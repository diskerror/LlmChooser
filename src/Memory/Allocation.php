<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

/**
 * Estimated bytes needed, per device id (before unified aliasing), plus the
 * memory-mapped part that does not need to fit.
 */
final readonly class Allocation
{
	/**
	 * @param array<string,int> $per_device  Resident bytes, e.g. ["gpu0" => 40e9, "cpu" => 2e9]
	 * @param array<string,int> $breakdown   Resident parts: weights, kv_cache, overhead, mmproj, draft, ...
	 * @param array<string,int> $mapped      Memory-mapped parts (page cache, read on demand):
	 *                                       weights, ngrams. Not in per_device or totalBytes().
	 * @param list<string>      $notes       Caveats about the estimate (e.g. placement not modelled).
	 * @param int               $read_per_token Mapped bytes one token reads if none are cached
	 *                                       (speed hint; see WeightPlacement).
	 */
	public function __construct(
		public array $per_device,
		public array $breakdown,
		public array $mapped = [],
		public array $notes = [],
		public int $read_per_token = 0,
	) {}

	/** Resident bytes: what admission checks. */
	public function totalBytes(): int
	{
		return array_sum($this->per_device);
	}

	/** Memory-mapped bytes: never block admission, decide speed. */
	public function mappedBytes(): int
	{
		return array_sum($this->mapped);
	}

	public function toArray(): array
	{
		return get_object_vars($this);
	}

	/** Instance rows written before the mapped split have no mapped/notes keys. */
	public static function fromArray(array $a): self
	{
		return new self(
			array_map('intval', $a['per_device']),
			array_map('intval', $a['breakdown']),
			array_map('intval', (array)($a['mapped'] ?? [])),
			array_values(array_map('strval', (array)($a['notes'] ?? []))),
			(int)($a['read_per_token'] ?? 0),
		);
	}
}
