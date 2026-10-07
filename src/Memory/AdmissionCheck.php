<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

final class AdmissionCheck
{
	/**
	 * Does $new fit alongside $running?
	 *
	 * Unified aliasing happens here: on a box whose GPU shares system RAM
	 * (a unified-memory APU host), every per-GPU number folds into the
	 * single "cpu" budget, so the whole thing collapses to one comparison.
	 *
	 * The comparison is `need <= available` WITHOUT adding what is already
	 * running, because MemAvailable already reflects the memory live
	 * processes hold. Adding it again would double-count and refuse models
	 * that fit. `already` is summed for reporting only.
	 *
	 * A newly started instance may not have loaded its weights yet, so its
	 * contribution to "used" can lag. Accepted as estimate error, not
	 * corrected for.
	 *
	 * @param list<Allocation> $running
	 */
	public function check(Allocation $new, array $running, MemoryTopology $topology): AdmissionResult
	{
		$runningByBudget = [];
		foreach ($running as $alloc) {
			foreach ($alloc->perDevice as $device => $bytes) {
				$budget = $topology->budgetId($device);
				$runningByBudget[$budget] = ($runningByBudget[$budget] ?? 0) + $bytes;
			}
		}

		$perBudget = [];
		$fits      = true;
		foreach ($new->perDevice as $device => $bytes) {
			$budget = $topology->budgetId($device);
			if (!isset($perBudget[$budget])) {
				$device_available = $topology->devices[$budget] ?? null;
				$perBudget[$budget] = [
					'need'      => 0,
					'already'   => $runningByBudget[$budget] ?? 0,
					// An id we know nothing about gets no budget, so it fails
					// loudly instead of passing on a guess.
					'available' => $device_available?->availableBytes ?? 0,
				];
			}
			$perBudget[$budget]['need'] += $bytes;
		}

		foreach ($perBudget as $budget => $row) {
			if ($row['need'] > $row['available']) {
				$fits = false;
			}
		}

		return new AdmissionResult($fits, $perBudget);
	}
}
