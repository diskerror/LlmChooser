<?php

declare(strict_types=1);

namespace LlmChooser;

use LlmChooser\Engine\EngineRepository;
use LlmChooser\Memory\LinuxTopologyProbe;
use LlmChooser\Memory\MemoryService;
use LlmChooser\Memory\TopologyProbe;
use LlmChooser\Model\ModelRootsConfig;
use LlmChooser\Model\ModelScanner;
use LlmChooser\Preset\PresetRepository;
use LlmChooser\Process\ProcessManager;
use LlmChooser\Process\RuntimeState;

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
