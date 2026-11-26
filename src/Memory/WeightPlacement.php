<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

use LlmChooser\Model\NgramTable;
use LlmChooser\Preset\Preset;
use LlmChooser\Process\CommandBuilder;

/**
 * Where llama-server puts each weight tensor, and whether its bytes are
 * resident (must fit) or memory-mapped (page cache: reclaimable, read from
 * disk on demand; decides speed, not fit).
 *
 * Rules, from llama.cpp src/llama-model.cpp (llama_model::load_tensors) and
 * common/arg.cpp:
 * - Input layer (token_embd, per_layer_token_embd, ...) always stays on the
 *   CPU ("very little benefit to offloading the input layer").
 * - With N GPU layers, repeating layers il >= n_layer + 1 - N go to the GPU;
 *   the output layer counts as layer n_layer, so it goes first. auto/all/-1
 *   = everything. Nothing goes to a GPU without one, or with `-dev none`.
 * - --cpu-moe keeps every tensor matching `\.ffn_(up|down|gate|gate_up)_(ch|)exps`
 *   on the CPU; --n-cpu-moe N only those of layers 0..N-1.
 * - GPU tensors are copied into device buffers: resident.
 * - CPU tensors are resident when the model is not memory-mapped or is
 *   mlocked (load_mode none/mlock/mmap+mlock/dio, --no-mmap, --mlock), or
 *   when the CPU backend repacks them into its own buffer (types below,
 *   matrix weights only, unless --no-repack). Otherwise they stay in the
 *   mapping: mapped.
 * - N-gram tables (per_layer_token_embd, embedded or the separate
 *   qwen4exp.ngram_file) are mapped when read lazily (lazy_mode auto/on,
 *   with mmap), resident when lazy_mode is off ("always keep them
 *   resident" — llama.cpp help, not yet checked against a run).
 *
 * Assumptions (cautious direction: they can only overstate resident):
 * - Repack depends on CPU features and tensor shapes; every repackable type
 *   off the input layer counts as repacked.
 * - `-ot/--override-tensor` regexes are not evaluated: with one present,
 *   every weight counts as resident and the result carries a note.
 * - Only the preset's own extra_flags are read, not the engine's.
 */
final readonly class WeightPlacement
{
	/**
	 * ggml types the CPU backend can repack (ggml/src/ggml-cpu/repack.cpp):
	 * Q4_0, Q8_0, Q2_K, Q4_K, Q5_K, Q6_K, IQ4_NL, MXFP4.
	 */
	public const REPACK_TYPES = [2, 8, 10, 12, 13, 14, 20, 39];

	/** llama.cpp common/common.h LLM_FFN_EXPS_REGEX. */
	public const EXPS_REGEX = '/\.ffn_(up|down|gate|gate_up)_(ch|)exps/';

	/** Input-layer tensor name prefixes (never offloaded). */
	private const INPUT_PREFIXES = ['token_embd.', 'per_layer_token_embd.', 'token_types.', 'position_embd.'];

	/** The n-gram table tensor of arch qwen4exp. */
	public const NGRAM_TENSOR = 'per_layer_token_embd.';

	public const OT_NOTE = 'Tensor overrides (-ot) are not modelled: all weights counted as resident.';

	/**
	 * @param int     $gpu            Weight bytes on GPUs.
	 * @param int     $cpu_resident   Weight bytes held in RAM on the CPU side.
	 * @param int     $mapped         Weight bytes left in the memory mapping.
	 * @param int     $ngrams         N-gram table bytes that are resident.
	 * @param int     $ngrams_mapped  N-gram table bytes that are mapped.
	 * @param ?string $note           Why placement fell back, or null.
	 * @param int     $read_per_token Mapped bytes one token reads when nothing is
	 *        cached: mapped repeating/output weights in full, mapped expert
	 *        tensors scaled by experts used / expert count; input-layer rows
	 *        (embeddings, n-gram tables) count as 0 (a few rows per token).
	 */
	public function __construct(
		public int $gpu,
		public int $cpu_resident,
		public int $mapped,
		public int $ngrams = 0,
		public int $ngrams_mapped = 0,
		public ?string $note = null,
		public int $read_per_token = 0,
	) {}

	/**
	 * @param list<array{name:string, dims:list<int>, type:int, bytes:int}> $tensors
	 *        Every tensor of the model (all shards), with bytes.
	 * @param ?NgramTable $ngrams  The model's n-gram table, if any; a
	 *        separate file of known size is added on the CPU side.
	 */
	public static function of(array $tensors, int $n_layer, Preset $preset, bool $has_gpu, ?NgramTable $ngrams = null, int $experts_used = 0, int $experts = 0): self
	{
		$flags = self::flags($preset->args->extra_flags);

		$resident_load = $flags['no_mmap'] || $flags['mlock']
			|| in_array($preset->args->load_mode, ['none', 'mlock', 'mmap+mlock', 'dio'], true);
		$lazy = $preset->args->lazy_mode !== 'off' && !$resident_load;

		$sep = $ngrams !== null && !$ngrams->embedded && $ngrams->file_bytes !== null ? $ngrams->file_bytes : 0;

		if ($flags['ot']) {
			$all = 0;
			foreach ($tensors as $t) {
				$all += (int)$t['bytes'];
			}
			return new self($all, 0, 0, $sep, 0, self::OT_NOTE);
		}

		$ngl       = $has_gpu && !$flags['dev_none'] ? self::gpuLayers($preset->args->n_gpu_layers, $n_layer) : 0;
		$gpu_start = max($n_layer + 1 - $ngl, 0);

		$gpu = $cpu = $mapped = $ng = $ng_mapped = $read = 0;
		$share = $experts > 0 && $experts_used > 0 ? min(1.0, $experts_used / $experts) : 1.0;
		foreach ($tensors as $t) {
			$name  = (string)$t['name'];
			$bytes = (int)$t['bytes'];
			$input = self::isInput($name);
			$layer = $input ? -1 : self::layerOf($name, $n_layer);

			$on_gpu = $ngl > 0 && !$input && $layer >= $gpu_start;
			if ($on_gpu && preg_match(self::EXPS_REGEX, $name) === 1
				&& ($flags['cpu_moe'] || $layer < $flags['n_cpu_moe'])) {
				$on_gpu = false;
			}

			if ($on_gpu) {
				$gpu += $bytes;
			}
			elseif (str_starts_with($name, self::NGRAM_TENSOR)) {
				if ($lazy) {
					$ng_mapped += $bytes;
				}
				else {
					$ng += $bytes;
				}
			}
			elseif ($resident_load
				|| (!$input && !$flags['no_repack'] && count((array)$t['dims']) >= 2
					&& in_array((int)$t['type'], self::REPACK_TYPES, true))) {
				$cpu += $bytes;
			}
			else {
				$mapped += $bytes;
				if (!$input) {
					$read += preg_match(self::EXPS_REGEX, $name) === 1 ? (int)ceil($bytes * $share) : $bytes;
				}
			}
		}

		return $lazy
			? new self($gpu, $cpu, $mapped, $ng, $ng_mapped + $sep, null, $read)
			: new self($gpu, $cpu, $mapped, $ng + $sep, $ng_mapped, null, $read);
	}

	/** Repeating layers + output on the GPU: "auto"/"all"/negative/garbage = all (n_layer + 1). */
	public static function gpuLayers(string $n_gpu_layers, int $n_layer): int
	{
		$v = trim($n_gpu_layers);
		if (!is_numeric($v) || (int)$v < 0) {
			return $n_layer + 1;
		}
		return min((int)$v, $n_layer + 1);
	}

	private static function isInput(string $name): bool
	{
		foreach (self::INPUT_PREFIXES as $p) {
			if (str_starts_with($name, $p)) {
				return true;
			}
		}
		return false;
	}

	/** blk.<n>.* -> n; every other non-input tensor belongs to the output layer (n_layer). */
	private static function layerOf(string $name, int $n_layer): int
	{
		return preg_match('/^blk\.(\d+)\./', $name, $m) === 1 ? (int)$m[1] : $n_layer;
	}

	/**
	 * The placement-relevant flags in extra_flags.
	 *
	 * @return array{dev_none:bool, cpu_moe:bool, n_cpu_moe:int, ot:bool, no_repack:bool, no_mmap:bool, mlock:bool}
	 */
	public static function flags(string $extra_flags): array
	{
		$out  = ['dev_none' => false, 'cpu_moe' => false, 'n_cpu_moe' => 0, 'ot' => false, 'no_repack' => false, 'no_mmap' => false, 'mlock' => false];
		$args = CommandBuilder::splitFlags($extra_flags);
		$n    = count($args);
		for ($i = 0; $i < $n; ++$i) {
			[$flag, $value] = str_contains($args[$i], '=') && str_starts_with($args[$i], '-')
				? explode('=', $args[$i], 2)
				: [$args[$i], null];
			$next = static function () use (&$i, $args, $value): string {
				return $value ?? (string)($args[++$i] ?? '');
			};
			match ($flag) {
				'-dev', '--device'           => $out['dev_none'] = strtolower($next()) === 'none',
				'-cmoe', '--cpu-moe'         => $out['cpu_moe'] = true,
				'-ncmoe', '--n-cpu-moe'      => $out['n_cpu_moe'] = max(0, (int)$next()),
				'-ot', '--override-tensor'   => $out['ot'] = $next() !== '',
				'-nr', '--no-repack'         => $out['no_repack'] = true,
				'--no-mmap'                  => $out['no_mmap'] = true,
				'--mlock'                    => $out['mlock'] = true,
				default                      => null,
			};
		}
		return $out;
	}
}
