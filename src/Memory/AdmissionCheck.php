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
		$running_by_budget = [];
		foreach ($running as $alloc) {
			foreach ($alloc->per_device as $device => $bytes) {
				$budget = $topology->budgetId($device);
				$running_by_budget[$budget] = ($running_by_budget[$budget] ?? 0) + $bytes;
			}
		}

		$per_budget = [];
		$fits      = true;
		foreach ($new->per_device as $device => $bytes) {
			$budget = $topology->budgetId($device);
			if (!isset($per_budget[$budget])) {
				$device_available = $topology->devices[$budget] ?? null;
				$per_budget[$budget] = [
					'need'      => 0,
					'already'   => $running_by_budget[$budget] ?? 0,
					// An id we know nothing about gets no budget, so it fails
					// loudly instead of passing on a guess.
					'available' => $device_available?->available_bytes ?? 0,
				];
			}
			$per_budget[$budget]['need'] += $bytes;
		}

		foreach ($per_budget as $budget => $row) {
			if ($row['need'] > $row['available']) {
				$fits = false;
			}
		}

		return new AdmissionResult($fits, $per_budget);
	}
}
