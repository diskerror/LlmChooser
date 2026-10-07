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
		public readonly int $totalBytes,
		public readonly int $availableBytes,
		public readonly ?string $unifiedWith = null, // "cpu" when this GPU shares system RAM
		// System RAM currently held by GPU drivers (amdgpu GTT). Set on the
		// 'cpu' device only. Not double-counted: the kernel already removes
		// it from MemAvailable — this field is for *explaining* used memory.
		public readonly int $driverHeldBytes = 0,
	) {}

	public function toArray(): array
	{
		return [
			'id' => $this->id, 'kind' => $this->kind, 'total_bytes' => $this->totalBytes,
			'available_bytes' => $this->availableBytes, 'unified_with' => $this->unifiedWith,
			'driver_held_bytes' => $this->driverHeldBytes,
		];
	}

	public static function fromArray(array $a): self
	{
		return new self(
			$a['id'], $a['kind'], (int)$a['total_bytes'], (int)$a['available_bytes'],
			$a['unified_with'] ?? null, (int)($a['driver_held_bytes'] ?? 0),
		);
	}
}
