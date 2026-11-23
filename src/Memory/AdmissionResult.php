<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

use LlmChooser\Cli\Format;

final readonly class AdmissionResult
{
	/**
	 * @param array<string, array{need:int, already:int, available:int}> $per_budget
	 *        keyed by budget device id (after unified aliasing)
	 */
	public function __construct(
		public bool $fits,
		public array $per_budget,
	) {}

	/** Human-readable explanation, one line per budget device. */
	public function message(): string
	{
		$lines = [];
		foreach ($this->per_budget as $id => $r) {
			$ok      = $r['need'] <= $r['available'];
			$lines[] = sprintf(
				'%s %s: need %s + already running %s vs available %s',
				$ok ? 'OK  ' : 'FAIL',
				$id,
				Format::humanBytes($r['need']),
				Format::humanBytes($r['already']),
				Format::humanBytes($r['available'])
			);
		}
		return implode("\n", $lines);
	}
}
