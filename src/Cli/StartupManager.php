<?php

declare(strict_types=1);

namespace LLMchooser\Cli;

use LLMchooser\App;

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
		private readonly string $unitDir = '/etc/systemd/system',
		private readonly string $serviceUser = 'llama',
		private readonly string $phpBinary = PHP_BINARY,
	) {}

	public function unitName(string $presetId): string
	{
		return "llmchooser-{$presetId}.service";
	}

	public function unitText(string $presetId): string
	{
		return <<<TXT
		[Unit]
		Description=LLMchooser preset {$presetId}
		After=network-online.target
		Wants=network-online.target

		[Service]
		Type=simple
		User={$this->serviceUser}
		Group={$this->serviceUser}
		WorkingDirectory={$this->app->root}
		ExecStart={$this->phpBinary} {$this->app->root}/bin/llmchooser start {$presetId} --foreground
		Restart=on-failure
		RestartSec=10
		TimeoutStopSec=60
		LimitMEMLOCK=infinity

		[Install]
		WantedBy=multi-user.target

		TXT;
	}

	public function set(string $presetId): int
	{
		// Loading first: a typo'd preset should not install a unit that fails
		// at boot. It also guarantees the id is a usable slug.
		$preset = $this->app->presets->load($presetId);
		if (!$preset->enabled) {
			$this->out->error("Preset '{$presetId}' is disabled. Enable it first.");
			return 1;
		}

		$rc = $this->preflight($presetId);
		if ($rc !== 0) {
			return $rc;
		}

		$name = $this->unitName($presetId);
		if (file_put_contents($this->unitDir . '/' . $name, $this->unitText($presetId)) === false) {
			$this->out->error("Cannot write {$this->unitDir}/{$name}");
			return 1;
		}

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

	public function remove(string $presetId): int
	{
		$rc = $this->preflight($presetId);
		if ($rc !== 0) {
			return $rc;
		}

		$name = $this->unitName($presetId);
		if (!is_file($this->unitDir . '/' . $name)) {
			$this->out->line("Not installed: {$name}");
			return 0;
		}

		// disable --now also stops it; its exit code is ignored because the
		// unit may be installed-but-never-started, which some systemd versions
		// report as an error even though the removal is what we asked for.
		$this->runner->run(['systemctl', 'disable', '--now', $name]);
		unlink($this->unitDir . '/' . $name);
		$this->runner->run(['systemctl', 'daemon-reload']);

		$this->out->line("Removed {$name}");
		return 0;
	}

	/** @return int 0 when the operation may proceed */
	private function preflight(string $presetId): int
	{
		if (!$this->runner->exists('systemctl')) {
			$this->out->error('systemd not found: set-startup is only supported on Linux with systemd');
			return 1;
		}
		if (!$this->runner->isRoot()) {
			$this->out->error(
				"Writing to {$this->unitDir} needs root. Re-run with: sudo {$this->phpBinary} {$this->app->root}/bin/llmchooser set-startup {$presetId}"
			);
			return 1;
		}
		return 0;
	}
}
