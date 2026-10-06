<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Memory;

use LlmChooser\Memory\Device;
use LlmChooser\Memory\FixedTopologyProbe;
use LlmChooser\Memory\MemoryService;
use LlmChooser\Memory\MemoryTopology;
use LlmChooser\Model\GgufReader;
use LlmChooser\Preset\Preset;
use LlmChooser\Tests\Model\GgufFixture;
use PHPUnit\Framework\TestCase;

class MemoryServiceTest extends TestCase
{
    private string $dir;
    private string $model;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmchooser-memsvc-' . uniqid('', true);
        mkdir($this->dir, 0775, true);
        $this->model = $this->dir . '/m.gguf';
        GgufFixture::model($this->model);
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

    public function test_admit_on_generous_unified_topology(): void
    {
        $g    = 1024 ** 3;
        $topo = new MemoryTopology([
            new Device('cpu', Device::KIND_RAM, 100 * $g, 100 * $g),
            new Device('gpu0', Device::KIND_VRAM, $g / 2, $g / 2, 'cpu'),
        ]);
        $svc = new MemoryService(new FixedTopologyProbe($topo));

        $preset = new Preset(['id' => 'p', 'model_path' => $this->model]);
        $alloc  = $svc->estimate($preset);

        $this->assertSame($alloc->breakdown['weights'], filesize($this->model));

        $result = $svc->admit($preset, []);
        $this->assertTrue($result->fits);
        $this->assertSame(['cpu'], array_keys($result->perBudget));
    }
}
