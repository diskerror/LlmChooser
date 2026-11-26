<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Memory;

use LlmChooser\Memory\WeightPlacement;
use LlmChooser\Model\GgufInfo;
use LlmChooser\Model\NgramTable;
use LlmChooser\Preset\Preset;
use PHPUnit\Framework\TestCase;

class WeightPlacementTest extends TestCase
{
	private const N_LAYER = 4;

	// Per layer: attn_q 10 + ffn_up_exps 20 + attn_norm 1 = 31; output layer 50 + 1; input 100.
	private const LAYER  = 31;
	private const OUTPUT = 51;
	private const INPUT  = 100;
	private const EXPS   = 20;

	private const F32  = 0;
	private const F16  = 1;
	private const Q4_K = 12;
	private const BF16 = 30;

	/** @return list<array{name:string, dims:list<int>, type:int, bytes:int}> */
	private static function tensors(int $exps_type = self::F16, int $ngram_bytes = 0): array
	{
		$t = [['name' => 'token_embd.weight', 'dims' => [8, 8], 'type' => self::Q4_K, 'bytes' => self::INPUT]];
		if ($ngram_bytes > 0) {
			$t[] = ['name' => 'per_layer_token_embd.weight', 'dims' => [8, 8], 'type' => self::BF16, 'bytes' => $ngram_bytes];
		}
		for ($i = 0; $i < self::N_LAYER; ++$i) {
			$t[] = ['name' => "blk.{$i}.attn_q.weight", 'dims' => [4, 4], 'type' => self::F16, 'bytes' => 10];
			$t[] = ['name' => "blk.{$i}.ffn_up_exps.weight", 'dims' => [4, 4, 2], 'type' => $exps_type, 'bytes' => self::EXPS];
			$t[] = ['name' => "blk.{$i}.attn_norm.weight", 'dims' => [4], 'type' => self::F32, 'bytes' => 1];
		}
		$t[] = ['name' => 'output.weight', 'dims' => [4, 8], 'type' => self::F16, 'bytes' => 50];
		$t[] = ['name' => 'output_norm.weight', 'dims' => [4], 'type' => self::F32, 'bytes' => 1];
		return $t;
	}

	private static function preset(array $args = []): Preset
	{
		return new Preset(['id' => 'p', 'model_path' => '/m/x.gguf', 'args' => $args]);
	}

	private static function place(array $args = [], bool $gpu = true, int $exps_type = self::F16, int $ngram_bytes = 0, ?NgramTable $ngrams = null): WeightPlacement
	{
		return WeightPlacement::of(self::tensors($exps_type, $ngram_bytes), self::N_LAYER, self::preset($args), $gpu, $ngrams);
	}

	/** @return array{0:int,1:int,2:int} [gpu, cpu_resident, mapped] */
	private static function split(WeightPlacement $w): array
	{
		return [$w->gpu, $w->cpu_resident, $w->mapped];
	}

	public function test_all_layers_on_gpu_input_layer_stays_mapped(): void
	{
		$all = [4 * self::LAYER + self::OUTPUT, 0, self::INPUT];
		$this->assertSame($all, self::split(self::place()));
		$this->assertSame($all, self::split(self::place(['n_gpu_layers' => 'all'])));
		$this->assertSame($all, self::split(self::place(['n_gpu_layers' => '-1'])));
		$this->assertSame($all, self::split(self::place(['n_gpu_layers' => '99'])));
		$this->assertNull(self::place()->note);
	}

	public function test_no_gpu_or_dev_none_maps_everything(): void
	{
		$cpu = [0, 0, self::INPUT + 4 * self::LAYER + self::OUTPUT];
		$this->assertSame($cpu, self::split(self::place([], false)));
		$this->assertSame($cpu, self::split(self::place(['extra_flags' => '-dev none'])));
		$this->assertSame($cpu, self::split(self::place(['extra_flags' => '--device=none'])));
		$this->assertSame($cpu, self::split(self::place(['n_gpu_layers' => '0'])));
		// a real device list still offloads
		$this->assertSame(4 * self::LAYER + self::OUTPUT, self::place(['extra_flags' => '-dev Vulkan0'])->gpu);
	}

	public function test_partial_offload_takes_output_then_last_layers(): void
	{
		// llama.cpp: i_gpu_start = n_layer + 1 - ngl; the output layer is layer n_layer
		$this->assertSame([self::OUTPUT, 0, self::INPUT + 4 * self::LAYER], self::split(self::place(['n_gpu_layers' => '1'])));
		$this->assertSame([self::OUTPUT + self::LAYER, 0, self::INPUT + 3 * self::LAYER], self::split(self::place(['n_gpu_layers' => '2'])));
	}

	public function test_cpu_moe_keeps_experts_off_the_gpu(): void
	{
		$gpu = 4 * self::LAYER + self::OUTPUT;
		$this->assertSame([$gpu - 4 * self::EXPS, 0, self::INPUT + 4 * self::EXPS], self::split(self::place(['extra_flags' => '--cpu-moe'])));
		$this->assertSame([$gpu - 4 * self::EXPS, 0, self::INPUT + 4 * self::EXPS], self::split(self::place(['extra_flags' => '-cmoe'])));
		// first N layers only
		$this->assertSame([$gpu - 2 * self::EXPS, 0, self::INPUT + 2 * self::EXPS], self::split(self::place(['extra_flags' => '--n-cpu-moe 2'])));
		$this->assertSame([$gpu - 3 * self::EXPS, 0, self::INPUT + 3 * self::EXPS], self::split(self::place(['extra_flags' => '-ncmoe=3'])));
	}

	public function test_repacked_cpu_weights_are_resident_unless_no_repack(): void
	{
		$gpu = 4 * self::LAYER + self::OUTPUT - 4 * self::EXPS;
		// Q4_K experts on the CPU get repacked; Q4_K token_embd (input layer) does not
		$this->assertSame([$gpu, 4 * self::EXPS, self::INPUT], self::split(self::place(['extra_flags' => '--cpu-moe'], true, self::Q4_K)));
		$this->assertSame([$gpu, 0, self::INPUT + 4 * self::EXPS], self::split(self::place(['extra_flags' => '--cpu-moe -nr'], true, self::Q4_K)));
		$this->assertSame([$gpu, 0, self::INPUT + 4 * self::EXPS], self::split(self::place(['extra_flags' => '--cpu-moe --no-repack'], true, self::Q4_K)));
	}

	public function test_load_modes_without_mmap_or_with_mlock_make_cpu_weights_resident(): void
	{
		$gpu = 4 * self::LAYER + self::OUTPUT;
		foreach (['none', 'mlock', 'mmap+mlock', 'dio'] as $mode) {
			$this->assertSame([$gpu, self::INPUT, 0], self::split(self::place(['load_mode' => $mode])), $mode);
		}
		$this->assertSame([$gpu, 0, self::INPUT], self::split(self::place(['load_mode' => 'mmap'])));
		$this->assertSame([$gpu, self::INPUT, 0], self::split(self::place(['extra_flags' => '--no-mmap'])));
		$this->assertSame([$gpu, self::INPUT, 0], self::split(self::place(['extra_flags' => '--mlock'])));
	}

	public function test_embedded_ngram_table_mapped_when_lazy(): void
	{
		foreach (['auto', 'on'] as $lazy) {
			$w = self::place(['lazy_mode' => $lazy], true, self::F16, 5000);
			$this->assertSame([0, 5000], [$w->ngrams, $w->ngrams_mapped], $lazy);
			$this->assertSame(self::INPUT, $w->mapped);
		}
		$off = self::place(['lazy_mode' => 'off'], true, self::F16, 5000);
		$this->assertSame([5000, 0], [$off->ngrams, $off->ngrams_mapped]);
		// lazy loading needs mmap
		$no_mmap = self::place(['load_mode' => 'none'], true, self::F16, 5000);
		$this->assertSame([5000, 0], [$no_mmap->ngrams, $no_mmap->ngrams_mapped]);
		// never offloaded, even with every layer on the GPU
		$this->assertSame(4 * self::LAYER + self::OUTPUT, $off->gpu);
	}

	public function test_separate_ngram_file_follows_the_same_rule(): void
	{
		$dir = sys_get_temp_dir() . '/llmchooser-wp-' . uniqid('', true);
		mkdir($dir);
		try {
			file_put_contents($dir . '/ngrams.gguf', str_repeat("\0", 2048));
			$info   = new GgufInfo(3, 0, ['general.architecture' => NgramTable::ARCH, NgramTable::FILE_KEY => 'ngrams.gguf']);
			$ngrams = NgramTable::of($dir . '/x.gguf', $info);

			$lazy = self::place([], true, self::F16, 0, $ngrams);
			$this->assertSame([0, 2048], [$lazy->ngrams, $lazy->ngrams_mapped]);
			$off = self::place(['lazy_mode' => 'off'], true, self::F16, 0, $ngrams);
			$this->assertSame([2048, 0], [$off->ngrams, $off->ngrams_mapped]);

			// missing file: nothing to add
			$missing = NgramTable::of($dir . '/x.gguf', new GgufInfo(3, 0, ['general.architecture' => NgramTable::ARCH, NgramTable::FILE_KEY => 'nope.gguf']));
			$none = self::place([], true, self::F16, 0, $missing);
			$this->assertSame([0, 0], [$none->ngrams, $none->ngrams_mapped]);
		}
		finally {
			exec('rm -rf ' . escapeshellarg($dir));
		}
	}

	public function test_override_tensor_falls_back_to_all_resident_with_note(): void
	{
		foreach (['-ot exps=CPU', '--override-tensor=exps=CPU'] as $flags) {
			$w = self::place(['extra_flags' => $flags]);
			$this->assertSame([self::INPUT + 4 * self::LAYER + self::OUTPUT, 0, 0], self::split($w), $flags);
			$this->assertSame(WeightPlacement::OT_NOTE, $w->note);
		}
	}

	public function test_read_per_token_scales_mapped_experts_and_skips_input_rows(): void
	{
		$of = static fn(array $args): WeightPlacement => WeightPlacement::of(self::tensors(), self::N_LAYER, self::preset($args), true, null, 1, 2);
		// all on CPU: dense layer weights + output in full, experts at 1 of 2, token_embd rows ~0
		$this->assertSame(4 * (self::LAYER - self::EXPS) + self::OUTPUT + 4 * self::EXPS / 2, $of(['extra_flags' => '-dev none'])->read_per_token);
		$this->assertSame(4 * self::EXPS / 2, $of(['extra_flags' => '--cpu-moe'])->read_per_token);
		$this->assertSame(0, $of([])->read_per_token);
		// not an MoE: experts read in full
		$this->assertSame(4 * self::EXPS, self::place(['extra_flags' => '--cpu-moe'])->read_per_token);
		// resident (repacked) experts are not read from disk
		$this->assertSame(0, WeightPlacement::of(self::tensors(self::Q4_K), self::N_LAYER, self::preset(['extra_flags' => '--cpu-moe']), true, null, 1, 2)->read_per_token);
	}

	public function test_flags_parsing(): void
	{
		$f = WeightPlacement::flags('--verbose -dev none --n-cpu-moe 5 -nr --temp 0.7');
		$this->assertTrue($f['dev_none']);
		$this->assertSame(5, $f['n_cpu_moe']);
		$this->assertTrue($f['no_repack']);
		$this->assertFalse($f['cpu_moe']);
		$this->assertFalse($f['ot']);
		$this->assertFalse(WeightPlacement::flags('-dev Vulkan0')['dev_none']);
		$this->assertSame(0, WeightPlacement::flags('')['n_cpu_moe']);
	}

	public function test_gpu_layers(): void
	{
		$this->assertSame(5, WeightPlacement::gpuLayers('auto', 4));
		$this->assertSame(5, WeightPlacement::gpuLayers('all', 4));
		$this->assertSame(5, WeightPlacement::gpuLayers('-1', 4));
		$this->assertSame(5, WeightPlacement::gpuLayers('100', 4));
		$this->assertSame(2, WeightPlacement::gpuLayers('2', 4));
		$this->assertSame(0, WeightPlacement::gpuLayers('0', 4));
	}
}
