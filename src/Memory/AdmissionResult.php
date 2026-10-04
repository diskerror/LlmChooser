<?php

declare(strict_types=1);

namespace LLMchooser\Memory;

final class AdmissionResult
{
	/**
	 * @param array<string, array{need:int, already:int, available:int}> $perBudget
	 *        keyed by budget device id (after unified aliasing)
	 */
	public function __construct(
		public readonly bool $fits,
		public readonly array $perBudget,
	) {}

	/** Human-readable explanation, one line per budget device. */
	public function message(): string
	{
		$lines = [];
		foreach ($this->perBudget as $id => $r) {
			$ok      = $r['need'] <= $r['available'];
			$lines[] = sprintf(
				'%s %s: need %.1f GB + already running %.1f GB vs available %.1f GB',
				$ok ? 'OK  ' : 'FAIL',
				$id,
				$r['need'] / 1e9,
				$r['already'] / 1e9,
				$r['available'] / 1e9
			);
		}
		return implode("\n", $lines);
	}
}
