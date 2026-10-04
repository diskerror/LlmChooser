<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Memory;

use LLMchooser\Memory\Device;
use LLMchooser\Memory\LinuxTopologyProbe;
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
        // beelink's real values.
        file_put_contents($this->root . '/proc/meminfo', implode("\n", [
            'MemTotal:       131018920 kB',
            'MemFree:         1000000 kB',
            'MemAvailable:   30361004 kB',
            '',
        ]));
    }

    private function amdCard(string $card, int $total, int $used): void
    {
        $dir = $this->root . '/sys/class/drm/' . $card . '/device';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/mem_info_vram_total', (string)$total);
        file_put_contents($dir . '/mem_info_vram_used', (string)$used);
    }

    /** @param \Closure(array):?string $fn */
    private function probe(\Closure $fn): LinuxTopologyProbe
    {
        return new LinuxTopologyProbe($this->root . '/proc', $this->root . '/sys', $fn);
    }

    public function test_beelink_unified(): void
    {
        $this->meminfo();
        $this->amdCard('card0', 536870912, 100000000);

        $topo = $this->probe(static fn(array $argv): ?string => null)->detect();

        $cpu = $topo->devices['cpu'];
        $this->assertSame(131018920 * 1024, $cpu->totalBytes);
        $this->assertSame(30361004 * 1024, $cpu->availableBytes);
        $this->assertSame(Device::KIND_RAM, $cpu->kind);

        $gpu = $topo->devices['gpu0'];
        $this->assertSame(536870912, $gpu->totalBytes);
        $this->assertSame(536870912 - 100000000, $gpu->availableBytes);
        $this->assertSame('cpu', $gpu->unifiedWith);

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
        $this->assertSame(23 * 1024 ** 3, $topo->devices['gpu0']->availableBytes);
        $this->assertSame(16 * 1024 ** 3, $topo->devices['gpu1']->availableBytes);
        $this->assertNull($topo->devices['gpu0']->unifiedWith);
        $this->assertNull($topo->devices['gpu1']->unifiedWith);
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

    public function test_nvidia_fallback(): void
    {
        $this->meminfo();

        $probe = $this->probe(static function (array $argv): ?string {
            return $argv[0] === 'nvidia-smi' ? "24576, 20000\n12288, 12000\n" : null;
        });
        $topo = $probe->detect();

        $this->assertSame(['gpu0', 'gpu1'], $topo->gpuIds());
        $this->assertSame(24576 * 1024 ** 2, $topo->devices['gpu0']->totalBytes);
        $this->assertSame(20000 * 1024 ** 2, $topo->devices['gpu0']->availableBytes);
        $this->assertFalse($topo->isUnified());
    }

    public function test_missing_meminfo_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->probe(static fn(array $argv): ?string => null)->detect();
    }
}
