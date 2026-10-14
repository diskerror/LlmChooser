<?php

declare(strict_types=1);

namespace LlmChooser;

use LlmChooser\Engine\EngineHelp;
use LlmChooser\Engine\EngineRepository;
use LlmChooser\Memory\LinuxTopologyProbe;
use LlmChooser\Memory\MemoryService;
use LlmChooser\Memory\TopologyProbe;
use LlmChooser\Model\ModelScanner;
use LlmChooser\Preset\PresetRepository;
use LlmChooser\Process\ProcessManager;
use LlmChooser\Process\RuntimeState;

/** Builds the shared services from the project directory layout. */
final class App
{
	public readonly PresetRepository $presets;
	public readonly EngineRepository $engines;
	public readonly EngineHelp $help;
	public readonly RuntimeState $state;
	public readonly MemoryService $memory;
	public readonly ProcessManager $processes;

	public function __construct(public readonly string $root, ?TopologyProbe $probe = null)
	{
		$this->presets   = new PresetRepository($root . '/storage/presets');
		$this->engines   = new EngineRepository($root . '/storage/engines');
		$this->help      = new EngineHelp($root . '/storage/help', $root . '/docs/llama-server-help.txt');
		$this->state     = new RuntimeState($root . '/storage/runtime_state.json');
		$this->memory    = new MemoryService($probe ?? new LinuxTopologyProbe());
		$this->processes = new ProcessManager(
			$this->presets, $this->engines, $this->state, $this->memory, $root . '/storage/logs'
		);
	}

	/**
	 * Engines are the single source of truth for where models live (each
	 * engine's model_dir); there is no separate model-roots config.
	 */
	public function modelScanner(): ModelScanner
	{
		return ModelScanner::fromEngines(
			$this->engines->loadAll(),
			$this->root . '/storage/model_cache.json'
		);
	}
}
