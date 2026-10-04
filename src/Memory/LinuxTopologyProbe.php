<?php

declare(strict_types=1);

namespace LLMchooser\Memory;

use Closure;
use RuntimeException;

/**
 * Detects memory devices from /proc and /sys, with an nvidia-smi fallback.
 *
 * Both roots are constructor arguments and command execution is injected, so
 * the whole thing is testable against fake directories without touching the
 * host. Only the Linux path is implemented; macOS/Windows detection is not a
 * goal (LLMchooser runs its instances on the Linux box).
 */
final class LinuxTopologyProbe implements TopologyProbe
{
	/** @var Closure(array):?string */
	private Closure $runCommand;

	/**
	 * @param ?Closure(array):?string $runCommand runs argv and returns stdout, or null on failure
	 * @param int $unifiedThresholdBytes a GPU with a total below this is treated as sharing system RAM
	 */
	public function __construct(
		private readonly string $procRoot = '/proc',
		private readonly string $sysRoot = '/sys',
		?Closure $runCommand = null,
		private readonly int $unifiedThresholdBytes = 2 * 1024 ** 3,
	) {
		$this->runCommand = $runCommand ?? self::defaultRunner();
	}

	public function detect(): MemoryTopology
	{
		$cpu  = $this->systemRam();
		$gpus = $this->amdGpus();
		if ($gpus === []) {
			$gpus = $this->nvidiaGpus();
		}

		return new MemoryTopology([$cpu, ...$gpus]);
	}

	/**
	 * Run argv with no shell. Returns stdout on exit code 0, null otherwise —
	 * including when the program does not exist. Never throws.
	 *
	 * @return Closure(array):?string
	 */
	private static function defaultRunner(): Closure
	{
		return static function (array $argv): ?string {
			$descriptors = [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
			$proc = @proc_open($argv, $descriptors, $pipes);
			if (!is_resource($proc)) {
				return null;
			}
			$out = stream_get_contents($pipes[1]);
			fclose($pipes[1]);
			$code = proc_close($proc);

			return ($code === 0 && is_string($out)) ? $out : null;
		};
	}

	private function systemRam(): Device
	{
		$file = $this->procRoot . '/meminfo';
		if (!is_file($file)) {
			throw new RuntimeException("Cannot read memory info: {$file}");
		}

		$total = null;
		$available = null;
		foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
			if (preg_match('/^MemTotal:\s+(\d+)\s*kB$/', $line, $m)) {
				$total = (int)$m[1] * 1024;
			} elseif (preg_match('/^MemAvailable:\s+(\d+)\s*kB$/', $line, $m)) {
				$available = (int)$m[1] * 1024;
			}
		}

		if ($total === null) {
			throw new RuntimeException("No MemTotal in {$file}");
		}

		// MemAvailable is newer than MemTotal; fall back to MemFree, and only
		// to zero if the kernel gave us nothing usable.
		if ($available === null) {
			$available = $this->memField('MemFree') ?? 0;
		}

		return new Device('cpu', Device::KIND_RAM, $total, $available);
	}

	private function memField(string $name): ?int
	{
		foreach (file($this->procRoot . '/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
			if (preg_match('/^' . $name . ':\s+(\d+)\s*kB$/', $line, $m)) {
				return (int)$m[1] * 1024;
			}
		}
		return null;
	}

	/**
	 * AMD (amdgpu) exposes VRAM through drm sysfs. Connector entries such as
	 * card0-DP-1 symlink back to the same card, so they are skipped — counting
	 * them would invent a GPU per display output.
	 *
	 * @return list<Device>
	 */
	private function amdGpus(): array
	{
		$files = glob($this->sysRoot . '/class/drm/card*/device/mem_info_vram_total') ?: [];

		$cards = [];
		foreach ($files as $file) {
			$card = basename(dirname($file, 2));
			if (preg_match('/^card\d+-/', $card)) {
				continue;
			}
			$cards[$card] = dirname($file);
		}
		uksort($cards, 'strnatcasecmp');

		$out = [];
		foreach ($cards as $dir) {
			$total = (int)@file_get_contents($dir . '/mem_info_vram_total');
			if ($total <= 0) {
				continue;
			}
			$used = (int)@file_get_contents($dir . '/mem_info_vram_used');
			$out[] = $this->gpu(count($out), $total, max(0, $total - $used));
		}

		return $out;
	}

	/**
	 * @return list<Device>
	 */
	private function nvidiaGpus(): array
	{
		$out = ($this->runCommand)([
			'nvidia-smi',
			'--query-gpu=memory.total,memory.free',
			'--format=csv,noheader,nounits',
		]);
		if ($out === null) {
			return [];
		}

		$gpus = [];
		foreach (explode("\n", $out) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$parts = array_map('trim', explode(',', $line));
			if (count($parts) < 2 || !is_numeric($parts[0])) {
				continue;
			}
			// nvidia-smi reports MiB.
			$total = (int)$parts[0] * 1024 ** 2;
			$free  = (int)$parts[1] * 1024 ** 2;
			if ($total <= 0) {
				continue;
			}
			$gpus[] = $this->gpu(count($gpus), $total, $free);
		}

		return $gpus;
	}

	private function gpu(int $index, int $total, int $available): Device
	{
		$unified = $total < $this->unifiedThresholdBytes ? 'cpu' : null;
		return new Device('gpu' . $index, Device::KIND_VRAM, $total, $available, $unified);
	}
}
