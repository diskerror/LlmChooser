<?php

declare(strict_types=1);

namespace LlmChooser\Process;

use RuntimeException;

use function Diskerror\Library\Json\decode as jsonDecode;
use function Diskerror\Library\Json\encode as jsonEncode;
use function LlmChooser\Config\ensureDir;

/**
 * storage/runtime_state.json — { "<preset_id>": Instance, ... }
 * All changes go through update(), which holds an exclusive file lock so the
 * web app and the CLI cannot overwrite each other.
 */
final readonly class RuntimeState
{
	public function __construct(private string $file) {}

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

	public function get(string $preset_id): ?Instance
	{
		$all = $this->all();
		return isset($all[$preset_id]) ? $all[$preset_id] : null;
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
			$json = jsonEncode($all);
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
		ensureDir($dir);
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
		$data = jsonDecode($json);
		foreach ($data as $id => $row) {
			$out[$id] = new Instance($row);
		}
		return $out;
	}
}
