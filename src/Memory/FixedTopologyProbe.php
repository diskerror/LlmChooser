<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

/**
 * Returns a topology handed to its constructor: used by tests, and
 * available as a manual override when auto-detection is wrong.
 */
final readonly class FixedTopologyProbe implements TopologyProbe
{
	public function __construct(private MemoryTopology $topology) {}

	public function detect(): MemoryTopology
	{
		return $this->topology;
	}
}
