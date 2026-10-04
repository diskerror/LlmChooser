<?php

declare(strict_types=1);

namespace LLMchooser\Engine;

use LLMchooser\Config\BrokenFile;
use LLMchooser\Config\JsonStore;
use RuntimeException;

class EngineRepository
{
    use JsonStore;

    public function __construct(private readonly string $dir)
    {
        if (!is_dir($this->dir)) {
            if (!mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
                throw new RuntimeException("Cannot create engine directory: {$this->dir}");
            }
        }
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
        $json = json_encode($engine, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
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
        // Reject path traversal — id must be a simple slug.
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            throw new RuntimeException("Invalid engine id: {$id}");
        }
        return $this->dir . '/' . $id . '.json';
    }
}
