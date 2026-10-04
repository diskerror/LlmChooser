<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Memory;

use LLMchooser\Memory\Device;
use LLMchooser\Memory\MemoryEstimator;
use LLMchooser\Memory\MemoryTopology;
use LLMchooser\Model\GgufReader;
use LLMchooser\Preset\Preset;
use LLMchooser\Tests\Model\GgufFixture;
use PHPUnit\Framework\TestCase;

class MemoryEstimatorTest extends TestCase
{
    private string $dir;
    private string $model;
    private MemoryEstimator $est;

    // fixture metadata (see makeModel)
    private const BLOCKS   = 10;
    private const CTX      = 1000;
    private const EMBED    = 100;
    private const HEADS_KV = 2;
    private const KLEN     = 8;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmchooser-estimate-' . uniqid('', true);
        mkdir($this->dir, 0775, true);
        $this->model = $this->dir . '/m.gguf';
        GgufFixture::write($this->model, [
            ['general.architecture', GgufReader::T_STRING, 'llama'],
            ['llama.block_count', GgufReader::T_UINT32, self::BLOCKS],
            ['llama.context_length', GgufReader::T_UINT32, self::CTX],
            ['llama.embedding_length', GgufReader::T_UINT32, self::EMBED],
            ['llama.attention.head_count', GgufReader::T_UINT32, 4],
            ['llama.attention.head_count_kv', GgufReader::T_UINT32, self::HEADS_KV],
            ['llama.attention.key_length', GgufReader::T_UINT32, self::KLEN],
            ['llama.attention.value_length', GgufReader::T_UINT32, self::KLEN],
        ], 0, 1_000_000);
        $this->est = new MemoryEstimator();
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

    /** KV bytes for the fixture, computed from the formula, not hard-coded. */
    private function expectKv(int $ctx, float $bk, float $bv, ?int $kvLayers = null): int
    {
        $layers = $kvLayers ?? self::BLOCKS;
        $kDim   = self::HEADS_KV * self::KLEN;
        return (int)ceil($layers * $ctx * ($kDim * $bk + $kDim * $bv));
    }

    private function preset(array $args = [], array $paths = []): Preset
    {
        return new Preset(array_merge([
            'id'         => 'p',
            'model_path' => $this->model,
            'args'       => $args,
        ], $paths));
    }

    private function unified(): MemoryTopology
    {
        $g = 1024 ** 3;
        return new MemoryTopology([
            new Device('cpu', Device::KIND_RAM, 100 * $g, 100 * $g),
            new Device('gpu0', Device::KIND_VRAM, $g / 2, $g / 2, 'cpu'),
        ]);
    }

    private function twoDiscrete(): MemoryTopology
    {
        $g = 1024 ** 3;
        return new MemoryTopology([
            new Device('cpu', Device::KIND_RAM, 64 * $g, 64 * $g),
            new Device('gpu0', Device::KIND_VRAM, 24 * $g, 24 * $g),
            new Device('gpu1', Device::KIND_VRAM, 16 * $g, 16 * $g),
        ]);
    }

    private function cpuOnly(): MemoryTopology
    {
        $g = 1024 ** 3;
        return new MemoryTopology([new Device('cpu', Device::KIND_RAM, 64 * $g, 64 * $g)]);
    }

    public function test_kv_formula(): void
    {
        $a = $this->est->estimate($this->preset(), GgufReader::read($this->model), $this->unified());
        // ctx 0 falls back to the model's trained context (1000), f16 = 2.0
        $this->assertSame($this->expectKv(self::CTX, 2.0, 2.0), $a->breakdown['kv_cache']);
        $this->assertSame(640000, $a->breakdown['kv_cache']);
    }

    public function test_ctx_size_override_and_q8(): void
    {
        $a = $this->est->estimate(
            $this->preset(['ctx_size' => 2000, 'cache_type_k' => 'q8_0', 'cache_type_v' => 'q8_0']),
            GgufReader::read($this->model),
            $this->unified()
        );
        $this->assertSame($this->expectKv(2000, 34 / 32, 34 / 32), $a->breakdown['kv_cache']);
    }

    public function test_parallel_does_not_multiply(): void
    {
        $info = GgufReader::read($this->model);
        $one  = $this->est->estimate($this->preset(['parallel' => 1]), $info, $this->unified());
        $four = $this->est->estimate($this->preset(['parallel' => 4]), $info, $this->unified());
        $this->assertSame($one->breakdown['kv_cache'], $four->breakdown['kv_cache']);
    }

    public function test_hybrid_interval(): void
    {
        $hybrid = $this->dir . '/h.gguf';
        GgufFixture::write($hybrid, [
            ['general.architecture', GgufReader::T_STRING, 'qwen3next'],
            ['qwen3next.block_count', GgufReader::T_UINT32, self::BLOCKS],
            ['qwen3next.context_length', GgufReader::T_UINT32, self::CTX],
            ['qwen3next.embedding_length', GgufReader::T_UINT32, self::EMBED],
            ['qwen3next.attention.head_count', GgufReader::T_UINT32, 4],
            ['qwen3next.attention.head_count_kv', GgufReader::T_UINT32, self::HEADS_KV],
            ['qwen3next.attention.key_length', GgufReader::T_UINT32, self::KLEN],
            ['qwen3next.attention.value_length', GgufReader::T_UINT32, self::KLEN],
            ['qwen3next.full_attention_interval', GgufReader::T_UINT32, 4],
        ], 0, 1000);

        $a = $this->est->estimate(
            new Preset(['id' => 'h', 'model_path' => $hybrid]),
            GgufReader::read($hybrid),
            $this->unified()
        );
        // only intdiv(10, 4) = 2 layers keep KV
        $this->assertSame($this->expectKv(self::CTX, 2.0, 2.0, intdiv(self::BLOCKS, 4)), $a->breakdown['kv_cache']);
    }

    public function test_weights_include_mmproj_and_draft(): void
    {
        $mmproj = $this->dir . '/mm.gguf';
        $draft  = $this->dir . '/d-00001-of-00002.gguf';
        $draft2 = $this->dir . '/d-00002-of-00002.gguf';
        file_put_contents($mmproj, str_repeat('x', 3000));
        file_put_contents($draft, str_repeat('x', 700));
        file_put_contents($draft2, str_repeat('x', 800));

        $a = $this->est->estimate(
            $this->preset([], ['mmproj_path' => $mmproj, 'model_draft_path' => $draft]),
            GgufReader::read($this->model),
            $this->unified()
        );

        $this->assertSame(filesize($this->model), $a->breakdown['weights']);
        $this->assertSame(3000, $a->breakdown['mmproj']);
        // draft counts ALL its shards
        $this->assertSame(1500, $a->breakdown['draft']);
        $this->assertSame(array_sum($a->breakdown), $a->totalBytes());
    }

    public function test_all_layers_on_single_gpu(): void
    {
        $a = $this->est->estimate($this->preset(['n_gpu_layers' => 'all']), GgufReader::read($this->model), $this->unified());
        $this->assertSame(['gpu0'], array_keys($a->perDevice));
        $this->assertSame($a->totalBytes(), $a->perDevice['gpu0']);
    }

    public function test_partial_offload(): void
    {
        $a = $this->est->estimate(
            $this->preset(['n_gpu_layers' => '5', 'split_mode' => 'none', 'main_gpu' => 1]),
            GgufReader::read($this->model),
            $this->twoDiscrete()
        );
        $total = $a->totalBytes();
        $this->assertArrayNotHasKey('gpu0', $a->perDevice);
        $this->assertSame((int)round($total * 0.5), $a->perDevice['gpu1']);
        $this->assertSame($total - (int)round($total * 0.5), $a->perDevice['cpu']);
        $this->assertSame($total, array_sum($a->perDevice));
    }

    public function test_cpu_only_ngl_zero(): void
    {
        $a = $this->est->estimate($this->preset(['n_gpu_layers' => '0']), GgufReader::read($this->model), $this->twoDiscrete());
        $this->assertSame(['cpu'], array_keys($a->perDevice));
        $this->assertSame($a->totalBytes(), $a->perDevice['cpu']);
    }

    public function test_tensor_split(): void
    {
        $a = $this->est->estimate(
            $this->preset(['n_gpu_layers' => 'all', 'tensor_split' => '3,1']),
            GgufReader::read($this->model),
            $this->twoDiscrete()
        );
        $total = $a->totalBytes();
        $this->assertSame(['gpu0', 'gpu1'], array_keys($a->perDevice));
        $this->assertSame($total, array_sum($a->perDevice));
        $this->assertEqualsWithDelta($total * 0.75, $a->perDevice['gpu0'], 1);
        $this->assertEqualsWithDelta($total * 0.25, $a->perDevice['gpu1'], 1);
    }

    public function test_even_split_layer_mode(): void
    {
        $a = $this->est->estimate(
            $this->preset(['n_gpu_layers' => 'all', 'split_mode' => 'layer']),
            GgufReader::read($this->model),
            $this->twoDiscrete()
        );
        $total = $a->totalBytes();
        $this->assertSame($total, array_sum($a->perDevice));
        $this->assertEqualsWithDelta($total / 2, $a->perDevice['gpu0'], 1);
        $this->assertEqualsWithDelta($total / 2, $a->perDevice['gpu1'], 1);
    }

    public function test_no_gpus(): void
    {
        $a = $this->est->estimate($this->preset(['n_gpu_layers' => 'all']), GgufReader::read($this->model), $this->cpuOnly());
        $this->assertSame(['cpu'], array_keys($a->perDevice));
    }
}
