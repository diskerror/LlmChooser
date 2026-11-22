<?php

declare(strict_types=1);

namespace LlmChooser\Config;

use JsonException;
use JsonSerializable;
use RuntimeException;

use function Diskerror\Library\Json\decode as jsonDecode;
use function Diskerror\Library\Json\encode as jsonEncode;
use function LlmChooser\Config\writeFileAtomic;

/**
 * Shared JSON-file behaviour for the config repositories: one
 * <dir>/<id>.json file per item.
 *
 * The using class provides `private readonly string $dir` and
 * `private const NOUN` ("Preset", "Engine") for messages.
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
	 * Decoded data of item $id.
	 *
	 * @throws RuntimeException if the id is invalid, the file is missing or not valid JSON.
	 */
	private function loadData(string $id): array
	{
		$path = $this->pathFor($id);
		if (!is_file($path)) {
			throw new RuntimeException(self::NOUN . " not found: {$id}");
		}
		return $this->readJson($path);
	}

	/** @throws RuntimeException if the id is empty or invalid, or the write fails. */
	private function saveItem(string $id, JsonSerializable $item): void
	{
		if ($id === '') {
			throw new RuntimeException(self::NOUN . ' must have a non-empty id before saving.');
		}
		$this->writeJsonIfChanged($this->pathFor($id), jsonEncode($item));
	}

	public function delete(string $id): void
	{
		$path = $this->pathFor($id);
		if (is_file($path)) {
			unlink($path);
		}
	}

	/**
	 * Dots are allowed (model ids like "qwen3.8-flash" need them), but never
	 * ".." or a leading/trailing dot: the id is used verbatim as a filename,
	 * so this is the only thing standing between here and path traversal.
	 */
	private function pathFor(string $id): string
	{
		if (!Id::isValid($id)) {
			throw new RuntimeException('Invalid ' . strtolower(self::NOUN) . " id: {$id}");
		}
		return $this->dir . '/' . $id . '.json';
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
			$data = jsonDecode($raw);
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

		writeFileAtomic($path, $json);

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
