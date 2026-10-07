<?php

declare(strict_types=1);

namespace LlmChooser\Model;

/**
 * Metadata read from a GGUF header. Plain value object, not a TypedClass:
 * it is produced by our own reader, never by outside input.
 */
final class GgufInfo
{
	/**
	 * @param array<string, mixed> $metadata Scalars, and numeric arrays of <= 4096 items.
	 * @param list<array{name:string, dims:list<int>, type:int}> $tensors
	 *        Per-tensor header info (name, shape, ggml type). Empty for
	 *        headers read before tensor capture existed (old cache entries).
	 */
	public function __construct(
		public readonly int $version,
		public readonly int $tensorCount,
		public readonly array $metadata,
		public readonly array $tensors = [],
	) {}

	public function get(string $key, mixed $default = null): mixed
	{
		return $this->metadata[$key] ?? $default;
	}

	public function architecture(): string
	{
		return (string)$this->get('general.architecture', '');
	}

	/** Reads "{arch}.{$suffix}". */
	public function archGet(string $suffix, mixed $default = null): mixed
	{
		return $this->get($this->architecture() . '.' . $suffix, $default);
	}

	public function blockCount(): int
	{
		return (int)$this->archGet('block_count', 0);
	}

	public function contextLength(): int
	{
		return (int)$this->archGet('context_length', 0);
	}

	public function embeddingLength(): int
	{
		return (int)$this->archGet('embedding_length', 0);
	}

	public function headCount(): int
	{
		return self::maxOf($this->archGet('attention.head_count', 0));
	}

	public function headCountKv(): int
	{
		$v = $this->archGet('attention.head_count_kv');
		return $v === null ? $this->headCount() : self::maxOf($v);
	}

	public function keyLength(): int
	{
		$v = $this->archGet('attention.key_length');
		if ($v !== null) {
			return (int)$v;
		}
		$heads = $this->headCount();
		return $heads > 0 ? intdiv($this->embeddingLength(), $heads) : 0;
	}

	public function valueLength(): int
	{
		$v = $this->archGet('attention.value_length');
		return $v !== null ? (int)$v : $this->keyLength();
	}

	public function splitCount(): int
	{
		return max(1, (int)$this->get('split.count', 1));
	}

	/** e.g. "30B-A3B", or "" if the metadata doesn't have it. */
	public function sizeLabel(): string
	{
		return (string)$this->get('general.size_label', '');
	}

	/**
	 * Human quant name from general.file_type, e.g. "Q5_K_M". Unknown codes
	 * fall back to the raw int as a string.
	 *
	 * Mapping transcribed from llama.cpp gguf-py/gguf/constants.py
	 * class LlamaFileType (local checkout at CLionProjects/llama.cpp):
	 * MOSTLY_* codes keep their suffix as the display name; 0 is "F32".
	 * Codes 4-6 and 33-35 are deprecated/removed there and deliberately
	 * omitted (they fall back to the raw int).
	 */
	public function fileTypeName(): string
	{
		$code = (int)$this->get('general.file_type', -1);
		$name = self::FILE_TYPE_NAMES[$code] ?? null;
		return $name !== null ? $name : (string)$code;
	}

	/** @var array<int,string> */
	private const FILE_TYPE_NAMES = [
		0  => 'F32',     1  => 'F16',     2  => 'Q4_0',    3  => 'Q4_1',
		7  => 'Q8_0',    8  => 'Q5_0',    9  => 'Q5_1',    10 => 'Q2_K',
		11 => 'Q3_K_S',  12 => 'Q3_K_M',  13 => 'Q3_K_L',  14 => 'Q4_K_S',
		15 => 'Q4_K_M',  16 => 'Q5_K_S',  17 => 'Q5_K_M',  18 => 'Q6_K',
		19 => 'IQ2_XXS', 20 => 'IQ2_XS',  21 => 'Q2_K_S',  22 => 'IQ3_XS',
		23 => 'IQ3_XXS', 24 => 'IQ1_S',   25 => 'IQ4_NL',  26 => 'IQ3_S',
		27 => 'IQ3_M',   28 => 'IQ2_S',   29 => 'IQ2_M',   30 => 'IQ4_XS',
		31 => 'IQ1_M',   32 => 'BF16',    36 => 'TQ1_0',   37 => 'TQ2_0',
		38 => 'MXFP4_MOE', 39 => 'NVFP4', 40 => 'Q1_0',    41 => 'Q2_0',
	];

	/** True if any RoPE-scaling metadata is present (YaRN/linear/etc.) — the ★ marker in §5. */
	public function hasRopeScaling(): bool
	{
		return $this->archGet('rope.scaling.type') !== null;
	}

	/** Mixture-of-experts: total experts, or 0 if this is not an MoE model. */
	public function expertCount(): int
	{
		return (int)$this->archGet('expert_count', 0);
	}

	/** Active experts per token for MoE models, or 0 if not MoE. */
	public function expertUsedCount(): int
	{
		return (int)$this->archGet('expert_used_count', 0);
	}

	public function ropeFreqBase(): float
	{
		return (float)$this->archGet('rope.freq_base', 10000.0);
	}

	public function vocabSize(): int
	{
		$tokens = $this->get('tokenizer.ggml.tokens', []);
		return is_array($tokens) && $tokens !== []
			? count($tokens)
			: (int)$this->archGet('vocab_size', 0);
	}

	/** True if a chat template is embedded in the file. */
	public function hasChatTemplate(): bool
	{
		return $this->get('tokenizer.chat_template') !== null;
	}

	/** First line of the chat template string, for display, or "" if absent. */
	public function chatTemplateFirstLine(): string
	{
		$t = (string)$this->get('tokenizer.chat_template', '');
		if ($t === '') {
			return '';
		}
		$nl = strpos($t, "\n");
		return $nl === false ? substr($t, 0, 80) : substr($t, 0, $nl);
	}

	/**
	 * Approximate total parameter count: sum of element counts across all
	 * tensor shapes (tensor info only — weights are never read). Label it
	 * "~" in the UI; norm/bias tensors are included, which llama.cpp's own
	 * reporting also does at this level of approximation.
	 */
	public function approxParamCount(): int
	{
		$sum = 0;
		foreach ($this->tensors as $t) {
			$n = 1;
			foreach ((array)$t['dims'] as $d) {
				$n *= (int)$d;
			}
			$sum += $n;
		}
		return $sum;
	}

	public function toArray(): array
	{
		return [
			'version'      => $this->version,
			'tensor_count' => $this->tensorCount,
			'metadata'     => $this->metadata,
			'tensors'      => $this->tensors,
		];
	}

	public static function fromArray(array $a): self
	{
		return new self(
			(int)$a['version'],
			(int)$a['tensor_count'],
			(array)$a['metadata'],
			(array)($a['tensors'] ?? []), // absent in cache entries written before tensor capture
		);
	}

	private static function maxOf(mixed $v): int
	{
		if (is_array($v)) {
			return $v === [] ? 0 : (int)max($v);
		}
		return (int)$v;
	}
}
