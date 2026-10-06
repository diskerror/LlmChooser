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
	 */
	public function __construct(
		public readonly int $version,
		public readonly int $tensorCount,
		public readonly array $metadata,
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

	public function toArray(): array
	{
		return [
			'version'      => $this->version,
			'tensor_count' => $this->tensorCount,
			'metadata'     => $this->metadata,
		];
	}

	public static function fromArray(array $a): self
	{
		return new self((int)$a['version'], (int)$a['tensor_count'], (array)$a['metadata']);
	}

	private static function maxOf(mixed $v): int
	{
		if (is_array($v)) {
			return $v === [] ? 0 : (int)max($v);
		}
		return (int)$v;
	}
}
