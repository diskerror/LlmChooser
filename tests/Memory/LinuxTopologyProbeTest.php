<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Memory;

use LlmChooser\Memory\Device;
use LlmChooser\Memory\LinuxTopologyProbe;
use LlmChooser\Memory\MemoryTopology;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LinuxTopologyProbeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/llmchooser-topology-' . uniqid('', true);
        mkdir($this->root . '/proc', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->rmrf($path . '/' . $e);
            }
        }
        rmdir($path);
    }

    private function meminfo(): void
    {
        // Example real-world values from a unified-memory APU host.
        file_put_contents($this->root . '/proc/meminfo', implode("\n", [
            'MemTotal:       131018920 kB',
            'MemFree:         1000000 kB',
            'MemAvailable:   30361004 kB',
            '',
        ]));
    }

    private function amdCard(string $card, int $total, int $used, ?int $gtt_used = null, ?int $gtt_total = null): void
    {
        $dir = $this->root . '/sys/class/drm/' . $card . '/device';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/mem_info_vram_total', (string)$total);
        file_put_contents($dir . '/mem_info_vram_used', (string)$used);
        if ($gtt_used !== null) {
            file_put_contents($dir . '/mem_info_gtt_used', (string)$gtt_used);
        }
        if ($gtt_total !== null) {
            file_put_contents($dir . '/mem_info_gtt_total', (string)$gtt_total);
        }
    }

    /** @param \Closure(array):?string $fn */
    private function probe(\Closure $fn): LinuxTopologyProbe
    {
        return new LinuxTopologyProbe($this->root . '/proc', $this->root . '/sys', $fn);
    }

    public function test_unified_memory_host(): void
    {
        $this->meminfo();
        $this->amdCard('card0', 536870912, 100000000);

        $topo = $this->probe(static fn(array $argv): ?string => null)->detect();

        $cpu = $topo->devices['cpu'];
        $this->assertSame(131018920 * 1024, $cpu->total_bytes);
        $this->assertSame(30361004 * 1024, $cpu->available_bytes);
        $this->assertSame(Device::KIND_RAM, $cpu->kind);

        $gpu = $topo->devices['gpu0'];
        $this->assertSame(536870912, $gpu->total_bytes);
        $this->assertSame(536870912 - 100000000, $gpu->available_bytes);
        $this->assertSame('cpu', $gpu->unified_with);

        $this->assertTrue($topo->isUnified());
        $this->assertSame('cpu', $topo->budgetId('gpu0'));
    }

    public function test_cpu_only(): void
    {
        $this->meminfo();

        $topo = $this->probe(static fn(array $argv): ?string => null)->detect();

        $this->assertSame([], $topo->gpuIds());
        $this->assertTrue($topo->isUnified());
        $this->assertSame('cpu', $topo->budgetId('cpu'));
    }

    public function test_two_discrete_amd(): void
    {
        $this->meminfo();
        $this->amdCard('card0', 24 * 1024 ** 3, 1024 ** 3);
        $this->amdCard('card1', 16 * 1024 ** 3, 0);

        $topo = $this->probe(static fn(array $argv): ?string => null)->detect();

        $this->assertSame(['gpu0', 'gpu1'], $topo->gpuIds());
        $this->assertSame(23 * 1024 ** 3, $topo->devices['gpu0']->available_bytes);
        $this->assertSame(16 * 1024 ** 3, $topo->devices['gpu1']->available_bytes);
        $this->assertNull($topo->devices['gpu0']->unified_with);
        $this->assertNull($topo->devices['gpu1']->unified_with);
        $this->assertFalse($topo->isUnified());
        $this->assertSame('gpu0', $topo->budgetId('gpu0'));
    }

    public function test_connector_entries_ignored(): void
    {
        $this->meminfo();
        $this->amdCard('card0', 24 * 1024 ** 3, 0);
        // A display connector symlinked to the same card must not count as a GPU.
        $this->amdCard('card0-DP-1', 24 * 1024 ** 3, 0);

        $topo = $this->probe(static fn(array $argv): ?string => null)->detect();

        $this->assertSame(['gpu0'], $topo->gpuIds());
    }

    public function test_gtt_held_reported_on_cpu_device(): void
    {
        $this->meminfo();
        $this->amdCard('card0', 536870912, 100000000, 96 * 1024 ** 3);
        // connector entry with a gtt file must not double-count
        $this->amdCard('card0-DP-1', 536870912, 100000000, 96 * 1024 ** 3);

        $topo = $this->probe(static fn(array $argv): ?string => null)->detect();

        $this->assertSame(96 * 1024 ** 3, $topo->devices['cpu']->driver_held_bytes);
        $this->assertSame(0, $topo->devices['gpu0']->driver_held_bytes);
        // round-trip through the array form
        $back = MemoryTopology::fromArray($topo->toArray());
        $this->assertSame(96 * 1024 ** 3, $back->devices['cpu']->driver_held_bytes);
    }

    public function test_unified_pool_is_capped_at_gtt_total(): void
    {
        $this->meminfo(); // MemTotal ~124.95 GiB, MemAvailable ~28.96 GiB
        $this->amdCard('card0', 536870912, 0, 92 * 1024 ** 3, 124 * 1024 ** 3);

        $topo = $this->probe(static fn(array $argv): ?string => null)->detect();
        $cpu  = $topo->devices['cpu'];

        $this->assertSame(124 * 1024 ** 3, $cpu->total_bytes);
        // headroom (32 GiB) exceeds MemAvailable (~29 GiB): the smaller wins
        $this->assertSame(30361004 * 1024, $cpu->available_bytes);
        $this->assertSame('apu', $topo->label('cpu'));
    }

    public function test_gtt_headroom_can_be_the_limit(): void
    {
        $this->meminfo();
        $this->amdCard('card0', 536870912, 0, 100 * 1024 ** 3, 124 * 1024 ** 3);

        $cpu = $this->probe(static fn(array $argv): ?string => null)->detect()->devices['cpu'];

        $this->assertSame(24 * 1024 ** 3, $cpu->available_bytes);
    }

    public function test_gtt_absent_is_zero(): void
    {
        $this->meminfo();
        $this->amdCard('card0', 536870912, 0);

        $topo = $this->probe(static fn(array $argv): ?string => null)->detect();

        $this->assertSame(0, $topo->devices['cpu']->driver_held_bytes);
    }

    public function test_nvidia_fallback(): void
    {
        $this->meminfo();

        $probe = $this->probe(static function (array $argv): ?string {
            return $argv[0] === 'nvidia-smi' ? "24576, 20000\n12288, 12000\n" : null;
        });
        $topo = $probe->detect();

        $this->assertSame(['gpu0', 'gpu1'], $topo->gpuIds());
        $this->assertSame(24576 * 1024 ** 2, $topo->devices['gpu0']->total_bytes);
        $this->assertSame(20000 * 1024 ** 2, $topo->devices['gpu0']->available_bytes);
        $this->assertFalse($topo->isUnified());
    }

    public function test_missing_meminfo_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->probe(static fn(array $argv): ?string => null)->detect();
    }
}
