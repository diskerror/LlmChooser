<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

use LlmChooser\Model\GgufInfo;
use LlmChooser\Model\GgufReader;
use LlmChooser\Model\ModelFiles;
use LlmChooser\Model\NgramTable;
use LlmChooser\Preset\Preset;

/**
 * Estimates the memory an instance will need and which devices carry it.
 *
 * Deliberate corrections to DESIGN.md §6:
 * - KV is NOT multiplied by parallel: --ctx-size is the TOTAL context
 *   shared by all slots in llama-server.
 * - Hybrid models (full_attention_interval, e.g. Qwen3-Next) only keep a
 *   KV cache on every N-th layer; only those layers are counted.
 * - Weights come from the tensor table (sizes from data offsets, all
 *   shards) and are placed per tensor by WeightPlacement: GPU and
 *   repacked/non-mmap CPU tensors are resident, mmap'd CPU tensors and lazy
 *   n-gram tables are "mapped" (page cache) and kept out of the resident
 *   total. Without tensor sizes (old/odd headers) weights fall back to the
 *   on-disk size of all shards, all resident.
 */
final class MemoryEstimator
{
	/**
	 * Bytes per KV element for each cache type, i.e. sizeof(block)/32
	 * from llama.cpp ggml/src/ggml-common.h (e.g. block_q8_0 = 2 + 32 =
	 * 34 bytes/32 elems, block_q4_0 = 2 + 16 = 18/32, block_q5_0 =
	 * 2 + 4 + 16 = 22/32; local checkout at CLionProjects/llama.cpp).
	 */
	public const KV_BYTES = [
		'f32'    => 4.0, 'f16' => 2.0, 'bf16' => 2.0,
		'q8_0'   => 34 / 32, 'q4_0' => 18 / 32, 'q4_1' => 20 / 32,
		'iq4_nl' => 18 / 32, 'q5_0' => 22 / 32, 'q5_1' => 24 / 32,
	];

	/** Fixed compute/scratch overhead per instance (deliberately cautious). */
	public const OVERHEAD_FIXED_BYTES = 1024 ** 3;

	/**
	 * Empirical compute/scratch buffer per ubatch token, from llama.cpp
	 * --verbose logs (Qwen3.8-Flash-Next, 512-expert MoE). The graph
	 * allocator's size is not derivable from GGUF metadata, so these are
	 * calibration constants. Dense models keep the older, smaller formula.
	 *
	 * MOE: main-context Vulkan 3345 + host 402 MiB @ ubatch 512 with f32
	 * KV cache (f16 KV measures lower, ~6.05 M; the f32 value is kept as a
	 * deliberate upper bound — underestimates are what bite on admission).
	 * DRAFT: MTP draft-context Vulkan 361 + host 281 MiB @ 512 and
	 * 90 + 70 MiB @ 128 — exactly linear at ~1.315 M/token once the host
	 * buffer is included (the old 740k was Vulkan-only and 44% low).
	 */
	public const MOE_COMPUTE_BYTES_PER_UBATCH_TOKEN   = 7_677_000;
	public const DRAFT_COMPUTE_BYTES_PER_UBATCH_TOKEN = 1_315_000;

	public function estimate(Preset $preset, GgufInfo $info, MemoryTopology $topology): Allocation
	{
		$breakdown = $this->breakdown($preset, $info);
		$placement = $this->placement($preset, $info, $topology->gpuIds() !== []);

		$mapped = [];
		$notes  = [];
		if ($placement !== null) {
			$breakdown['weights'] = $placement->gpu + $placement->cpu_resident;
			if ($placement->ngrams > 0) {
				$breakdown['ngrams'] = $placement->ngrams;
			}
			$mapped = array_filter(
				['weights' => $placement->mapped, 'ngrams' => $placement->ngrams_mapped],
				static fn(int $b): bool => $b > 0,
			);
			if ($placement->note !== null) {
				$notes[] = $placement->note;
			}
		}

		$total = array_sum($breakdown);
		$fixed = $placement === null ? null : [$placement->gpu, $placement->cpu_resident + $placement->ngrams];

		return new Allocation($this->perDevice($preset, $info, $topology, $total, $fixed), $breakdown, $mapped, $notes, $placement?->read_per_token ?? 0);
	}

	/**
	 * Per-tensor weight placement, or null when the tensor sizes are not
	 * known for every shard (then weights stay the all-resident file size).
	 */
	private function placement(Preset $preset, GgufInfo $info, bool $has_gpu): ?WeightPlacement
	{
		if (!$info->hasTensorBytes()) {
			return null;
		}
		$tensors = $info->tensors;
		$shards  = ModelFiles::shardPaths($preset->model_path);
		if (count($shards) > 1) {
			$tensors = [];
			foreach ($shards as $path) {
				try {
					$shard = $path === $preset->model_path ? $info : GgufReader::read($path);
				} catch (\Throwable) {
					return null;
				}
				if (!$shard->hasTensorBytes()) {
					return null;
				}
				array_push($tensors, ...$shard->tensors);
			}
		}
		return WeightPlacement::of($tensors, $info->blockCount(), $preset, $has_gpu, NgramTable::of($preset->model_path, $info), $info->expertUsedCount(), $info->expertCount());
	}

	/**
	 * KV cache bytes per 1000 tokens of context, for one cache type
	 * (same type for K and V — the UI's f16/q8_0/q4_0 comparison rows).
	 *
	 * Hybrid models (full_attention_interval) only pay KV on every
	 * interval-th layer, same correction as breakdown(). MLA-style models
	 * where K/V dims diverge sum both sides.
	 */
	public function kvBytesPer1k(GgufInfo $info, string $cache_type): int
	{
		$blocks = $info->blockCount();
		if ($blocks <= 0) {
			return 0;
		}
		$interval = (int)$info->archGet('full_attention_interval', 0);
		$kv_layers = $interval > 1 ? intdiv($blocks, $interval) : $blocks;

		$b = self::KV_BYTES[strtolower($cache_type)] ?? 2.0;
		$k_dim = $info->headCountKv() * $info->keyLength();
		$v_dim = $info->headCountKv() * $info->valueLength();

		return (int)ceil(1000 * $kv_layers * ($k_dim + $v_dim) * $b);
	}

	/**
	 * @return array<string,int>
	 */
	private function breakdown(Preset $preset, GgufInfo $info): array
	{
		$weights = ModelFiles::totalBytes($preset->model_path);
		$mmproj  = $preset->mmproj_path !== null ? ModelFiles::totalBytes($preset->mmproj_path) : 0;
		$draft   = $preset->model_draft_path !== null ? ModelFiles::totalBytes($preset->model_draft_path) : 0;

		$blocks = $info->blockCount();
		$ctx    = $preset->args->ctx_size > 0 ? $preset->args->ctx_size : $info->contextLength();
		$bk     = self::KV_BYTES[strtolower($preset->args->cache_type_k)] ?? 2.0;
		$bv     = self::KV_BYTES[strtolower($preset->args->cache_type_v)] ?? 2.0;
		$kv = $indexer = $recurrent = 0;
		$kv_layers = 0;
		if ($blocks > 0) {
			// Hybrid attention: only every interval-th layer keeps KV.
			$interval = (int)$info->archGet('full_attention_interval', 0);
			$kv_layers = $interval > 1 ? intdiv($blocks, $interval) : $blocks;

			$k_dim = $info->headCountKv() * $info->keyLength();
			$v_dim = $info->headCountKv() * $info->valueLength();

			// NOT multiplied by parallel: slots share the total context.
			$kv = (int)ceil($kv_layers * $ctx * ($k_dim * $bk + $v_dim * $bv));

			// Compressed-attention models (compress_ratios) keep a second,
			// per-ctx cache on the compressed layers: indexer-K plus V, one
			// head each. Verified exact against llama.cpp's log (4608 MiB).
			$ratios   = $info->archGet('attention.compress_ratios');
			$idx_key   = (int)$info->archGet('attention.indexer.key_length', 0);
			if (is_array($ratios) && $idx_key > 0) {
				$compressed = count(array_filter($ratios, static fn($r): bool => (int)$r > 0));
				$indexer    = (int)ceil($compressed * $ctx * ($idx_key * $bk + $info->valueLength() * $bv));
			}

			// Recurrent (SSM) layers: fp32 conv + state per sequence, plus
			// n_rs_seq extra snapshots for MTP speculation (= draft max).
			$inner = (int)$info->archGet('ssm.inner_size', 0);
			$state = (int)$info->archGet('ssm.state_size', 0);
			if ($inner > 0 && $state > 0) {
				$groups  = (int)$info->archGet('ssm.group_count', 0);
				$kernel  = (int)$info->archGet('ssm.conv_kernel', 4);
				$per_seq  = 4 * ($inner * $state + max(0, $kernel - 1) * ($inner + 2 * $groups * $state));
				$extra   = str_contains($preset->args->spec_type, 'mtp') ? $preset->args->spec_draft_n_max : 0;
				$recurrent = (int)ceil(($blocks - $kv_layers) * $per_seq * (max(1, $preset->args->parallel) + $extra));
			}
		}

		// Compute buffer: see the calibration constants above.
		$ub = $preset->args->ubatch_size;
		$overhead = $info->expertCount() > 0
			? $ub * self::MOE_COMPUTE_BYTES_PER_UBATCH_TOKEN
			: self::OVERHEAD_FIXED_BYTES + $ub * $info->embeddingLength() * 4;

		// Draft model: its own context at the same n_ctx, f16 KV (llama-server
		// default for the draft), plus its compute buffer. An MTP head has
		// nextn_predict_layers layers; a normal draft model all of its own.
		$draft_kv = $draft_compute = 0;
		if ($draft > 0) {
			$d = null;
			try {
				$d = GgufReader::read((string)$preset->model_draft_path);
			} catch (\Throwable) {
			}
			if ($d !== null) {
				$layers = (int)$d->archGet('nextn_predict_layers', 0);
				if ($layers <= 0) {
					$di     = (int)$d->archGet('full_attention_interval', 0);
					$layers = $di > 1 ? intdiv($d->blockCount(), $di) : $d->blockCount();
				}
				$dctx    = $ctx > 0 ? $ctx : $d->contextLength();
				$draft_kv = (int)ceil($layers * $dctx * ($d->headCountKv() * ($d->keyLength() + $d->valueLength())) * 2.0);
			}
			$draft_compute = $ub * self::DRAFT_COMPUTE_BYTES_PER_UBATCH_TOKEN;
		}

		return [
			'weights'  => $weights,
			'kv_cache' => $kv,
			'kv_indexer' => $indexer,
			'recurrent'  => $recurrent,
			'overhead' => $overhead,
			'mmproj'   => $mmproj,
			'draft'    => $draft,
			'draft_kv' => $draft_kv,
			'draft_compute' => $draft_compute,
		];
	}

	/**
	 * Which devices carry how many bytes. Unified aliasing happens in
	 * AdmissionCheck, not here — this is the physical view.
	 *
	 * @param ?array{0:int,1:int} $fixed Resident weight bytes [gpu, cpu] from
	 *        WeightPlacement, or null to split everything by layer fraction.
	 *
	 * @return array<string,int>
	 */
	private function perDevice(Preset $preset, GgufInfo $info, MemoryTopology $topology, int $total, ?array $fixed = null): array
	{
		$f = WeightPlacement::flags($preset->args->extra_flags)['dev_none']
			? 0.0
			: $this->gpuFraction($preset->args->n_gpu_layers, $info->blockCount());

		$gpus = $topology->gpuIds();
		if ($gpus === []) {
			return $total > 0 ? ['cpu' => $total] : [];
		}

		// With per-tensor placement, resident weights go where the tensors
		// are; the rest (KV, buffers, draft, mmproj) splits by layer fraction.
		[$gpu_weights, $cpu_weights] = $fixed ?? [0, 0];
		$gpu_part = $gpu_weights + (int)round(($total - $gpu_weights - $cpu_weights) * $f);
		$cpu_part = $total - $gpu_part;

		$per_device = $gpu_part > 0
			? $this->splitGpus($preset, $topology, $gpus, $gpu_part)
			: [];

		if ($cpu_part > 0) {
			$per_device['cpu'] = ($per_device['cpu'] ?? 0) + $cpu_part;
		}

		return $per_device;
	}

	private function gpuFraction(string $n_gpu_layers, int $blocks): float
	{
		$v = trim($n_gpu_layers);
		if ($v === 'auto' || $v === 'all') {
			return 1.0;
		}
		if (!is_numeric($v)) {
			return 1.0;
		}
		$n = (float)$v;
		if ($n < 0) {
			return 1.0;
		}
		if ($n === 0.0) {
			return 0.0;
		}
		return min($n, (float)$blocks) / max((float)$blocks, 1.0);
	}

	/**
	 * @param list<string> $gpus
	 * @return array<string,int>
	 */
	private function splitGpus(Preset $preset, MemoryTopology $topology, array $gpus, int $gpu_part): array
	{
		$shares = $this->tensorShares($preset->args->tensor_split, $gpus);

		if ($shares === null) {
			if ($preset->args->split_mode === 'none' || count($gpus) === 1) {
				$target = 'gpu' . $preset->args->main_gpu;
				if (!in_array($target, $gpus, true)) {
					$target = $gpus[0];
				}
				$shares = [$target => 1.0];
			} else {
				$even = 1.0 / count($gpus);
				$shares = [];
				foreach ($gpus as $g) {
					$shares[$g] = $even;
				}
			}
		}

		// Integer split; hand the rounding remainder to the first GPU so the
		// parts sum exactly to $gpu_part.
		$out   = [];
		$given = 0;
		$first = array_key_first($shares);
		foreach ($shares as $gpu => $share) {
			$bytes = (int)floor($gpu_part * $share);
			$out[$gpu] = $bytes;
			$given += $bytes;
		}
		$out[$first] += $gpu_part - $given;

		return array_filter($out, static fn(int $b): bool => $b > 0);
	}

	/**
	 * "3,1" -> gpu0 0.75, gpu1 0.25. Ratios naming GPUs that do not exist are
	 * dropped and the rest renormalised. Null when unusable (empty, all zero,
	 * no index matches a real GPU, or malformed).
	 *
	 * @param list<string> $gpus
	 * @return ?array<string,float>
	 */
	private function tensorShares(string $split, array $gpus): ?array
	{
		$v = trim($split);
		if ($v === '') {
			return null;
		}

		$parts = preg_split('/\s*,\s*/', $v) ?: [];
		$sum   = 0.0;
		$shares = [];
		foreach ($parts as $i => $part) {
			if ($i >= count($gpus) || !is_numeric($part)) {
				continue;
			}
			$r = (float)$part;
			if ($r < 0) {
				return null; // a negative share is malformed, not meaningful
			}
			$shares[$gpus[$i]] = $r;
			$sum += $r;
		}

		if ($shares === [] || $sum <= 0.0) {
			return null;
		}

		return array_map(static fn(float $r): float => $r / $sum, $shares);
	}
}
