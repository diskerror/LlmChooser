<?php

declare(strict_types=1);

namespace LlmChooser\Engine;

use LlmChooser\Config\JsonStore;

use function LlmChooser\Config\ensureDir;

class EngineRepository
{
    use JsonStore;

    private const NOUN = 'Engine';

    public function __construct(private readonly string $dir)
    {
        ensureDir($this->dir);
    }

    public function load(string $id): Engine
    {
        return new Engine($this->loadData($id));
    }

    public function save(Engine $engine): void
    {
        $this->saveItem($engine->id, $engine);
    }

    public function loadAll(): EngineCollection
    {
        $out = new EngineCollection();
        foreach ($this->readAllJson($this->dir) as $data) {
            $engine = new Engine($data);
            $out[$engine->id] = $engine;
        }
        return $out;
    }
}
