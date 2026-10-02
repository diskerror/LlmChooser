<?php

namespace LLMchooser\Engine;

use RuntimeException;

class EngineRepository
{
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
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return new Engine($data);
    }

    public function save(Engine $engine): void
    {
        if ($engine->id === '') {
            throw new RuntimeException('Engine must have a non-empty id before saving.');
        }
        $json = json_encode($engine, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        file_put_contents($this->pathFor($engine->id), $json);
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
        foreach (glob($this->dir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
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
