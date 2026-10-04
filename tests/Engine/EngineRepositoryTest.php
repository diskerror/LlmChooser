<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Engine;

use LLMchooser\Engine\Engine;
use LLMchooser\Engine\EngineCollection;
use LLMchooser\Engine\EngineRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EngineRepositoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmchooser-engine-tests-' . uniqid('', true);
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

    public function test_save_load_roundtrip(): void
    {
        $repo = new EngineRepository($this->dir);
        $engine = new Engine([
            'id'                  => 'test-engine',
            'name'                => 'Test Engine',
            'binary_path_default' => '/opt/llama.cpp/build/bin/llama-server',
            'port_range'          => ['min' => 6000, 'max' => 6100],
        ]);
        $repo->save($engine);

        $loaded = $repo->load('test-engine');
        $this->assertSame('test-engine', $loaded->id);
        $this->assertSame('Test Engine', $loaded->name);
        $this->assertSame('/opt/llama.cpp/build/bin/llama-server', $loaded->binary_path_default);
        $this->assertSame('127.0.0.1', $loaded->host); // default survived
        $this->assertSame(6000, $loaded->port_range->min);
        $this->assertSame(6100, $loaded->port_range->max);
    }

    public function test_loadall_returns_collection(): void
    {
        $repo = new EngineRepository($this->dir);
        foreach (['one', 'two', 'three'] as $id) {
            $repo->save(new Engine(['id' => $id, 'name' => ucfirst($id)]));
        }
        $all = $repo->loadAll();
        $this->assertInstanceOf(EngineCollection::class, $all);
        $this->assertCount(3, $all);
    }

    public function test_delete_removes_file(): void
    {
        $repo = new EngineRepository($this->dir);
        $repo->save(new Engine(['id' => 'doomed']));
        $repo->delete('doomed');
        $this->expectException(RuntimeException::class);
        $repo->load('doomed');
    }

    public function test_traversal_guard_rejects_bad_ids(): void
    {
        $repo = new EngineRepository($this->dir);
        $this->expectException(RuntimeException::class);
        $repo->load('../evil');
    }

    public function test_seeded_beelink_local_config(): void
    {
        $repo = new EngineRepository(dirname(__DIR__, 2) . '/config/engines');
        $engine = $repo->load('beelink-local');
        $this->assertSame('beelink-local', $engine->id);
        $this->assertSame('Beelink Local', $engine->name);
        $this->assertSame('/opt/llama.cpp-ngrams/build/bin/llama-server', $engine->binary_path_default);
        $this->assertSame('127.0.0.1', $engine->host);
        $this->assertSame(5800, $engine->port_range->min);
        $this->assertSame(5900, $engine->port_range->max);
    }
}
