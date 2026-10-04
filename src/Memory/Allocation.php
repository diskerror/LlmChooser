<?php

declare(strict_types=1);

namespace LLMchooser\Memory;

/** Estimated bytes needed, per device id (before unified aliasing). */
final class Allocation
{
	/**
	 * @param array<string,int> $perDevice  e.g. ["gpu0" => 40e9, "cpu" => 2e9]
	 * @param array<string,int> $breakdown  weights, kv_cache, overhead, mmproj, draft
	 */
	public function __construct(
		public readonly array $perDevice,
		public readonly array $breakdown,
	) {}

	public function totalBytes(): int
	{
		return array_sum($this->perDevice);
	}

	public function toArray(): array
	{
		return ['per_device' => $this->perDevice, 'breakdown' => $this->breakdown];
	}

	public static function fromArray(array $a): self
	{
		return new self(array_map('intval', $a['per_device']), array_map('intval', $a['breakdown']));
	}
}
