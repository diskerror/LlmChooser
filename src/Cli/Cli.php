<?php

declare(strict_types=1);

namespace LLMchooser\Cli;

use LLMchooser\App;
use LLMchooser\Memory\Allocation;
use LLMchooser\Process\AdmissionException;
use LLMchooser\Process\HealthChecker;
use LLMchooser\Process\Instance;
use LLMchooser\Process\ProcessControl;
use Throwable;

/**
 * The command line. run() maps argv to services and returns an exit code;
 * it never throws, so the wrapper script stays trivial.
 *
 * Exit codes: 0 ok, 1 failure, 2 usage error, 3 refused by admission.
 */
final class Cli
{
	private const USAGE = <<<'TXT'
	Usage: llmchooser <command> [args]

	  start   <preset-id> [--force] [--wait]   admission check, then spawn
	  stop    <preset-id>                      stop (SIGTERM, then SIGKILL)
	  restart <preset-id> [--force] [--wait]   stop then start
	  health  [<preset-id>] [--lines=N]        summary table, or details for one preset
	  list                                     list presets
	  models                                   list discovered model files
	  enable  <preset-id>                      allow this preset to be offered/started
	  disable <preset-id>                      park this preset (stop first if running)
	  set-startup    <preset-id>               start this preset at boot (systemd, needs root)
	  remove-startup <preset-id>               undo set-startup (needs root)
	TXT;

	public function __construct(
		private readonly App $app,
		private readonly Output $out = new Output(),
		private readonly ?StartupManager $startup = null,
	) {}

	/** @param list<string> $args  arguments AFTER the script name. Returns exit code. */
	public function run(array $args): int
	{
		$command = array_shift($args) ?? '';

		// Split options from positionals.
		$opts = [];
		$pos  = [];
		foreach ($args as $a) {
			if (str_starts_with($a, '--')) {
				$eq = strpos($a, '=');
				if ($eq === false) {
					$opts[substr($a, 2)] = true;
				} else {
					$opts[substr($a, 2, $eq - 2)] = substr($a, $eq + 1);
				}
			} else {
				$pos[] = $a;
			}
		}

		try {
			return $this->dispatch($command, $pos, $opts);
		}
		catch (UsageException $e) {
			$this->out->error($e->getMessage());
			return 2;
		}
		catch (AdmissionException $e) {
			$this->out->error($e->getMessage());
			$this->out->error('Use --force to start anyway.');
			return 3;
		}
		catch (Throwable $e) {
			$this->out->error('Error: ' . $e->getMessage());
			return 1;
		}
	}

	/** @param array<string,string|bool> $opts @param list<string> $pos */
	private function dispatch(string $command, array $pos, array $opts): int
	{
		$known = ['force', 'wait', 'foreground', 'lines', 'enabled'];
		foreach (array_keys($opts) as $name) {
			if (!in_array($name, $known, true)) {
				throw new UsageException("Unknown option: --{$name}");
			}
		}

		return match ($command) {
			'', 'help', '--help' => $this->usage(),
			'start'   => $this->start($this->needId($pos), $opts),
			'stop'    => $this->stop($this->needId($pos)),
			'restart' => $this->start($this->needId($pos), $opts, restarting: true),
			'health'  => $this->health($pos, $opts),
			'list'    => $this->listPresets(isset($opts['enabled'])),
			'models'  => $this->models(),
			'enable'  => $this->setEnabled($this->needId($pos), true),
			'disable' => $this->setEnabled($this->needId($pos), false),
			'set-startup'    => $this->startupCmd('set', $pos),
			'remove-startup' => $this->startupCmd('remove', $pos),
			default => $this->unknown($command),
		};
	}

	private function usage(): int
	{
		$this->out->line(self::USAGE);
		return 0;
	}

	private function unknown(string $command): int
	{
		$this->out->error("Unknown command: {$command}");
		$this->out->line(self::USAGE);
		return 2;
	}

	/** @param list<string> $pos */
	private function needId(array $pos): string
	{
		$id = $pos[0] ?? '';
		if ($id === '') {
			throw new UsageException('Missing preset id');
		}
		return $id;
	}

	/** @param array<string,string|bool> $opts */
	private function start(string $id, array $opts, bool $restarting = false): int
	{
		$this->app->processes->reconcile();

		$force      = isset($opts['force']);
		$foreground = isset($opts['foreground']);

		// restart() is stop+start; for the CLI the observable behaviour is the
		// same output path either way.
		$instance = $restarting
			? $this->app->processes->restart($id, $force)
			: $this->app->processes->start($id, $force);

		$this->out->line(sprintf('Started %s: pid %d, port %d (%s)', $id, $instance->pid, $instance->port, $instance->status));

		if ($foreground) {
			return $this->foregroundLoop($id);
		}

		if (isset($opts['wait'])) {
			$final = $this->app->processes->waitUntilReady($id);
			if ($final->status === Instance::READY) {
				$this->out->line("{$id}: ready");
				return 0;
			}
			$this->out->line("{$id}: error: {$final->message}");
			return 1;
		}

		return 0;
	}

	/**
	 * What the systemd unit runs: spawn, then stay attached so systemd owns
	 * the service's lifetime. The instance shares this unit's cgroup (setsid
	 * changes the session, not the cgroup), so `systemctl stop` still reaches
	 * the server itself via KillMode=control-group.
	 */
	private function foregroundLoop(string $id): int
	{
		if (function_exists('pcntl_async_signals')) {
			pcntl_async_signals(true);
			$handler = function (int $signo) use ($id): void {
				$this->app->processes->stop($id);
				exit(0);
			};
			pcntl_signal(SIGTERM, $handler);
			pcntl_signal(SIGINT, $handler);
		}

		while (true) {
			sleep(5);
			$all = $this->app->processes->refresh();
			$i   = $all[$id] ?? null;
			if ($i === null) {
				// Someone ran `llmchooser stop` — the service did its job.
				return 0;
			}
			if ($i->status === Instance::ERROR) {
				$this->out->error("{$id}: {$i->message}");
				// exit non-zero so Restart=on-failure brings it back.
				return 1;
			}
		}
	}

	private function stop(string $id): int
	{
		$this->app->processes->stop($id);
		$this->out->line("Stopped {$id}");
		return 0;
	}

	/**
	 * Park or un-park a preset. Disabling stops a running instance first —
	 * the CLI has no interactive prompt; it reports that it stopped it.
	 */
	private function setEnabled(string $id, bool $enabled): int
	{
		if (!$enabled) {
			$i = $this->app->processes->refresh()[$id] ?? null;
			if ($i !== null && ($i->status === Instance::LOADING || $i->status === Instance::READY)) {
				$this->app->processes->stop($id);
				$this->out->line("Stopped {$id} (was running)");
			}
		}
		$this->app->presets->setEnabled($id, $enabled);
		$this->out->line(($enabled ? 'Enabled ' : 'Disabled ') . $id);
		return 0;
	}

	/** @param list<string> $pos @param array<string,string|bool> $opts */
	private function health(array $pos, array $opts): int
	{
		$id = $pos[0] ?? '';
		if ($id === '') {
			return $this->healthSummary();
		}
		$lines = (int)(string)($opts['lines'] ?? 20);
		return $this->healthDetail($id, $lines > 0 ? $lines : 20);
	}

	private function healthSummary(): int
	{
		$this->app->processes->reconcile();
		$instances = $this->app->processes->refresh();
		$presets   = $this->app->presets->loadAll();

		$rows = [];
		$seen = [];
		foreach ($presets as $pid => $p) {
			$i    = $instances[$pid] ?? null;
			$seen[$pid] = true;
			$rows[] = [
				$pid,
				$i?->status ?? Instance::STOPPED,
				$i !== null && $i->pid > 0 ? (string)$i->pid : '-',
				(string)($i?->port ?: ($p->assigned_port ?? '-')),
				$i !== null && $i->started_at > 0 ? Format::duration(time() - $i->started_at) : '-',
				$i !== null ? Format::gb($this->allocTotal($i->allocation)) : '-',
				$i !== null && $i->pid > 0 ? Format::gb((new ProcessControl())->rssBytes($i->pid)) : '-',
			];
		}
		// An instance whose preset file is gone still gets a row — otherwise a
		// running server looks like it does not exist.
		foreach ($instances as $pid => $i) {
			if (isset($seen[$pid])) {
				continue;
			}
			$rows[] = [
				$pid, $i->status,
				$i->pid > 0 ? (string)$i->pid : '-',
				(string)($i->port ?: '-'),
				$i->started_at > 0 ? Format::duration(time() - $i->started_at) : '-',
				Format::gb($this->allocTotal($i->allocation)),
				$i->pid > 0 ? Format::gb((new ProcessControl())->rssBytes($i->pid)) : '-',
			];
		}

		$this->out->table(['PRESET', 'STATUS', 'PID', 'PORT', 'UPTIME', 'EST', 'RSS'], $rows);

		$broken = $this->reportBroken();
		return $broken === [] ? 0 : 1;
	}

	/** @param list<string> $pos */
	private function healthDetail(string $id, int $lines): int
	{
		$this->app->processes->reconcile();
		$instances = $this->app->processes->refresh();
		$i = $instances[$id] ?? $this->app->state->get($id);
		if ($i === null) {
			$this->out->error("No instance for preset {$id}");
			return 1;
		}

		// An instance row can predate this field (or be hand-written), so do
		// not assume an allocation is present.
		$alloc = $i->allocation !== [] ? Allocation::fromArray($i->allocation) : null;
		$break = [];
		foreach ($alloc?->breakdown ?? [] as $k => $v) {
			$break[] = $k . ' ' . Format::gb((int)$v);
		}

		$health = new HealthChecker();
		[$state, $body] = $health->check($i->host, $i->port, 1.0);

		$this->out->line("preset: {$i->preset_id}");
		$this->out->line("status: {$i->status}");
		if ($i->message !== '') {
			$this->out->line("message: {$i->message}");
		}
		$this->out->line("pid: {$i->pid}");
		$this->out->line("port: {$i->port}");
		$this->out->line('started: ' . ($i->started_at > 0 ? date('Y-m-d H:i:s', $i->started_at) : '-'));
		$this->out->line('uptime: ' . ($i->started_at > 0 ? Format::duration(time() - $i->started_at) : '-'));
		$this->out->line('estimate: ' . ($alloc === null ? '-' : Format::gb($alloc->totalBytes())) . ($break === [] ? '' : ' (' . implode(', ', $break) . ')'));
		$this->out->line('rss: ' . Format::gb((new ProcessControl())->rssBytes($i->pid)));
		$this->out->line("health: {$state}" . ($body !== '' ? ' ' . $body : ''));
		$this->out->line("command: {$i->command}");
		$this->out->line("log: {$i->log_file}");
		$this->out->line('');
		$this->out->line("--- last {$lines} log lines ---");
		$this->out->line(Format::tail($i->log_file, $lines));

		return 0;
	}

	private function listPresets(bool $onlyEnabled = false): int
	{
		$presets = $this->app->presets->loadAll();
		$rows = [];
		foreach ($presets as $p) {
			if ($onlyEnabled && !$p->enabled) {
				continue;
			}
			$rows[] = [
				$p->id,
				$p->name,
				$p->enabled ? 'yes' : 'no',
				$p->model_path !== '' ? basename($p->model_path) : '-',
				$p->assigned_port !== null ? (string)$p->assigned_port : '-',
			];
		}
		$this->out->table(['ID', 'NAME', 'ENABLED', 'MODEL', 'PORT'], $rows);

		// exit stays 0: the readable presets really were listed.
		$this->reportBroken();
		return 0;
	}

	private function models(): int
	{
		$rows = [];
		foreach ($this->app->modelScanner()->scan() as $e) {
			$note = $e->error ?? '';
			if ($note === '' && $e->isSharded()) {
				$note = count($e->shardPaths) . ' shards';
			}
			$rows[] = [
				$e->displayName,
				Format::gb($e->sizeBytes),
				$e->info?->architecture() ?? '-',
				(string)($e->info?->contextLength() ?? '-'),
				$note,
			];
		}
		$this->out->table(['MODEL', 'SIZE', 'ARCH', 'CTX', 'NOTE'], $rows);
		return 0;
	}

	/** @param list<string> $pos */
	private function startupCmd(string $what, array $pos): int
	{
		if ($this->startup === null) {
			$this->out->error('Startup management not available');
			return 1;
		}
		$id = $this->needId($pos);
		return $what === 'set' ? $this->startup->set($id) : $this->startup->remove($id);
	}

	/**
	 * Print quarantined config files to STDERR — a silently shorter list is
	 * how you end up hunting for a preset that "disappeared". Wording comes
	 * from BrokenFile::message(), the single definition for every surface.
	 *
	 * @return list<string> the broken file paths (for exit-code decisions)
	 */
	private function reportBroken(): array
	{
		$paths = [];
		foreach ([...$this->app->presets->broken(), ...$this->app->engines->broken()] as $f) {
			$this->out->error('ERROR: ' . $f->message());
			$paths[] = $f->path;
		}
		return $paths;
	}

	/** @param array<mixed> $allocation */
	private function allocTotal(array $allocation): ?int
	{
		if ($allocation === []) {
			return null;
		}
		return array_sum(Allocation::fromArray($allocation)->perDevice);
	}
}
