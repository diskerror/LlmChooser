<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

use LlmChooser\Model\GgufReader;
use LlmChooser\Preset\Preset;

/**
 * One call for callers: detect once, estimate, admit.
 */
final class MemoryService
{
	private ?MemoryTopology $topology = null;

	public function __construct(
		private readonly TopologyProbe $probe,
		private readonly MemoryEstimator $estimator = new MemoryEstimator(),
		private readonly AdmissionCheck $admission = new AdmissionCheck(),
	) {}

	/** Detected once per object; call refresh() to re-detect. */
	public function topology(): MemoryTopology
	{
		return $this->topology ??= $this->probe->detect();
	}

	public function refresh(): void
	{
		$this->topology = null;
	}

	public function estimate(Preset $preset): Allocation
	{
		return $this->estimator->estimate($preset, GgufReader::read($preset->model_path), $this->topology());
	}

	/** @param list<Allocation> $running */
	public function admit(Preset $preset, array $running): AdmissionResult
	{
		// Fresh "available" numbers before every start: memory moves.
		$this->refresh();
		return $this->admission->check($this->estimate($preset), $running, $this->topology());
	}
}
