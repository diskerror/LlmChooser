<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Process;

use LlmChooser\Engine\Engine;
use LlmChooser\Preset\Preset;
use LlmChooser\Preset\PresetRepository;
use LlmChooser\Process\PortAllocator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PortAllocatorTest extends TestCase
{
    private string $dir;
    private PresetRepository $presets;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmchooser-ports-' . uniqid('', true);
        $this->presets = new PresetRepository($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function engine(int $min, int $max): Engine
    {
        return new Engine(['id' => 'e', 'host' => '127.0.0.1', 'port_range' => ['min' => $min, 'max' => $max]]);
    }

    public function test_new_preset_gets_lowest_and_it_is_saved(): void
    {
        $p = new Preset(['id' => 'a']);
        $port = (new PortAllocator($this->presets))->portFor($p, $this->engine(5880, 5883));

        $this->assertSame(5880, $port);
        $this->assertSame(5880, $this->presets->load('a')->assigned_port);
    }

    public function test_second_preset_gets_next(): void
    {
        $alloc = new PortAllocator($this->presets);
        $this->assertSame(5880, $alloc->portFor(new Preset(['id' => 'a']), $this->engine(5880, 5883)));
        $this->assertSame(5881, $alloc->portFor(new Preset(['id' => 'b']), $this->engine(5880, 5883)));
    }

    public function test_assigned_port_is_kept(): void
    {
        $p = new Preset(['id' => 'a', 'assigned_port' => 5880]);
        $this->assertSame(5880, (new PortAllocator($this->presets))->portFor($p, $this->engine(5880, 5883)));
    }

    public function test_out_of_range_assignment_is_replaced(): void
    {
        $p = new Preset(['id' => 'a', 'assigned_port' => 9999]);
        $port = (new PortAllocator($this->presets))->portFor($p, $this->engine(5880, 5883));

        $this->assertNotSame(9999, $port);
        $this->assertSame($port, $this->presets->load('a')->assigned_port);
    }

    public function test_busy_port_is_skipped(): void
    {
        $busy = stream_socket_server('tcp://127.0.0.1:5880', $errno, $errstr);
        $this->assertNotFalse($busy, 'could not occupy 5880 for the test');

        try {
            $p = new Preset(['id' => 'a']);
            $this->assertSame(5881, (new PortAllocator($this->presets))->portFor($p, $this->engine(5880, 5883)));
        } finally {
            fclose($busy);
        }
    }

    public function test_range_exhausted_throws(): void
    {
        $other = new Preset(['id' => 'other', 'assigned_port' => 5880]);
        $this->presets->save($other);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No free port in range 5880-5880');
        (new PortAllocator($this->presets))->portFor(new Preset(['id' => 'a']), $this->engine(5880, 5880));
    }
}
