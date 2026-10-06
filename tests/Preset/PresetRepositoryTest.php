<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Preset;

use LLMchooser\Preset\Preset;
use LLMchooser\Preset\PresetCollection;
use LLMchooser\Preset\PresetRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PresetRepositoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmchooser-preset-tests-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
    }

    public function test_save_load_roundtrip_with_nested_args(): void
    {
        $repo = new PresetRepository($this->dir);
        $preset = new Preset([
            'id'            => 'test-preset',
            'name'          => 'Test Preset',
            'engine_id'     => 'beelink-local',
            'model_path'    => '/models/test.gguf',
            'assigned_port' => 5801,
            'args'          => ['temp' => 0.3, 'ctx_size' => 8192, 'flash_attn' => 'on'],
        ]);
        $repo->save($preset);

        $loaded = $repo->load('test-preset');
        $this->assertSame('test-preset', $loaded->id);
        $this->assertSame('Test Preset', $loaded->name);
        $this->assertSame('beelink-local', $loaded->engine_id);
        $this->assertSame('/models/test.gguf', $loaded->model_path);
        $this->assertSame(5801, $loaded->assigned_port);
        $this->assertSame(0.3, $loaded->args->temp);
        $this->assertSame(8192, $loaded->args->ctx_size);
        $this->assertSame('on', $loaded->args->flash_attn);
        $this->assertSame(0.95, $loaded->args->top_p); // default survived the round trip
    }

    public function test_loadall_returns_collection_of_saved_presets(): void
    {
        $repo = new PresetRepository($this->dir);
        foreach (['alpha', 'beta', 'gamma'] as $id) {
            $repo->save(new Preset(['id' => $id, 'name' => ucfirst($id)]));
        }
        $all = $repo->loadAll();
        $this->assertInstanceOf(PresetCollection::class, $all);
        $this->assertCount(3, $all);
        $this->assertArrayHasKey('alpha', $all);
        $this->assertArrayHasKey('beta', $all);
        $this->assertArrayHasKey('gamma', $all);
    }

    public function test_delete_removes_file(): void
    {
        $repo = new PresetRepository($this->dir);
        $repo->save(new Preset(['id' => 'doomed']));
        $repo->delete('doomed');
        $this->expectException(RuntimeException::class);
        $repo->load('doomed');
    }

    public function test_traversal_guard_rejects_bad_ids(): void
    {
        $repo = new PresetRepository($this->dir);
        $this->expectException(RuntimeException::class);
        $repo->load('../evil');
    }

    public function test_save_rejects_empty_id(): void
    {
        $repo = new PresetRepository($this->dir);
        $this->expectException(RuntimeException::class);
        $repo->save(new Preset());
    }

    public function test_set_enabled_false_persists(): void
    {
        $repo = new PresetRepository($this->dir);
        $repo->save(new Preset(['id' => 'parked']));
        $this->assertTrue($repo->load('parked')->enabled); // default

        $repo->setEnabled('parked', false);
        $this->assertFalse($repo->load('parked')->enabled);
    }

    public function test_set_enabled_true_persists(): void
    {
        $repo = new PresetRepository($this->dir);
        $repo->save(new Preset(['id' => 'flipped', 'enabled' => false]));
        $this->assertFalse($repo->load('flipped')->enabled);

        $repo->setEnabled('flipped', true);
        $this->assertTrue($repo->load('flipped')->enabled);
    }

    public function test_legacy_file_without_enabled_key_loads_as_enabled(): void
    {
        // A preset JSON saved before Phase 8 has no "enabled" key at all.
        $repo = new PresetRepository($this->dir);
        file_put_contents($this->dir . '/legacy.json', json_encode([
            'id'         => 'legacy',
            'name'       => 'Legacy',
            'engine_id'  => 'beelink-local',
            'model_path' => '/models/legacy.gguf',
        ]));
        $this->assertTrue($repo->load('legacy')->enabled);
    }
}
