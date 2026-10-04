<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Memory;

use LLMchooser\Memory\AdmissionCheck;
use LLMchooser\Memory\Allocation;
use LLMchooser\Memory\Device;
use LLMchooser\Memory\MemoryTopology;
use PHPUnit\Framework\TestCase;

class AdmissionCheckTest extends TestCase
{
    private AdmissionCheck $check;

    protected function setUp(): void
    {
        $this->check = new AdmissionCheck();
    }

    private function unified(float $cpuAvailableGb = 100): MemoryTopology
    {
        $g = 1024 ** 3;
        return new MemoryTopology([
            new Device('cpu', Device::KIND_RAM, 100 * $g, (int)($cpuAvailableGb * $g)),
            new Device('gpu0', Device::KIND_VRAM, $g / 2, $g / 2, 'cpu'),
        ]);
    }

    /** @param array<string,int> $perDevice */
    private function alloc(array $perDevice): Allocation
    {
        return new Allocation($perDevice, []);
    }

    public function test_unified_fits_and_collapses_to_cpu(): void
    {
        $r = $this->check->check($this->alloc(['gpu0' => 60_000_000_000]), [], $this->unified());

        $this->assertTrue($r->fits);
        // gpu0 aliased to cpu: exactly one budget row.
        $this->assertSame(['cpu'], array_keys($r->perBudget));
        $this->assertSame(60_000_000_000, $r->perBudget['cpu']['need']);
    }

    public function test_unified_combined_exceeds(): void
    {
        $r = $this->check->check(
            $this->alloc(['gpu0' => 60_000_000_000, 'cpu' => 50_000_000_000]),
            [],
            $this->unified()
        );

        $this->assertFalse($r->fits);
        $this->assertSame(110_000_000_000, $r->perBudget['cpu']['need']);
    }

    public function test_discrete_full_gpu_does_not_block_other(): void
    {
        $topo = new MemoryTopology([
            new Device('cpu', Device::KIND_RAM, 64 * 1024 ** 3, 64 * 1024 ** 3),
            new Device('gpu0', Device::KIND_VRAM, 24 * 1024 ** 3, 0),
            new Device('gpu1', Device::KIND_VRAM, 16 * 1024 ** 3, 16 * 1024 ** 3),
        ]);
        $r = $this->check->check($this->alloc(['gpu1' => 10_000_000_000]), [], $topo);

        $this->assertTrue($r->fits);
        $this->assertSame(['gpu1'], array_keys($r->perBudget));
    }

    public function test_discrete_one_fails_one_ok(): void
    {
        $g = 1024 ** 3;
        $topo = new MemoryTopology([
            new Device('cpu', Device::KIND_RAM, 64 * $g, 64 * $g),
            new Device('gpu0', Device::KIND_VRAM, 24 * $g, 24 * $g),
            new Device('gpu1', Device::KIND_VRAM, 16 * $g, 16 * $g),
        ]);
        $r = $this->check->check($this->alloc(['gpu0' => 20_000_000_000, 'gpu1' => 20_000_000_000]), [], $topo);

        $this->assertFalse($r->fits);
        $this->assertStringContainsString('FAIL gpu1', $r->message());
        $this->assertStringContainsString('OK   gpu0', $r->message());
    }

    public function test_already_running_is_reported_not_counted(): void
    {
        $r = $this->check->check(
            $this->alloc(['gpu0' => 60_000_000_000]),
            [$this->alloc(['gpu0' => 5_000_000_000])],
            $this->unified()
        );

        $this->assertSame(5_000_000_000, $r->perBudget['cpu']['already']);
        // MemAvailable already excludes the running instance: need is
        // compared on its own, so this still fits.
        $this->assertTrue($r->fits);
    }

    public function test_unknown_device_fails(): void
    {
        $r = $this->check->check($this->alloc(['gpu7' => 1]), [], $this->unified());

        $this->assertFalse($r->fits);
        $this->assertSame(0, $r->perBudget['gpu7']['available']);
    }
}
