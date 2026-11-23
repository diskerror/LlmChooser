<?php

declare(strict_types=1);

namespace LlmChooser\Model;

final readonly class ModelEntry
{
	/**
	 * @param string       $path        First (or only) file — the value for --model.
	 * @param string       $display_name Relative path under its root, without ".gguf"
	 *                                  and without the shard suffix.
	 * @param int          $size_bytes   Sum of all shard file sizes.
	 * @param list<string> $shard_paths  All shard files in order (one item if not sharded).
	 * @param array{mmproj: list<string>, draft: list<string>, ngrams: list<string>} $sidecars
	 * @param ?GgufInfo    $info        Null if the header could not be read.
	 * @param ?string      $error       Why the header could not be read, or shard set problem.
	 */
	public function __construct(
		public string $path,
		public string $display_name,
		public int $size_bytes,
		public array $shard_paths,
		public array $sidecars,
		public ?GgufInfo $info,
		public ?string $error,
	) {}

	/** The model's lazily loaded n-gram table, or null if it has none. */
	public function ngrams(): ?NgramTable
	{
		return NgramTable::of($this->path, $this->info);
	}

	public function isSharded(): bool
	{
		return count($this->shard_paths) > 1;
	}

	public function toArray(): array
	{
		$a = get_object_vars($this);

		$a['info']           = $this->info?->toArray();
		$a['architecture']   = $this->info?->architecture();
		$a['context_length'] = $this->info?->contextLength();

		return $a;
	}
}
