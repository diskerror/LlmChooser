<?php

declare(strict_types=1);

namespace LLMchooser\Memory;

interface TopologyProbe
{
	public function detect(): MemoryTopology;
}
