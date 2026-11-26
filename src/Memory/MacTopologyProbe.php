<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

use Closure;
use RuntimeException;

/**
 * Memory detection on Apple Silicon macOS (unified memory, Metal).
 *
 * - RAM total: `sysctl -n hw.memsize`.
 * - RAM available: vm_stat free + inactive + speculative + purgeable pages
 *   (what macOS can hand out without paging anything still in use).
 * - GPU ceiling: Metal only lets the GPU wire part of RAM. The limit is
 *   `iogpu.wired_limit_mb` when set (non-zero); otherwise the macOS default,
 *   approximated as DEFAULT_GPU_SHARE of RAM. Metal's own figure
 *   (recommendedMaxWorkingSetSize) is a little higher — 24.96 GiB on a
 *   32 GiB Mac Studio — but needs a Swift/ObjC call; the lower share errs on
 *   the safe side.
 * - GPU in use: IOAccelerator "In use system memory" (ioreg).
 *
 * Like an APU on Linux, the GPU is reported as unified with 'cpu', and the
 * 'cpu' budget is min(RAM, GPU ceiling) / min(RAM available, ceiling - in use).
 * With mmap, llama.cpp's Metal backend wraps the mapped model file in GPU
 * buffers without copying, so model weights show up as file-backed memory,
 * not as app memory — the GPU in-use figure is what accounts for them.
 */
final class MacTopologyProbe implements TopologyProbe
{
	public const DEFAULT_GPU_SHARE = 0.75;

	/** @var Closure(array):?string */
	private Closure $runCommand;

	/** @param ?Closure(array):?string $runCommand runs argv and returns stdout, or null on failure */
	public function __construct(?Closure $runCommand = null)
	{
		$this->runCommand = $runCommand ?? LinuxTopologyProbe::defaultRunner();
	}

	public function detect(): MemoryTopology
	{
		$total = (int)trim((string)($this->runCommand)(['/usr/sbin/sysctl', '-n', 'hw.memsize']));
		if ($total <= 0) {
			throw new RuntimeException('Cannot read hw.memsize');
		}
		$available = $this->vmAvailable() ?? 0;

		$limit_mb = (int)trim((string)($this->runCommand)(['/usr/sbin/sysctl', '-n', 'iogpu.wired_limit_mb']));
		$limit    = $limit_mb > 0 ? min($total, $limit_mb * 1024 ** 2) : (int)($total * self::DEFAULT_GPU_SHARE);
		$held     = $this->gpuInUse();

		$cpu = new Device('cpu', Device::KIND_RAM, min($total, $limit), min($available, max(0, $limit - $held)), null, $held);
		$gpu = new Device('gpu0', Device::KIND_VRAM, $limit, max(0, $limit - $held), 'cpu');
		return new MemoryTopology([$cpu, $gpu]);
	}

	/** Bytes macOS can hand out from vm_stat, or null if it can't be read. */
	private function vmAvailable(): ?int
	{
		$out = ($this->runCommand)(['/usr/bin/vm_stat']);
		if ($out === null || !preg_match('/page size of (\d+) bytes/', $out, $m)) {
			return null;
		}
		$page  = (int)$m[1];
		$pages = 0;
		foreach (['Pages free', 'Pages inactive', 'Pages speculative', 'Pages purgeable'] as $field) {
			if (preg_match('/^' . $field . ':\s+(\d+)\./m', $out, $f)) {
				$pages += (int)$f[1];
			}
		}
		return $pages * $page;
	}

	/** GPU-held system memory (IOAccelerator "In use system memory"), 0 if unknown. */
	private function gpuInUse(): int
	{
		$out = ($this->runCommand)(['/usr/sbin/ioreg', '-r', '-c', 'IOAccelerator', '-d', '1', '-w0']);
		return $out !== null && preg_match('/"In use system memory"=(\d+)/', $out, $m) ? (int)$m[1] : 0;
	}
}
