<?php

declare(strict_types=1);

namespace LlmChooser\Memory;

interface TopologyProbe
{
	public function detect(): MemoryTopology;
}
