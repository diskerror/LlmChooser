<?php

declare(strict_types=1);

namespace LlmChooser\Config;

use JsonException;
use RuntimeException;

/**
 * Shared JSON-file behaviour for the config repositories.
 *
 * Policy:
 * - Structurally malformed JSON is a HARD failure for that one file. It is
 *   never guessed at, never rewritten, and never silently replaced with
 *   defaults — that would destroy whatever the user was trying to type.
 *   One bad file must not stop the program: loadAll() quarantines it so the
 *   UI stays up and can report it.
 * - Missing, misspelled and extra keys are SOFT. Typed drops unknown keys
 *   and fills defaults. The healed shape reaches disk on the next save().
 * - load() never writes. Normalization happens on save() only.
 */
trait JsonStore
{
	/** @var list<BrokenFile> */
	private array $broken = [];

	/**
	 * Files that failed to parse during the last loadAll(), in glob order.
	 *
	 * @return list<BrokenFile>
	 */
	public function broken(): array
	{
		return $this->broken;
	}

	/**
	 * @throws RuntimeException if the file is missing or not valid JSON.
	 */
	private function readJson(string $path): array
	{
		$raw = @file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException("Cannot read config file: {$path}");
		}

		try {
			$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $e) {
			// Not worth quoting $e->getMessage(): it is always "Syntax error".
			throw new RuntimeException((new BrokenFile($path, basename($path, '.json')))->message(), 0, $e);
		}

		// Valid JSON that is not an object (a bare string, number, null) is
		// not a config file. Same bucket, same message.
		if (!is_array($data)) {
			throw new RuntimeException((new BrokenFile($path, basename($path, '.json')))->message());
		}

		return $data;
	}

	/**
	 * Write $json to $path atomically, and only if the contents differ.
	 *
	 * Skipping an identical write means a hand-edited file is not touched
	 * (mtime preserved) when nothing actually changed, and the normalizing
	 * rewrite happens exactly once.
	 *
	 * @return bool True if the file was written.
	 */
	private function writeJsonIfChanged(string $path, string $json): bool
	{
		$json .= "\n";

		if (is_file($path) && file_get_contents($path) === $json) {
			return false;
		}

		$tmp = $path . '.tmp' . getmypid();
		if (file_put_contents($tmp, $json) === false) {
			throw new RuntimeException("Cannot write config file: {$path}");
		}
		if (!rename($tmp, $path)) {
			@unlink($tmp);
			throw new RuntimeException("Cannot replace config file: {$path}");
		}

		return true;
	}

	/**
	 * Decode every *.json in $dir. Files that fail to parse are recorded in
	 * broken() and skipped, never thrown.
	 *
	 * @return array<string, array> decoded data, keyed by file basename sans .json
	 */
	private function readAllJson(string $dir): array
	{
		$this->broken = [];
		$out          = [];

		foreach (glob($dir . '/*.json') as $file) {
			$id = basename($file, '.json');
			try {
				$out[$id] = $this->readJson($file);
			}
			catch (RuntimeException $e) {
				$this->broken[] = new BrokenFile($file, $id);
			}
		}

		return $out;
	}
}
