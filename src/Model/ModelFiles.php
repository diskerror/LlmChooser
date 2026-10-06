<?php

declare(strict_types=1);

namespace LlmChooser\Model;

final class ModelFiles
{
	/**
	 * All files that make up the model at $path, in order.
	 * For "name-00001-of-00003.gguf" returns the 3 sibling shard paths that exist.
	 * For anything else returns [$path].
	 *
	 * @return list<string>
	 */
	public static function shardPaths(string $path): array
	{
		if (!preg_match('/^(.+)-(\d{5})-of-(\d{5})\.gguf$/i', $path, $m)) {
			return [$path];
		}
		$total = (int)$m[3];
		$out   = [];
		for ($i = 1; $i <= $total; ++$i) {
			$p = sprintf('%s-%05d-of-%s.gguf', $m[1], $i, $m[3]);
			if (is_file($p)) {
				$out[] = $p;
			}
		}
		return $out === [] ? [$path] : $out;
	}

	/** Sum of file sizes of all shards; 0 if nothing exists. */
	public static function totalBytes(string $path): int
	{
		$sum = 0;
		foreach (self::shardPaths($path) as $p) {
			$size = @filesize($p);
			$sum  += $size === false ? 0 : $size;
		}
		return $sum;
	}
}
