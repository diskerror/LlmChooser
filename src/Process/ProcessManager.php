<?php

declare(strict_types=1);

namespace LLMchooser\Process;

use LLMchooser\Engine\EngineRepository;
use LLMchooser\Memory\Allocation;
use LLMchooser\Memory\MemoryService;
use LLMchooser\Preset\Preset;
use LLMchooser\Preset\PresetRepository;
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
		private readonly int $stopTimeoutSeconds = 30,
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

		$preset = $this->presets->load($presetId);
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

	public function restart(string $presetId, bool $force = false): Instance
	{
		$this->stop($presetId);
		return $this->start($presetId, $force);
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
					$i->message = 'process not found (LLMchooser or host restarted?)';
				}
			}
		});
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
