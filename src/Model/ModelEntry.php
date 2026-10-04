<?php

declare(strict_types=1);

namespace LLMchooser\Model;

final class ModelEntry
{
	/**
	 * @param string       $path        First (or only) file — the value for --model.
	 * @param string       $displayName Relative path under its root, without ".gguf"
	 *                                  and without the shard suffix.
	 * @param int          $sizeBytes   Sum of all shard file sizes.
	 * @param list<string> $shardPaths  All shard files in order (one item if not sharded).
	 * @param array{mmproj: list<string>, draft: list<string>, ngrams: list<string>} $sidecars
	 * @param ?GgufInfo    $info        Null if the header could not be read.
	 * @param ?string      $error       Why the header could not be read, or shard set problem.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $displayName,
		public readonly int $sizeBytes,
		public readonly array $shardPaths,
		public readonly array $sidecars,
		public readonly ?GgufInfo $info,
		public readonly ?string $error,
	) {}

	public function isSharded(): bool
	{
		return count($this->shardPaths) > 1;
	}

	public function toArray(): array
	{
		return [
			'path'         => $this->path,
			'display_name' => $this->displayName,
			'size_bytes'   => $this->sizeBytes,
			'shard_paths'  => $this->shardPaths,
			'sidecars'     => $this->sidecars,
			'architecture' => $this->info?->architecture(),
			'context_length' => $this->info?->contextLength(),
			'error'        => $this->error,
		];
	}
}
