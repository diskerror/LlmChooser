<?php

declare(strict_types=1);

namespace LlmChooser\Cli;

use LlmChooser\App;

/**
 * systemd unit files for "start this preset at boot".
 *
 * Everything system-level goes through CommandRunner and the unit directory
 * is a constructor argument, so the whole feature is testable without ever
 * touching systemctl, /etc or requiring root.
 */
final class StartupManager
{
	public function __construct(
		private readonly App $app,
		private readonly Output $out,
		private readonly CommandRunner $runner = new CommandRunner(),
		private readonly string $unit_dir = '/etc/systemd/system',
		private readonly string $service_user = 'llama',
		private readonly string $php_binary = PHP_BINARY,
	) {}

	public function unitName(string $preset_id): string
	{
		return "llmchooser-{$preset_id}.service";
	}

	public function unitText(string $preset_id): string
	{
		return <<<TXT
		[Unit]
		Description=LlmChooser preset {$preset_id}
		After=network-online.target
		Wants=network-online.target

		[Service]
		Type=simple
		User={$this->service_user}
		Group={$this->service_user}
		WorkingDirectory={$this->app->root}
		ExecStart={$this->php_binary} {$this->app->root}/bin/llmchooser start {$preset_id} --foreground
		Restart=on-failure
		RestartSec=10
		TimeoutStopSec=60
		LimitMEMLOCK=infinity

		[Install]
		WantedBy=multi-user.target

		TXT;
	}

	public function set(string $preset_id): int
	{
		// Loading first: a typo'd preset should not install a unit that fails
		// at boot. It also guarantees the id is a usable slug.
		$preset = $this->app->presets->load($preset_id);
		if (!$preset->enabled) {
			$this->out->error("Preset '{$preset_id}' is disabled. Enable it first.");
			return 1;
		}

		$rc = $this->preflight($preset_id);
		if ($rc !== 0) {
			return $rc;
		}

		$name = $this->unitName($preset_id);
		if (file_put_contents($this->unit_dir . '/' . $name, $this->unitText($preset_id)) === false) {
			$this->out->error("Cannot write {$this->unit_dir}/{$name}");
			return 1;
		}
		// Unit files are public config (the CLI runs with umask 027).
		@chmod($this->unit_dir . '/' . $name, 0644);

		foreach ([['systemctl', 'daemon-reload'], ['systemctl', 'enable', $name]] as $argv) {
			[$rc, $output] = $this->runner->run($argv);
			if ($rc !== 0) {
				$this->out->error(trim($output) !== '' ? trim($output) : implode(' ', $argv) . ' failed');
				return 1;
			}
		}

		$this->out->line("Installed and enabled {$name}. It will start at boot; start it now with: systemctl start {$name}");
		return 0;
	}

	public function remove(string $preset_id): int
	{
		$rc = $this->preflight($preset_id);
		if ($rc !== 0) {
			return $rc;
		}

		$name = $this->unitName($preset_id);
		if (!is_file($this->unit_dir . '/' . $name)) {
			$this->out->line("Not installed: {$name}");
			return 0;
		}

		// disable --now also stops it; its exit code is ignored because the
		// unit may be installed-but-never-started, which some systemd versions
		// report as an error even though the removal is what we asked for.
		$this->runner->run(['systemctl', 'disable', '--now', $name]);
		unlink($this->unit_dir . '/' . $name);
		$this->runner->run(['systemctl', 'daemon-reload']);

		$this->out->line("Removed {$name}");
		return 0;
	}

	/** @return int 0 when the operation may proceed */
	private function preflight(string $preset_id): int
	{
		if (!$this->runner->exists('systemctl')) {
			$this->out->error('systemd not found: set-startup is only supported on Linux with systemd');
			return 1;
		}
		if (!$this->runner->isRoot()) {
			$this->out->error(
				"Writing to {$this->unit_dir} needs root. Re-run with: sudo {$this->php_binary} {$this->app->root}/bin/llmchooser set-startup {$preset_id}"
			);
			return 1;
		}
		return 0;
	}
}
