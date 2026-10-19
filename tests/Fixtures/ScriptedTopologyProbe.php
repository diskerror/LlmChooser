<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Fixtures;

use LlmChooser\Memory\MemoryTopology;
use LlmChooser\Memory\TopologyProbe;

/**
 * Returns the given topologies in detection order; once exhausted, keeps
 * returning the last one. Models slow driver memory release: the probe
 * reports stale-low availability for the first detections, then recovers.
 */
final class ScriptedTopologyProbe implements TopologyProbe
{
	private int $n = 0;

	/** @param list<MemoryTopology> $topologies */
	public function __construct(private readonly array $topologies)
	{
		if ($topologies === []) {
			throw new \InvalidArgumentException('need at least one topology');
		}
	}

	public function detect(): MemoryTopology
	{
		return $this->topologies[min($this->n++, count($this->topologies) - 1)];
	}
}
