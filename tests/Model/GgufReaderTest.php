<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Model;

use LlmChooser\Model\GgufException;
use LlmChooser\Model\GgufReader;
use PHPUnit\Framework\TestCase;

class GgufReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmc-gguf-' . uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    public function test_reads_basic_metadata(): void
    {
        $p = $this->dir . '/m.gguf';
        GgufFixture::model($p, 'qwen3');

        $info = GgufReader::read($p);

        $this->assertSame(3, $info->version);
        $this->assertSame('qwen3', $info->architecture());
        $this->assertSame(32, $info->blockCount());
        $this->assertSame(8192, $info->contextLength());
        $this->assertSame(4096, $info->embeddingLength());
        $this->assertSame(32, $info->headCount());
        $this->assertSame(8, $info->headCountKv());
        $this->assertSame(128, $info->keyLength());
        $this->assertSame(128, $info->valueLength());
        $this->assertSame(1, $info->splitCount());
    }

    public function test_all_scalar_types(): void
    {
        $p = $this->dir . '/scalars.gguf';
        GgufFixture::write($p, [
            ['u8',   GgufReader::T_UINT8,   200],
            ['i8',   GgufReader::T_INT8,    -5],
            ['u16',  GgufReader::T_UINT16,  65000],
            ['i16',  GgufReader::T_INT16,   -300],
            ['u32',  GgufReader::T_UINT32,  4000000000],
            ['i32',  GgufReader::T_INT32,   -70000],
            ['f32',  GgufReader::T_FLOAT32, 0.5],
            ['bool', GgufReader::T_BOOL,    true],
            ['str',  GgufReader::T_STRING,  'héllo'],
            ['u64',  GgufReader::T_UINT64,  5000000000],
            ['i64',  GgufReader::T_INT64,   -5000000000],
            ['f64',  GgufReader::T_FLOAT64, 0.125],
        ]);

        $meta = GgufReader::read($p)->metadata;

        $this->assertSame(200, $meta['u8']);
        $this->assertSame(-5, $meta['i8']);
        $this->assertSame(65000, $meta['u16']);
        $this->assertSame(-300, $meta['i16']);
        $this->assertSame(4000000000, $meta['u32']);
        $this->assertSame(-70000, $meta['i32']);
        $this->assertSame(0.5, $meta['f32']);
        $this->assertSame(true, $meta['bool']);
        $this->assertSame('héllo', $meta['str']);
        $this->assertSame(5000000000, $meta['u64']);
        $this->assertSame(-5000000000, $meta['i64']);
        $this->assertSame(0.125, $meta['f64']);
    }

    public function test_head_count_kv_array_takes_max(): void
    {
        $p = $this->dir . '/kvmax.gguf';
        GgufFixture::model($p, 'llama', 100, [
            ['llama.attention.head_count_kv', GgufReader::T_ARRAY, [GgufReader::T_UINT32, [0, 8, 0, 8, 4]]],
        ]);

        $this->assertSame(8, GgufReader::read($p)->headCountKv());
    }

    public function test_large_string_array_is_skipped_and_following_keys_still_read(): void
    {
        $p = $this->dir . '/tokens.gguf';
        $tokens = [];
        for ($i = 0; $i < 5000; ++$i) {
            $tokens[] = 'tok' . $i;
        }
        GgufFixture::write($p, [
            ['general.architecture', GgufReader::T_STRING, 'llama'],
            ['tokenizer.ggml.tokens', GgufReader::T_ARRAY, [GgufReader::T_STRING, $tokens]],
            ['llama.block_count', GgufReader::T_UINT32, 48],
        ]);

        $info = GgufReader::read($p);

        $this->assertNull($info->get('tokenizer.ggml.tokens'));
        $this->assertSame(48, $info->blockCount());
    }

    public function test_explicit_key_length(): void
    {
        $p = $this->dir . '/kl.gguf';
        GgufFixture::model($p, 'llama', 100, [
            ['llama.attention.key_length', GgufReader::T_UINT32, 256],
            ['llama.attention.value_length', GgufReader::T_UINT32, 192],
        ]);

        $info = GgufReader::read($p);

        $this->assertSame(256, $info->keyLength());
        $this->assertSame(192, $info->valueLength());
    }

    public function test_rejects_non_gguf(): void
    {
        $p = $this->dir . '/nope.gguf';
        file_put_contents($p, 'NOPE....');

        $this->expectException(GgufException::class);
        GgufReader::read($p);
    }

    public function test_rejects_truncated_file(): void
    {
        $p = $this->dir . '/trunc.gguf';
        GgufFixture::model($p);
        file_put_contents($p, substr(file_get_contents($p), 0, 30));

        $this->expectException(GgufException::class);
        GgufReader::read($p);
    }

    public function test_rejects_bad_version(): void
    {
        $p = $this->dir . '/v1.gguf';
        GgufFixture::write($p, [], 0, 0, 1);

        $this->expectException(GgufException::class);
        GgufReader::read($p);
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
