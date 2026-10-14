<?php

declare(strict_types=1);

namespace LlmChooser\Engine;

use Closure;
use RuntimeException;

/**
 * The inference engine's own option text (`<binary> --help`), kept per engine
 * in storage/help/<engine-id>.json — generated, never hand-edited, and kept
 * apart from LlmChooser's own notes (resources/field_help.json). An engine
 * with no capture falls back to the bundled snapshot in docs/.
 */
final class EngineHelp
{
	/** @var Closure(array<int,string>,string):?string  argv, working dir -> merged output or null */
	private Closure $run;

	/** @var ?array{version:string,captured_at:string,entries:array<string,array{flags:string,text:string}>} */
	private ?array $bundled = null;

	public function __construct(
		private readonly string $dir,
		private readonly string $bundledFile,
		?Closure $run = null,
	) {
		$this->run = $run ?? self::defaultRunner();
	}

	/**
	 * Capture and store the engine's help. Throws with a user-readable message.
	 *
	 * @return array{version:string,captured_at:string,entries:array<string,array{flags:string,text:string}>}
	 */
	public function capture(string $engineId, string $binary): array
	{
		$problem = BinaryCheck::problem($binary);
		if ($problem !== null || trim($binary) === '') {
			throw new RuntimeException($problem ?? 'binary not found');
		}
		$help = ($this->run)([$binary, '--help'], dirname($binary));
		if ($help === null || trim($help) === '') {
			throw new RuntimeException("could not run {$binary} --help");
		}
		$entries = HelpParser::parse($help);
		if ($entries === []) {
			throw new RuntimeException('no options found in the --help output');
		}
		$version = trim((string)(($this->run)([$binary, '--version'], dirname($binary)) ?? ''));
		$data    = [
			'version'     => $version,
			'captured_at' => gmdate('c'),
			'entries'     => $entries,
		];
		$this->save($engineId, $data);
		return $data;
	}

	/**
	 * What to show for an engine: its own capture if there is one, else the bundled snapshot.
	 *
	 * @return array{source:string,version:string,captured_at:string,entries:array<string,array{flags:string,text:string}>}
	 */
	public function forEngine(string $engineId): array
	{
		$own = $this->load($engineId);
		if ($own !== null) {
			return ['source' => 'engine'] + $own;
		}
		return ['source' => 'bundled'] + $this->bundled();
	}

	/** @return ?array{version:string,captured_at:string,entries:array<string,array{flags:string,text:string}>} */
	public function load(string $engineId): ?array
	{
		$path = $this->path($engineId);
		if (!is_file($path)) {
			return null;
		}
		$d = json_decode((string)file_get_contents($path), true);
		return is_array($d) && isset($d['entries']) && is_array($d['entries']) ? $d : null;
	}

	public function delete(string $engineId): void
	{
		$path = $this->path($engineId);
		if (is_file($path)) {
			unlink($path);
		}
	}

	/** @return array{version:string,captured_at:string,entries:array<string,array{flags:string,text:string}>} */
	public function bundled(): array
	{
		return $this->bundled ??= [
			'version'     => 'bundled snapshot',
			'captured_at' => '',
			'entries'     => HelpParser::parse((string)@file_get_contents($this->bundledFile)),
		];
	}

	/** @param array<string,mixed> $data */
	private function save(string $engineId, array $data): void
	{
		if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
			throw new RuntimeException("cannot create {$this->dir}");
		}
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		if (file_put_contents($this->path($engineId), $json . "\n", LOCK_EX) === false) {
			throw new RuntimeException('cannot write the help file under ' . $this->dir);
		}
	}

	private function path(string $engineId): string
	{
		if (!preg_match('/^(?!\.)(?!.*\.\.)[A-Za-z0-9_.-]+(?<!\.)$/', $engineId)) {
			throw new RuntimeException("Invalid engine id: {$engineId}");
		}
		return $this->dir . '/' . $engineId . '.json';
	}

	/**
	 * argv (no shell) -> stdout+stderr merged, or null if it did not finish in
	 * time or could not start. llama-server prints --version to stderr, and
	 * the ngram build links its own libraries, hence LD_LIBRARY_PATH.
	 *
	 * @return Closure(array<int,string>,string):?string
	 */
	private static function defaultRunner(): Closure
	{
		return static function (array $argv, string $libDir): ?string {
			$proc = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['LD_LIBRARY_PATH' => $libDir] + getenv());
			if (!is_resource($proc)) {
				return null;
			}
			stream_set_blocking($pipes[1], false);
			stream_set_blocking($pipes[2], false);
			$out      = '';
			$deadline = microtime(true) + 20;
			while (microtime(true) < $deadline) {
				$read = [$pipes[1], $pipes[2]];
				$w = $e = null;
				if (@stream_select($read, $w, $e, 0, 200000) > 0) {
					foreach ($read as $r) {
						$out .= (string)fread($r, 65536);
					}
				}
				if (feof($pipes[1]) && feof($pipes[2])) {
					break;
				}
			}
			$finished = feof($pipes[1]) && feof($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			if (!$finished) {
				proc_terminate($proc);
				proc_close($proc);
				return null;
			}
			proc_close($proc);
			return $out;
		};
	}
}
