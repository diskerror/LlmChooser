<?php

declare(strict_types=1);

namespace LlmChooser\Model;

use FilesystemIterator;
use LlmChooser\Engine\EngineCollection;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function Diskerror\Library\Json\decode as jsonDecode;
use function Diskerror\Library\Json\encode as jsonEncode;

/**
 * Walks model roots, builds ModelEntry objects (shards collapsed, sidecars
 * attached) and caches read GGUF headers by path + size + mtime.
 */
final class ModelScanner
{
	/** Shard file names: base-00001-of-00003.gguf */
	private const SHARD_RE = '/^(.+)-(\d{5})-of-(\d{5})\.gguf$/i';

	/** @var list<string> */
	private array $roots;

	private ?string $cache_file;

	/**
	 * @param list<string> $roots Absolute directory paths; missing roots are ignored.
	 */
	public function __construct(array $roots, ?string $cache_file = null)
	{
		$this->roots     = array_map(static fn(string $r) => rtrim($r, '/'), $roots);
		$this->cache_file = $cache_file;
	}

	/**
	 * Build a scanner from the union of every engine's model_dir — engines
	 * are the single source of truth for where to look for models (no
	 * separate model-roots config). Engines with an empty model_dir
	 * contribute nothing; duplicates collapse.
	 */
	public static function fromEngines(EngineCollection $engines, string $cache_file): self
	{
		$roots = [];
		foreach ($engines as $engine) {
			if ($engine->model_dir !== '') {
				$roots[$engine->model_dir] = true;
			}
		}
		return new self(array_keys($roots), $cache_file);
	}

	/** @return list<ModelEntry> */
	public function scan(): array
	{
		clearstatcache();
		$cache = $this->loadCache();

		$models   = [];  // model files: [dir, name]
		$sidecars = [];  // [kind, dir, name]
		foreach ($this->roots as $root) {
			if (!is_dir($root)) {
				continue;
			}
			foreach ($this->walk($root) as $file) {
				$lower = strtolower($file->getBasename());
				$kind  = $this->classify($lower);
				if ($kind === null) {
					$models[]   = $file;
				}
				else {
					$sidecars[$kind][] = $file;
				}
			}
		}

		$entries = $this->buildEntries($models);

		$seen = [];
		foreach ($entries as &$e) {
			$path = $e['path'];
			if ($e['error'] !== null) {
				continue; // Errors are not cached.
			}
			$hit = $cache[$path] ?? null;
			if ($hit !== null
				&& isset($hit['size'], $hit['mtime'], $hit['info'])
				&& (int)$hit['size'] === (int)filesize($path)
				&& (int)$hit['mtime'] === (int)filemtime($path)
			) {
				$e['info'] = GgufInfo::fromArray((array)$hit['info']);
			}
			else {
				try {
					$e['info'] = GgufReader::read($path);
				}
				catch (GgufException $ex) {
					$e['info']  = null;
					$e['error'] = $ex->getMessage();
					continue;
				}
				$cache[$path] = [
					'size'  => (int)filesize($path),
					'mtime' => (int)filemtime($path),
					'info'  => $e['info']->toArray(),
				];
			}
			$seen[$path] = true;
		}
		unset($e);

		$this->attachSidecars($entries, $sidecars);
		$this->fixDisplayCollisions($entries);

		foreach ($cache as $path => $unused) {
			if (!isset($seen[$path])) {
				unset($cache[$path]);
			}
		}
		$this->writeCache($cache);

		$objects = array_map(fn(array $e) => $this->toEntry($e), $entries);
		usort($objects, static fn(ModelEntry $a, ModelEntry $b) => strnatcasecmp($a->display_name, $b->display_name));
		return $objects;
	}

	/**
	 * Yield every non-hidden .gguf file under $root (hidden directories are
	 * never descended into). Symlinked directories are not followed.
	 *
	 * @return list<\SplFileInfo>
	 */
	private function walk(string $root): array
	{
		$iterator = new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			static fn(\SplFileInfo $current) => !str_starts_with($current->getBasename(), '.')
		);
		$out = [];
		foreach (new RecursiveIteratorIterator($iterator) as $file) {
			if ($file->isFile() && str_ends_with(strtolower($file->getBasename()), '.gguf')) {
				$out[] = $file;
			}
		}
		return $out;
	}

	/** null = model file, otherwise the sidecar kind. */
	private function classify(string $lower_name): ?string
	{
		if (str_starts_with($lower_name, 'mmproj')) {
			return 'mmproj';
		}
		if (str_contains($lower_name, 'ngram')) {
			return 'ngrams';
		}
		if (str_starts_with($lower_name, 'mtp-')
			|| str_contains($lower_name, '-draft')
			|| str_contains($lower_name, '_draft')
			|| str_contains($lower_name, '.draft')
		) {
			return 'draft';
		}
		return null;
	}

	/**
	 * Group model files into draft entry arrays (shards collapsed).
	 *
	 * @param list<\SplFileInfo> $models
	 * @return list<array> staged entries, converted to ModelEntry at the end of scan()
	 */
	private function buildEntries(array $models): array
	{
		$groups = []; // key => [base, total, root, items => [index => [path, size]]]
		$plain  = [];
		foreach ($models as $file) {
			$path = $file->getPathname();
			$name = $file->getBasename();
			if (!preg_match(self::SHARD_RE, $name, $m)) {
				$plain[] = ['path' => $path, 'name' => $name];
				continue;
			}
			$root   = $this->rootOf($path);
			$base   = $m[1];
			$index  = (int)$m[2];
			$total  = (int)$m[3];
			$key    = dirname($path) . '/' . strtolower($base) . '/' . $total;
			$groups[$key] ??= ['base' => $base, 'total' => $total, 'root' => $root, 'items' => []];
			$groups[$key]['items'][$index] = ['path' => $path, 'size' => (int)$file->getSize()];
		}

		$entries = [];
		foreach ($groups as $g) {
			ksort($g['items']);
			$shard_paths = array_column($g['items'], 'path');
			$have       = count($g['items']);
			$first      = $g['items'][1]['path'] ?? null;

			$rel = $this->relativeName($g['root'], $shard_paths[0] ?? '', strip_shard: true);
			if ($first === null || $have !== $g['total']) {
				$entries[] = $this->entry(
					$shard_paths[0], $rel, array_sum(array_column($g['items'], 'size')),
					$shard_paths, $g['root'], "incomplete shard set: have {$have} of {$g['total']}", null
				);
			}
			else {
				$entries[] = $this->entry(
					$first, $rel, array_sum(array_column($g['items'], 'size')),
					$shard_paths, $g['root'], null, null
				);
			}
		}

		foreach ($plain as $p) {
			$root = $this->rootOf($p['path']);
			$rel  = $this->relativeName($root, $p['path'], strip_shard: false);
			$entries[] = $this->entry($p['path'], $rel, (int)filesize($p['path']), [$p['path']], $root, null, null);
		}

		return $entries;
	}

	/**
	 * @param list<string> $shard_paths
	 * @return array{path:string,display_name:string,size_bytes:int,shard_paths:list<string>,root:string,error:?string,info:?GgufInfo}
	 */
	private function entry(string $path, string $display_name, int $size_bytes, array $shard_paths, string $root, ?string $error, ?GgufInfo $info): array
	{
		return [
			'path'        => $path,
			'display_name' => $display_name,
			'size_bytes'   => $size_bytes,
			'shard_paths'  => $shard_paths,
			'root'        => $root,
			'error'       => $error,
			'info'        => $info,
		];
	}

	/** The root a path was found under (longest match). */
	private function rootOf(string $path): string
	{
		$best = '';
		foreach ($this->roots as $root) {
			if (str_starts_with($path, $root . '/') && strlen($root) > strlen($best)) {
				$best = $root;
			}
		}
		return $best;
	}

	/** Path relative to root, without ".gguf" (and without shard suffix if asked). */
	private function relativeName(string $root, string $path, bool $strip_shard): string
	{
		$rel = substr($path, strlen($root) + 1);
		if ($strip_shard && preg_match(self::SHARD_RE, basename($rel), $m)) {
			$dir  = dirname($rel);
			$rel  = $dir === '.' ? $m[1] : $dir . '/' . $m[1];
		}
		return preg_replace('/\.gguf$/i', '', $rel);
	}

	/**
	 * Attach sidecar files to entries: same directory, or a sibling directory
	 * named "shared" (dirname(modelDir) . '/shared').
	 *
	 * @param list<array> $entries
	 * @param array<string, list<\SplFileInfo>> $sidecars
	 */
	private function attachSidecars(array &$entries, array $sidecars): void
	{
		foreach ($entries as &$e) {
			$model_dir = dirname($e['path']);
			$shared   = dirname($model_dir) . '/shared';
			$found    = ['mmproj' => [], 'draft' => [], 'ngrams' => []];
			foreach ($sidecars as $kind => $files) {
				foreach ($files as $f) {
					$dir = $f->getPath();
					if ($dir === $model_dir || $dir === $shared) {
						$found[$kind][] = $f->getPathname();
					}
				}
				sort($found[$kind]);
			}
			$e['sidecars'] = $found;
		}
		unset($e);
	}

	/** Two entries from different roots with the same display name both get " (root)". */
	private function fixDisplayCollisions(array &$entries): void
	{
		$by_name = [];
		foreach ($entries as $i => $e) {
			$by_name[$e['display_name']][$e['root']][] = $i;
		}
		foreach ($by_name as $name => $roots) {
			if (count($roots) < 2) {
				continue;
			}
			foreach ($roots as $root => $indices) {
				foreach ($indices as $i) {
					$entries[$i]['display_name'] = $name . ' (' . $root . ')';
				}
			}
		}
	}

	/** @return array<string, array{size:int, mtime:int, info:array}> */
	private function loadCache(): array
	{
		if ($this->cache_file === null || !is_file($this->cache_file)) {
			return [];
		}
		try {
			$cache = jsonDecode((string)file_get_contents($this->cache_file));
		}
		catch (\JsonException) {
			return [];
		}
		return is_array($cache) ? $cache : [];
	}

	/** @param array<string, mixed> $cache */
	private function writeCache(array $cache): void
	{
		if ($this->cache_file === null) {
			return;
		}
		$dir = dirname($this->cache_file);
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		file_put_contents(
			$this->cache_file,
			jsonEncode($cache, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
		);
	}

	/** @param array $e */
	private function toEntry(array $e): ModelEntry
	{
		return new ModelEntry(
			$e['path'],
			$e['display_name'],
			$e['size_bytes'],
			$e['shard_paths'],
			$e['sidecars'],
			$e['info'],
			$e['error'],
		);
	}
}
