<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

use InvalidArgumentException;

final class MemoryTopology
{
	/** @var array<string, Device> keyed by id */
	public readonly array $devices;

	/** @param list<Device> $devices */
	public function __construct(array $devices)
	{
		$by_id = [];
		foreach ($devices as $d) {
			$by_id[$d->id] = $d;
		}
		if (!isset($by_id['cpu'])) {
			throw new InvalidArgumentException('Topology must contain a "cpu" device.');
		}
		$this->devices = $by_id;
	}

	/** The budget device id for $id: follows unified_with, so unified GPUs map to "cpu". */
	public function budgetId(string $id): string
	{
		$d = $this->devices[$id] ?? null;
		return ($d !== null && $d->unified_with !== null) ? $d->unified_with : $id;
	}

	/** GPU ids in order gpu0, gpu1, ... */
	public function gpuIds(): array
	{
		$ids = array_keys(array_filter($this->devices, fn(Device $d) => $d->kind === Device::KIND_VRAM));
		natsort($ids);
		return array_values($ids);
	}

	/** Display name: the budget device of a unified-memory box is an "APU". */
	public function label(string $id): string
	{
		if ($id === 'cpu') {
			foreach ($this->gpuIds() as $g) {
				if ($this->devices[$g]->unified_with === 'cpu') {
					return 'apu';
				}
			}
		}
		return $id;
	}

	public function isUnified(): bool
	{
		foreach ($this->gpuIds() as $id) {
			if ($this->devices[$id]->unified_with === null) {
				return false;
			}
		}
		return true;
	}

	public function toArray(): array
	{
		return array_values(array_map(fn(Device $d) => $d->toArray(), $this->devices));
	}

	public static function fromArray(array $list): self
	{
		return new self(array_map([Device::class, 'fromArray'], $list));
	}
}
