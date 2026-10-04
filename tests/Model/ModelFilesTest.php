<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Model;

use LLMchooser\Model\ModelFiles;
use PHPUnit\Framework\TestCase;

class ModelFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmchooser-modelfiles-' . uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function test_single_file(): void
    {
        $p = $this->dir . '/model.gguf';
        file_put_contents($p, str_repeat('x', 100));

        $this->assertSame([$p], ModelFiles::shardPaths($p));
        $this->assertSame(100, ModelFiles::totalBytes($p));
    }

    public function test_three_shards_sum_in_order(): void
    {
        $paths = [];
        foreach ([10, 20, 30] as $i => $size) {
            $p = sprintf($this->dir . '/m-%05d-of-00003.gguf', $i + 1);
            file_put_contents($p, str_repeat('x', $size));
            $paths[] = $p;
        }

        $found = ModelFiles::shardPaths($paths[0]);
        $this->assertSame($paths, $found);
        $this->assertSame(60, ModelFiles::totalBytes($paths[0]));
        // Any shard asked about returns the whole set.
        $this->assertSame($paths, ModelFiles::shardPaths($paths[2]));
    }

    public function test_missing_file_is_zero(): void
    {
        $p = $this->dir . '/nope.gguf';
        $this->assertSame([$p], ModelFiles::shardPaths($p));
        $this->assertSame(0, ModelFiles::totalBytes($p));
    }
}
