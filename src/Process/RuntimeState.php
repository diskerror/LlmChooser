<?php

declare(strict_types=1);

namespace LlmChooser\Process;

use RuntimeException;

/**
 * storage/runtime_state.json — { "<preset_id>": Instance, ... }
 * All changes go through update(), which holds an exclusive file lock so the
 * web app and the CLI cannot overwrite each other.
 */
final class RuntimeState
{
	public function __construct(private readonly string $file) {}

	public function all(): InstanceCollection
	{
		$fh = $this->open();
		try {
			flock($fh, LOCK_SH);
			return $this->decode(stream_get_contents($fh));
		}
		finally {
			flock($fh, LOCK_UN);
			fclose($fh);
		}
	}

	public function get(string $presetId): ?Instance
	{
		$all = $this->all();
		return isset($all[$presetId]) ? $all[$presetId] : null;
	}

	/**
	 * @param callable(InstanceCollection): void $fn  modify the collection in place
	 */
	public function update(callable $fn): InstanceCollection
	{
		$fh = $this->open();
		try {
			flock($fh, LOCK_EX);
			$all = $this->decode(stream_get_contents($fh));
			$fn($all);
			$json = json_encode($all, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
			ftruncate($fh, 0);
			rewind($fh);
			fwrite($fh, $json);
			fflush($fh);
			return $all;
		}
		finally {
			flock($fh, LOCK_UN);
			fclose($fh);
		}
	}

	/** @return resource */
	private function open()
	{
		$dir = dirname($this->file);
		if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new RuntimeException("Cannot create directory: {$dir}");
		}
		$fh = fopen($this->file, 'c+');
		if ($fh === false) {
			throw new RuntimeException("Cannot open runtime state: {$this->file}");
		}
		return $fh;
	}

	private function decode(string|false $json): InstanceCollection
	{
		$out = new InstanceCollection();
		if ($json === false || trim($json) === '') {
			return $out;
		}
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		foreach ($data as $id => $row) {
			$out[$id] = new Instance($row);
		}
		return $out;
	}
}
