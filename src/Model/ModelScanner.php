<?php

namespace LLMchooser\Model;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

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

	private ?string $cacheFile;

	/**
	 * @param list<string> $roots Absolute directory paths; missing roots are ignored.
	 */
	public function __construct(array $roots, ?string $cacheFile = null)
	{
		$this->roots     = array_map(static fn(string $r) => rtrim($r, '/'), $roots);
		$this->cacheFile = $cacheFile;
	}

	public static function fromConfig(ModelRootsConfig $c, string $projectRoot): self
	{
		$cacheFile = $c->cache_file;
		if (!str_starts_with($cacheFile, '/')) {
			$cacheFile = rtrim($projectRoot, '/') . '/' . $cacheFile;
		}
		return new self($c->roots->toArray(), $cacheFile);
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
		usort($objects, static fn(ModelEntry $a, ModelEntry $b) => strnatcasecmp($a->displayName, $b->displayName));
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
	private function classify(string $lowerName): ?string
	{
		if (str_starts_with($lowerName, 'mmproj')) {
			return 'mmproj';
		}
		if (str_contains($lowerName, 'ngram')) {
			return 'ngrams';
		}
		if (str_starts_with($lowerName, 'mtp-')
			|| str_contains($lowerName, '-draft')
			|| str_contains($lowerName, '_draft')
			|| str_contains($lowerName, '.draft')
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
			$shardPaths = array_column($g['items'], 'path');
			$have       = count($g['items']);
			$first      = $g['items'][1]['path'] ?? null;

			$rel = $this->relativeName($g['root'], $shardPaths[0] ?? '', stripShard: true);
			if ($first === null || $have !== $g['total']) {
				$entries[] = $this->entry(
					$shardPaths[0], $rel, array_sum(array_column($g['items'], 'size')),
					$shardPaths, $g['root'], "incomplete shard set: have {$have} of {$g['total']}", null
				);
			}
			else {
				$entries[] = $this->entry(
					$first, $rel, array_sum(array_column($g['items'], 'size')),
					$shardPaths, $g['root'], null, null
				);
			}
		}

		foreach ($plain as $p) {
			$root = $this->rootOf($p['path']);
			$rel  = $this->relativeName($root, $p['path'], stripShard: false);
			$entries[] = $this->entry($p['path'], $rel, (int)filesize($p['path']), [$p['path']], $root, null, null);
		}

		return $entries;
	}

	/**
	 * @param list<string> $shardPaths
	 * @return array{path:string,displayName:string,sizeBytes:int,shardPaths:list<string>,root:string,error:?string,info:?GgufInfo}
	 */
	private function entry(string $path, string $displayName, int $sizeBytes, array $shardPaths, string $root, ?string $error, ?GgufInfo $info): array
	{
		return [
			'path'        => $path,
			'displayName' => $displayName,
			'sizeBytes'   => $sizeBytes,
			'shardPaths'  => $shardPaths,
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
	private function relativeName(string $root, string $path, bool $stripShard): string
	{
		$rel = substr($path, strlen($root) + 1);
		if ($stripShard && preg_match(self::SHARD_RE, basename($rel), $m)) {
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
			$modelDir = dirname($e['path']);
			$shared   = dirname($modelDir) . '/shared';
			$found    = ['mmproj' => [], 'draft' => [], 'ngrams' => []];
			foreach ($sidecars as $kind => $files) {
				foreach ($files as $f) {
					$dir = $f->getPath();
					if ($dir === $modelDir || $dir === $shared) {
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
		$byName = [];
		foreach ($entries as $i => $e) {
			$byName[$e['displayName']][$e['root']][] = $i;
		}
		foreach ($byName as $name => $roots) {
			if (count($roots) < 2) {
				continue;
			}
			foreach ($roots as $root => $indices) {
				foreach ($indices as $i) {
					$entries[$i]['displayName'] = $name . ' (' . $root . ')';
				}
			}
		}
	}

	/** @return array<string, array{size:int, mtime:int, info:array}> */
	private function loadCache(): array
	{
		if ($this->cacheFile === null || !is_file($this->cacheFile)) {
			return [];
		}
		try {
			$cache = json_decode((string)file_get_contents($this->cacheFile), true, 512, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException) {
			return [];
		}
		return is_array($cache) ? $cache : [];
	}

	/** @param array<string, mixed> $cache */
	private function writeCache(array $cache): void
	{
		if ($this->cacheFile === null) {
			return;
		}
		$dir = dirname($this->cacheFile);
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		file_put_contents(
			$this->cacheFile,
			json_encode($cache, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)
		);
	}

	/** @param array $e */
	private function toEntry(array $e): ModelEntry
	{
		return new ModelEntry(
			$e['path'],
			$e['displayName'],
			$e['sizeBytes'],
			$e['shardPaths'],
			$e['sidecars'],
			$e['info'],
			$e['error'],
		);
	}
}
