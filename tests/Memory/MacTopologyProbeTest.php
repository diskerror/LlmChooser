<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Memory;

use LlmChooser\Memory\Device;
use LlmChooser\Memory\MacTopologyProbe;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MacTopologyProbeTest extends TestCase
{
	private const GIB = 1024 ** 3;

	private const VM_STAT = <<<'TXT'
		Mach Virtual Memory Statistics: (page size of 16384 bytes)
		Pages free:                                     65536.
		Pages inactive:                                131072.
		Pages speculative:                              65536.
		Pages wired down:                             1005796.
		Pages purgeable:                                65536.
		File-backed pages:                             173586.
		TXT;

	/** @param array<string,?string> $outputs command basename + first arg => stdout */
	private static function probe(array $outputs): MacTopologyProbe
	{
		return new MacTopologyProbe(static function (array $argv) use ($outputs): ?string {
			$key = basename($argv[0]) . (isset($argv[2]) && $argv[0] === '/usr/sbin/sysctl' ? ' ' . $argv[2] : '');
			return $outputs[$key] ?? null;
		});
	}

	private static function outputs(string $wired_limit_mb = '0', ?string $ioreg = '"In use system memory"=4294967296'): array
	{
		return [
			'sysctl hw.memsize'            => (string)(32 * self::GIB) . "\n",
			'sysctl iogpu.wired_limit_mb'  => $wired_limit_mb . "\n",
			'vm_stat'                      => self::VM_STAT,
			'ioreg'                        => $ioreg,
		];
	}

	public function test_default_gpu_ceiling_is_three_quarters_of_ram(): void
	{
		[$cpu, $gpu] = array_values(self::probe(self::outputs())->detect()->devices);
		$this->assertSame(['cpu', Device::KIND_RAM, 24 * self::GIB], [$cpu->id, $cpu->kind, $cpu->total_bytes]);
		// free + inactive + speculative + purgeable = 327680 pages × 16 KiB = 5 GiB
		$this->assertSame(5 * self::GIB, $cpu->available_bytes);
		$this->assertSame(4 * self::GIB, $cpu->driver_held_bytes);
		$this->assertSame(['gpu0', 'cpu', 24 * self::GIB, 20 * self::GIB], [$gpu->id, $gpu->unified_with, $gpu->total_bytes, $gpu->available_bytes]);
	}

	public function test_wired_limit_sysctl_overrides_default(): void
	{
		[$cpu] = array_values(self::probe(self::outputs('28672'))->detect()->devices);
		$this->assertSame(28 * self::GIB, $cpu->total_bytes);
	}

	public function test_gpu_headroom_caps_available(): void
	{
		[$cpu, $gpu] = array_values(self::probe(self::outputs('0', '"In use system memory"=' . (22 * self::GIB)))->detect()->devices);
		$this->assertSame(2 * self::GIB, $cpu->available_bytes);
		$this->assertSame(2 * self::GIB, $gpu->available_bytes);
	}

	public function test_missing_ioreg_and_vm_stat_degrade_to_zero_held_and_available(): void
	{
		$o = self::outputs('0', null);
		$o['vm_stat'] = null;
		[$cpu] = array_values(self::probe($o)->detect()->devices);
		$this->assertSame([0, 0], [$cpu->available_bytes, $cpu->driver_held_bytes]);
	}

	public function test_no_memsize_throws(): void
	{
		$this->expectException(RuntimeException::class);
		self::probe([])->detect();
	}
}
