<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

final class Device
{
	public const KIND_RAM  = 'system_ram';
	public const KIND_VRAM = 'vram';

	public function __construct(
		public readonly string $id,              // "cpu", "gpu0", "gpu1", ...
		public readonly string $kind,            // KIND_RAM or KIND_VRAM
		public readonly int $total_bytes,
		public readonly int $available_bytes,
		public readonly ?string $unified_with = null, // "cpu" when this GPU shares system RAM
		// System RAM currently held by GPU drivers (amdgpu GTT). Set on the
		// 'cpu' device only. Not double-counted: the kernel already removes
		// it from MemAvailable — this field is for *explaining* used memory.
		public readonly int $driver_held_bytes = 0,
	) {}

	public function toArray(): array
	{
		return get_object_vars($this);
	}

	public static function fromArray(array $a): self
	{
		return new self(
			$a['id'], $a['kind'], (int)$a['total_bytes'], (int)$a['available_bytes'],
			$a['unified_with'] ?? null, (int)($a['driver_held_bytes'] ?? 0),
		);
	}
}
