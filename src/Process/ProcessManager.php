<?php

declare(strict_types=1);

namespace LlmChooser\Process;

use LlmChooser\Engine\EngineRepository;
use LlmChooser\Memory\Allocation;
use LlmChooser\Memory\MemoryService;
use LlmChooser\Preset\Preset;
use LlmChooser\Preset\PresetRepository;
use RuntimeException;

/**
 * Starts, watches and stops llama-server instances.
 *
 * The rules that shape this class:
 * - A PID is never trusted on its own. PIDs get reused, so isOurs() also
 *   requires the recorded --port to appear in the process command line
 *   before anything is signalled.
 * - Nothing blocks the caller except waitUntilReady(), which exists so the
 *   CLI can wait while the web UI polls refresh().
 * - Stopped instances are removed from state; the log file stays on disk,
 *   which is where the reason for a failure lives.
 */
final class ProcessManager
{
	public function __construct(
		private readonly PresetRepository $presets,
		private readonly EngineRepository $engines,
		private readonly RuntimeState $state,
		private readonly MemoryService $memory,
		private readonly string $logDir,
		private readonly CommandBuilder $builder = new CommandBuilder(),
		private readonly ProcessControl $proc = new ProcessControl(),
		private readonly HealthChecker $health = new HealthChecker(),
		private readonly int $stopTimeoutSeconds = 15,
		private readonly int $releaseTimeoutSeconds = 15,
	) {}

	public function start(string $presetId, bool $force = false): Instance
	{
		$this->reconcile();

		// Already up? Starting twice is a no-op, not an error. An instance in
		// error state does not count: it gets replaced.
		$existing = $this->state->get($presetId);
		if ($existing !== null && in_array($existing->status, [Instance::LOADING, Instance::READY], true)) {
			return $existing;
		}

		// An instance in error may still have a live process (e.g. "not ready
		// after 600s" only gives up waiting; it does not kill the server).
		// Replacing its state entry would orphan that process, so stop it.
		if ($existing !== null) {
			$this->stop($presetId);
		}

		$preset = $this->presets->load($presetId);
		if (!$preset->enabled) {
			throw new RuntimeException("Preset '{$presetId}' is disabled. Enable it first.");
		}
		$engine = $this->engines->load($preset->engine_id);

		if ($force) {
			$allocation = $this->memory->estimate($preset);
		} else {
			$running = [];
			foreach ($this->state->all() as $i) {
				if (in_array($i->status, [Instance::LOADING, Instance::READY], true) && $i->allocation !== []) {
					$running[] = Allocation::fromArray($i->allocation);
				}
			}
			$result = $this->memory->admit($preset, $running);
			if (!$result->fits) {
				throw new AdmissionException($result);
			}
			// admit() refreshed the topology; estimate once more for storage.
			$allocation = $this->memory->estimate($preset);
		}

		$port = (new PortAllocator($this->presets))->portFor($preset, $engine);
		if (!PortAllocator::isFree($engine->host, $port)) {
			throw new RuntimeException("Port {$port} is already in use");
		}

		$cmd = $this->builder->build($preset, $engine, $port);

		if (!is_dir($this->logDir) && !mkdir($this->logDir, 0775, true) && !is_dir($this->logDir)) {
			throw new RuntimeException("Cannot create log directory: {$this->logDir}");
		}
		$logFile = $this->logDir . '/' . $presetId . '-' . date('Ymd-His') . '.log';
		file_put_contents($logFile, '# ' . date('c') . ' ' . $cmd->toString() . "\n");

		$argv = $cmd->argv;
		if (is_executable('/usr/bin/setsid')) {
			// Own session: the instance survives the CLI or web request that
			// started it. Not forked, so the PID we record is the server's.
			array_unshift($argv, '/usr/bin/setsid');
		}
		$env  = array_merge(getenv(), $cmd->env);
		$proc = proc_open($argv, [
			0 => ['file', '/dev/null', 'r'],
			1 => ['file', $logFile, 'a'],
			2 => ['file', $logFile, 'a'],
		], $pipes, null, $env);

		if ($proc === false) {
			throw new RuntimeException('Failed to start process');
		}
		$pid = proc_get_status($proc)['pid'];
		// Deliberately no proc_close(): it would block until the server exits.

		$this->state->update(static function ($c) use ($presetId, $pid, $engine, $port, $logFile, $cmd, $allocation): void {
			$c[$presetId] = new Instance([
				'preset_id'  => $presetId,
				'pid'        => $pid,
				'host'       => $engine->host,
				'port'       => $port,
				'started_at' => time(),
				'status'     => Instance::LOADING,
				'log_file'   => $logFile,
				'command'    => $cmd->toString(),
				'allocation' => $allocation->toArray(),
				'last_used'  => time(),
			]);
		});

		return $this->state->get($presetId);
	}

	/**
	 * Start a preset, making room if needed: when admission refuses, stop
	 * the least-recently-used OTHER instances one at a time until it fits.
	 * Only if nothing is left to stop does the AdmissionException propagate.
	 *
	 * @return list<string> ids that were stopped to make room
	 */
	public function startEvicting(string $presetId, ?Instance &$started = null): array
	{
		$evicted = [];
		while (true) {
			try {
				// After an eviction, allow driver-release time before naming
				// the next victim: stale "available" readings would otherwise
				// evict more instances than the freed memory actually needs.
				$started = $evicted === []
					? $this->start($presetId)
					: $this->startAllowingRelease($presetId);
				return $evicted;
			} catch (AdmissionException $e) {
				$victims = [];
				foreach ($this->state->all() as $i) {
					if ($i->preset_id !== $presetId && in_array($i->status, [Instance::LOADING, Instance::READY], true)) {
						$victims[] = $i;
					}
				}
				if ($victims === []) {
					throw $e;
				}
				usort($victims, static fn($a, $b): int => $a->last_used <=> $b->last_used);
				$this->stop($victims[0]->preset_id);
				$evicted[] = $victims[0]->preset_id;
			}
		}
	}

	/** Update statuses without waiting for anything. */
	public function refresh(): InstanceCollection
	{
		return $this->state->update(function ($c): void {
			foreach ($c as $i) {
				if (!in_array($i->status, [Instance::LOADING, Instance::READY], true)) {
					continue;
				}
				if (!$this->isOurs($i)) {
					$i->status  = Instance::ERROR;
					$i->message = 'process exited' . $this->tailOfLog($i);
					continue;
				}
				[$state] = $this->health->check($i->host, $i->port, 1.0);
				if ($state === HealthChecker::READY) {
					$i->status = Instance::READY;
				} elseif ($state === HealthChecker::LOADING) {
					$i->status = Instance::LOADING;
				}
				// unreachable: leave as is, it may still be starting up.
			}
		});
	}

	/** Block until the instance is ready, errors, or the timeout passes. */
	public function waitUntilReady(string $presetId, int $timeoutSeconds = 600): Instance
	{
		$deadline = microtime(true) + $timeoutSeconds;
		while (true) {
			$this->refresh();
			$i = $this->state->get($presetId);
			if ($i === null) {
				throw new RuntimeException("No instance for preset {$presetId}");
			}
			if (in_array($i->status, [Instance::READY, Instance::ERROR], true)) {
				return $i;
			}
			if (microtime(true) >= $deadline) {
				$this->state->update(static function ($c) use ($presetId, $timeoutSeconds): void {
					$i = $c[$presetId];
					$i->status  = Instance::ERROR;
					$i->message = "not ready after {$timeoutSeconds}s";
				});
				return $this->state->get($presetId);
			}
			usleep(500000);
		}
	}

	public function stop(string $presetId): void
	{
		$i = $this->state->get($presetId);
		if ($i === null) {
			return;
		}

		if ($this->isOurs($i)) {
			$this->proc->signal($i->pid, 'TERM');
			if ($this->waitWhileAlive($i->pid, $this->stopTimeoutSeconds)) {
				$this->proc->signal($i->pid, 'KILL');
				$this->waitWhileAlive($i->pid, 2);
			}
		}

		$this->state->update(static function ($c) use ($presetId): void {
			unset($c[$presetId]);
		});
	}

	/**
	 * Stop, then start. A killed llama-server releases its pinned GTT/VRAM
	 * in the driver only seconds AFTER the process is reaped, so an immediate
	 * admission check would measure the dying instance's memory as still
	 * held and refuse a restart that fits. Retry admission briefly before
	 * giving up; a genuine over-commit still fails, just later.
	 */
	public function restart(string $presetId, bool $force = false): Instance
	{
		$this->stop($presetId);
		return $this->startAllowingRelease($presetId, $force);
	}

	/**
	 * start() with a bounded grace period for driver memory release: on
	 * admission refusal, re-check every second up to releaseTimeoutSeconds.
	 */
	private function startAllowingRelease(string $presetId, bool $force = false): Instance
	{
		$deadline = microtime(true) + $this->releaseTimeoutSeconds;
		while (true) {
			try {
				return $this->start($presetId, $force);
			} catch (AdmissionException $e) {
				if (microtime(true) >= $deadline) {
					throw $e;
				}
				sleep(1);
			}
		}
	}

	/**
	 * Reconcile state against real processes. Called when the web app or CLI
	 * comes up, since either the manager or the host may have restarted.
	 *
	 * Instances already in error are kept so the message can be read; they go
	 * away on stop() or are replaced by start().
	 */
	public function reconcile(): InstanceCollection
	{
		return $this->state->update(function ($c): void {
			foreach ($c as $i) {
				if (!in_array($i->status, [Instance::LOADING, Instance::READY], true)) {
					continue;
				}
				if (!$this->isOurs($i)) {
					$i->status  = Instance::ERROR;
					$i->message = 'process not found (LlmChooser or host restarted?)';
				}
			}
		});
	}

	/**
	 * llama-server processes started from one of our engine binaries (or a
	 * preset's binary override) that no recorded instance accounts for.
	 * These are invisible to the dashboard, yet still hold their model in
	 * memory. Matching on OUR binaries, not on the name "llama-server",
	 * means an unrelated server someone else runs is never listed.
	 *
	 * @return list<array{pid:int,rss:int,port:string,model:string,args:string}>
	 */
	public function orphans(): array
	{
		$binaries = [];
		foreach ($this->engines->loadAll() as $e) {
			if ($e->binary_path_default !== '') {
				$binaries[$e->binary_path_default] = true;
			}
		}
		foreach ($this->presets->loadAll() as $p) {
			if ($p->binary_path !== '') {
				$binaries[$p->binary_path] = true;
			}
		}

		$managed = [];
		foreach ($this->state->all() as $i) {
			$managed[$i->pid] = true;
		}

		$out = [];
		foreach ($this->proc->listProcesses() as $p) {
			if (isset($managed[$p['pid']]) || $p['pid'] === getmypid()) {
				continue;
			}
			// argv[0], or argv[1] when the binary is a script run by an interpreter.
			$tokens = preg_split('/\s+/', $p['args'], 3) ?: [];
			if (!isset($binaries[$tokens[0] ?? '']) && !isset($binaries[$tokens[1] ?? ''])) {
				continue;
			}
			$out[] = $p + [
				'port'  => preg_match('/--port\s+(\d+)/', $p['args'], $m) ? $m[1] : '?',
				'model' => preg_match('/--model\s+(\S+)/', $p['args'], $m) ? basename($m[1]) : '?',
			];
		}
		return $out;
	}

	/**
	 * Stop untracked servers: all of them, or just $pid. The list is always
	 * recomputed here, so a PID from a stale page can never signal a process
	 * that has since become something else.
	 *
	 * @return int how many were signalled
	 */
	public function killOrphans(?int $pid = null): int
	{
		$n = 0;
		foreach ($this->orphans() as $o) {
			if ($pid !== null && $o['pid'] !== $pid) {
				continue;
			}
			$this->proc->signal($o['pid'], 'TERM');
			if ($this->waitWhileAlive($o['pid'], $this->stopTimeoutSeconds)) {
				$this->proc->signal($o['pid'], 'KILL');
			}
			$n++;
		}
		return $n;
	}

	/** @return InstanceCollection instances with status loading or ready */
	public function running(): InstanceCollection
	{
		$all = $this->refresh();
		$out = new InstanceCollection();
		foreach ($all as $id => $i) {
			if (in_array($i->status, [Instance::LOADING, Instance::READY], true)) {
				$out[$id] = $i;
			}
		}
		return $out;
	}

	/**
	 * Does this PID belong to the instance we recorded?
	 *
	 * The --port check is the part that matters: without it, a recycled PID
	 * that happens to belong to something else would be reported alive and
	 * then signalled.
	 */
	private function isOurs(Instance $i): bool
	{
		if (!$this->proc->isAlive($i->pid)) {
			return false;
		}
		$cmdline = $this->proc->commandLine($i->pid);
		return $cmdline !== null && str_contains($cmdline, '--port ' . $i->port);
	}

	/** @return bool true if still alive when the wait ran out */
	private function waitWhileAlive(int $pid, int $seconds): bool
	{
		$deadline = microtime(true) + $seconds;
		while (microtime(true) < $deadline) {
			if (!$this->proc->isAlive($pid)) {
				return false;
			}
			usleep(200000);
		}
		return $this->proc->isAlive($pid);
	}

	/** Last few log lines, appended to an error message — that is where the
	 *  reason for a failed start actually lives. */
	private function tailOfLog(Instance $i): string
	{
		if ($i->log_file === '' || !is_file($i->log_file)) {
			return '';
		}
		$lines = file($i->log_file, FILE_IGNORE_NEW_LINES);
		if ($lines === false || $lines === []) {
			return '';
		}
		return ': ' . implode(' | ', array_slice($lines, -5));
	}
}
