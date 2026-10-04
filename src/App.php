<?php

declare(strict_types=1);

namespace LLMchooser;

use LLMchooser\Engine\EngineRepository;
use LLMchooser\Memory\LinuxTopologyProbe;
use LLMchooser\Memory\MemoryService;
use LLMchooser\Memory\TopologyProbe;
use LLMchooser\Model\ModelRootsConfig;
use LLMchooser\Model\ModelScanner;
use LLMchooser\Preset\PresetRepository;
use LLMchooser\Process\ProcessManager;
use LLMchooser\Process\RuntimeState;

/** Builds the shared services from the project directory layout. */
final class App
{
	public readonly PresetRepository $presets;
	public readonly EngineRepository $engines;
	public readonly RuntimeState $state;
	public readonly MemoryService $memory;
	public readonly ProcessManager $processes;

	public function __construct(public readonly string $root, ?TopologyProbe $probe = null)
	{
		$this->presets   = new PresetRepository($root . '/storage/presets');
		$this->engines   = new EngineRepository($root . '/config/engines');
		$this->state     = new RuntimeState($root . '/storage/runtime_state.json');
		$this->memory    = new MemoryService($probe ?? new LinuxTopologyProbe());
		$this->processes = new ProcessManager(
			$this->presets, $this->engines, $this->state, $this->memory, $root . '/storage/logs'
		);
	}

	public function modelScanner(): ModelScanner
	{
		return ModelScanner::fromConfig(
			ModelRootsConfig::load($this->root . '/config/model_roots.json'),
			$this->root
		);
	}
}
