<?php

declare(strict_types=1);

namespace LlmChooser\Engine;

use LlmChooser\Config\BrokenFile;
use LlmChooser\Config\Id;
use LlmChooser\Config\JsonStore;
use RuntimeException;

use function Diskerror\Library\Json\encode as jsonEncode;
use function LlmChooser\Config\ensureDir;

class EngineRepository
{
    use JsonStore;

    public function __construct(private readonly string $dir)
    {
        ensureDir($this->dir);
    }

    public function load(string $id): Engine
    {
        $path = $this->pathFor($id);
        if (!is_file($path)) {
            throw new RuntimeException("Engine not found: {$id}");
        }
        return new Engine($this->readJson($path));
    }

    public function save(Engine $engine): void
    {
        if ($engine->id === '') {
            throw new RuntimeException('Engine must have a non-empty id before saving.');
        }
        $json = jsonEncode($engine);
        $this->writeJsonIfChanged($this->pathFor($engine->id), $json);
    }

    public function delete(string $id): void
    {
        $path = $this->pathFor($id);
        if (is_file($path)) {
            unlink($path);
        }
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

    private function pathFor(string $id): string
    {
        // Dots are allowed (see PresetRepository::pathFor), but never ".."
        // or a leading/trailing dot.
        if (!Id::isValid($id)) {
            throw new RuntimeException("Invalid engine id: {$id}");
        }
        return $this->dir . '/' . $id . '.json';
    }
}
