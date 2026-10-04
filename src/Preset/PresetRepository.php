<?php

declare(strict_types=1);

namespace LLMchooser\Preset;

use LLMchooser\Config\BrokenFile;
use LLMchooser\Config\JsonStore;
use RuntimeException;

class PresetRepository
{
    use JsonStore;

    public function __construct(private readonly string $dir)
    {
        if (!is_dir($this->dir)) {
            if (!mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
                throw new RuntimeException("Cannot create preset directory: {$this->dir}");
            }
        }
    }

    public function load(string $id): Preset
    {
        $path = $this->pathFor($id);
        if (!is_file($path)) {
            throw new RuntimeException("Preset not found: {$id}");
        }
        return new Preset($this->readJson($path));
    }

    public function save(Preset $preset): void
    {
        if ($preset->id === '') {
            throw new RuntimeException('Preset must have a non-empty id before saving.');
        }
        $json = json_encode($preset, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->writeJsonIfChanged($this->pathFor($preset->id), $json);
    }

    public function delete(string $id): void
    {
        $path = $this->pathFor($id);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function loadAll(): PresetCollection
    {
        $out = new PresetCollection();
        foreach ($this->readAllJson($this->dir) as $data) {
            $preset = new Preset($data);
            $out[$preset->id] = $preset;
        }
        return $out;
    }

    private function pathFor(string $id): string
    {
        // Reject path traversal — id must be a simple slug.
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            throw new RuntimeException("Invalid preset id: {$id}");
        }
        return $this->dir . '/' . $id . '.json';
    }
}
