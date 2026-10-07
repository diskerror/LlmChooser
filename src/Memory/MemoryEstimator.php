<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

use LlmChooser\Model\GgufInfo;
use LlmChooser\Model\ModelFiles;
use LlmChooser\Preset\Preset;

/**
 * Estimates the memory an instance will need and which devices carry it.
 *
 * Deliberate corrections to DESIGN.md §6:
 * - KV is NOT multiplied by parallel: --ctx-size is the TOTAL context
 *   shared by all slots in llama-server.
 * - Hybrid models (full_attention_interval, e.g. Qwen3-Next) only keep a
 *   KV cache on every N-th layer; only those layers are counted.
 * - Weights are the total on-disk size of all shards; we do not parse the
 *   tensor table. This is within a few MB of the tensor sum.
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

	public function estimate(Preset $preset, GgufInfo $info, MemoryTopology $topology): Allocation
	{
		$breakdown = $this->breakdown($preset, $info);
		$total     = array_sum($breakdown);

		return new Allocation($this->perDevice($preset, $info, $topology, $total), $breakdown);
	}

	/**
	 * KV cache bytes per 1000 tokens of context, for one cache type
	 * (same type for K and V — the UI's f16/q8_0/q4_0 comparison rows).
	 *
	 * Hybrid models (full_attention_interval) only pay KV on every
	 * interval-th layer, same correction as breakdown(). MLA-style models
	 * where K/V dims diverge sum both sides.
	 */
	public function kvBytesPer1k(GgufInfo $info, string $cacheType): int
	{
		$blocks = $info->blockCount();
		if ($blocks <= 0) {
			return 0;
		}
		$interval = (int)$info->archGet('full_attention_interval', 0);
		$kvLayers = $interval > 1 ? intdiv($blocks, $interval) : $blocks;

		$b = self::KV_BYTES[strtolower($cacheType)] ?? 2.0;
		$kDim = $info->headCountKv() * $info->keyLength();
		$vDim = $info->headCountKv() * $info->valueLength();

		return (int)ceil(1000 * $kvLayers * ($kDim + $vDim) * $b);
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
		if ($blocks <= 0) {
			$kv = 0;
		} else {
			// Hybrid attention: only every interval-th layer keeps KV.
			$interval = (int)$info->archGet('full_attention_interval', 0);
			$kvLayers = $interval > 1 ? intdiv($blocks, $interval) : $blocks;

			$ctx = $preset->args->ctx_size > 0 ? $preset->args->ctx_size : $info->contextLength();

			$kDim = $info->headCountKv() * $info->keyLength();
			$vDim = $info->headCountKv() * $info->valueLength();
			$bk   = self::KV_BYTES[strtolower($preset->args->cache_type_k)] ?? 2.0;
			$bv   = self::KV_BYTES[strtolower($preset->args->cache_type_v)] ?? 2.0;

			// NOT multiplied by parallel: slots share the total context.
			$kv = (int)ceil($kvLayers * $ctx * ($kDim * $bk + $vDim * $bv));
		}

		$overhead = self::OVERHEAD_FIXED_BYTES + $preset->args->ubatch_size * $info->embeddingLength() * 4;

		return [
			'weights'  => $weights,
			'kv_cache' => $kv,
			'overhead' => $overhead,
			'mmproj'   => $mmproj,
			'draft'    => $draft,
		];
	}

	/**
	 * Which devices carry how many bytes. Unified aliasing happens in
	 * AdmissionCheck, not here — this is the physical view.
	 *
	 * @return array<string,int>
	 */
	private function perDevice(Preset $preset, GgufInfo $info, MemoryTopology $topology, int $total): array
	{
		$f = $this->gpuFraction($preset->args->n_gpu_layers, $info->blockCount());

		$gpus = $topology->gpuIds();
		if ($gpus === []) {
			return ['cpu' => $total];
		}

		$gpuPart = (int)round($total * $f);
		$cpuPart = $total - $gpuPart;

		$perDevice = $gpuPart > 0
			? $this->splitGpus($preset, $topology, $gpus, $gpuPart)
			: [];

		if ($cpuPart > 0) {
			$perDevice['cpu'] = ($perDevice['cpu'] ?? 0) + $cpuPart;
		}

		return $perDevice;
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
	private function splitGpus(Preset $preset, MemoryTopology $topology, array $gpus, int $gpuPart): array
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
		// parts sum exactly to $gpuPart.
		$out   = [];
		$given = 0;
		$first = array_key_first($shares);
		foreach ($shares as $gpu => $share) {
			$bytes = (int)floor($gpuPart * $share);
			$out[$gpu] = $bytes;
			$given += $bytes;
		}
		$out[$first] += $gpuPart - $given;

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
