<?php

declare(strict_types=1);

namespace LlmChooser\Preset;

use LlmChooser\Config\JsonStore;

use function LlmChooser\Config\ensureDir;

class PresetRepository
{
    use JsonStore;

    private const NOUN = 'Preset';

    public function __construct(private readonly string $dir)
    {
        ensureDir($this->dir);
    }

    public function load(string $id): Preset
    {
        return new Preset($this->loadData($id));
    }

    public function save(Preset $preset): void
    {
        $this->saveItem($preset->id, $preset);
    }

    public function setEnabled(string $id, bool $enabled): Preset
    {
        $preset = $this->load($id);
        $preset->enabled = $enabled;
        $this->save($preset);
        return $preset;
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
}
