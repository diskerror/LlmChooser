<?php

namespace LLMchooser\Tests\Model;

use LLMchooser\Model\ModelEntry;
use LLMchooser\Model\ModelScanner;
use PHPUnit\Framework\TestCase;

class ModelScannerTest extends TestCase
{
    private string $r;

    protected function setUp(): void
    {
        $this->r = sys_get_temp_dir() . '/llmc-scan-' . uniqid('', true);

        GgufFixture::model($this->r . '/a/plain-Q4_K_M.gguf', 'llama', 1000);
        GgufFixture::model($this->r . '/b/big-Q5-00001-of-00003.gguf', 'llama', 100);
        GgufFixture::model($this->r . '/b/big-Q5-00002-of-00003.gguf', 'llama', 200);
        GgufFixture::model($this->r . '/b/big-Q5-00003-of-00003.gguf', 'llama', 300);
        GgufFixture::model($this->r . '/c/broken-00001-of-00002.gguf', 'llama', 10);
        GgufFixture::model($this->r . '/q/Q5_K_M-Ngrams/model-Q5.gguf', 'llama', 500);
        GgufFixture::model($this->r . '/q/shared/mmproj-model-f16.gguf', 'llama', 50);
        GgufFixture::model($this->r . '/q/shared/mtp-model-Q8_0.gguf', 'llama', 50);
        GgufFixture::model($this->r . '/q/shared/ngrams-model-BF16.gguf', 'llama', 50);
        GgufFixture::model($this->r . '/.cache/hidden.gguf', 'llama', 10);
        mkdir($this->r . '/z', 0775, true);
        file_put_contents($this->r . '/notes.txt', 'not a model');
        file_put_contents($this->r . '/z/bad.gguf', 'garbage!');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->r);
    }

    private function entry(array $entries, string $displayName): ModelEntry
    {
        foreach ($entries as $e) {
            if ($e->displayName === $displayName) {
                return $e;
            }
        }
        $this->fail("no entry '{$displayName}'; got: " . implode(', ', array_column($entries, 'displayName')));
    }

    public function test_finds_entries_and_skips_sidecars_and_hidden(): void
    {
        $entries = (new ModelScanner([$this->r]))->scan();

        $this->assertCount(5, $entries);
        $this->assertSame(
            ['a/plain-Q4_K_M', 'b/big-Q5', 'c/broken', 'q/Q5_K_M-Ngrams/model-Q5', 'z/bad'],
            array_map(static fn(ModelEntry $e) => $e->displayName, $entries)
        );
    }

    public function test_shards_collapsed(): void
    {
        $entries = (new ModelScanner([$this->r]))->scan();
        $e       = $this->entry($entries, 'b/big-Q5');

        $this->assertCount(3, $e->shardPaths);
        $this->assertStringEndsWith('-00001-of-00003.gguf', $e->path);
        $this->assertSame(
            filesize($this->r . '/b/big-Q5-00001-of-00003.gguf')
            + filesize($this->r . '/b/big-Q5-00002-of-00003.gguf')
            + filesize($this->r . '/b/big-Q5-00003-of-00003.gguf'),
            $e->sizeBytes
        );
        $this->assertNull($e->error);
        $this->assertNotNull($e->info);
    }

    public function test_incomplete_shard_set(): void
    {
        $entries = (new ModelScanner([$this->r]))->scan();
        $e       = $this->entry($entries, 'c/broken');

        $this->assertSame('incomplete shard set: have 1 of 2', $e->error);
        $this->assertNull($e->info);
    }

    public function test_sidecars_from_shared_sibling(): void
    {
        $entries = (new ModelScanner([$this->r]))->scan();
        $e       = $this->entry($entries, 'q/Q5_K_M-Ngrams/model-Q5');

        $this->assertSame([$this->r . '/q/shared/mmproj-model-f16.gguf'], $e->sidecars['mmproj']);
        $this->assertSame([$this->r . '/q/shared/mtp-model-Q8_0.gguf'], $e->sidecars['draft']);
        $this->assertSame([$this->r . '/q/shared/ngrams-model-BF16.gguf'], $e->sidecars['ngrams']);
    }

    public function test_unreadable_header_gives_error(): void
    {
        $entries = (new ModelScanner([$this->r]))->scan();
        $e       = $this->entry($entries, 'z/bad');

        $this->assertNull($e->info);
        $this->assertNotEmpty($e->error);
    }

    public function test_missing_root_ignored(): void
    {
        $this->assertSame([], (new ModelScanner([$this->r . '/nope']))->scan());
    }

    public function test_cache_used_when_size_and_mtime_match(): void
    {
        $cacheFile = $this->r . '/cache/model_cache.json';
        (new ModelScanner([$this->r], $cacheFile))->scan();

        $cache = json_decode(file_get_contents($cacheFile), true);
        $key   = $this->r . '/a/plain-Q4_K_M.gguf';
        $this->assertArrayHasKey($key, $cache);
        $cache[$key]['info']['metadata']['general.name'] = 'FROM CACHE';
        file_put_contents($cacheFile, json_encode($cache));

        $entries = (new ModelScanner([$this->r], $cacheFile))->scan();
        $e       = $this->entry($entries, 'a/plain-Q4_K_M');

        $this->assertSame('FROM CACHE', $e->info->get('general.name'));
    }

    public function test_cache_refreshed_when_mtime_changes(): void
    {
        $cacheFile = $this->r . '/cache/model_cache.json';
        (new ModelScanner([$this->r], $cacheFile))->scan();

        $key = $this->r . '/a/plain-Q4_K_M.gguf';
        $cache = json_decode(file_get_contents($cacheFile), true);
        $cache[$key]['info']['metadata']['general.name'] = 'FROM CACHE';
        file_put_contents($cacheFile, json_encode($cache));

        touch($key, time() + 10);

        $entries = (new ModelScanner([$this->r], $cacheFile))->scan();
        $e       = $this->entry($entries, 'a/plain-Q4_K_M');

        $this->assertSame('Fixture Model', $e->info->get('general.name'));
    }

    public function test_cache_drops_removed_files(): void
    {
        $cacheFile = $this->r . '/cache/model_cache.json';
        (new ModelScanner([$this->r], $cacheFile))->scan();

        $key = $this->r . '/a/plain-Q4_K_M.gguf';
        $this->assertArrayHasKey($key, json_decode(file_get_contents($cacheFile), true));

        unlink($key);
        (new ModelScanner([$this->r], $cacheFile))->scan();

        $this->assertArrayNotHasKey($key, json_decode(file_get_contents($cacheFile), true));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
