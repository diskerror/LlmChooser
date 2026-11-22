<?php

declare(strict_types=1);

/**
 * File helpers shared by everything that writes under storage/.
 *
 * Several php -S workers (or php-fpm children) read these files while
 * another one writes them, so writes go to a temp file in the same
 * directory and rename() into place: a reader sees the old file or the
 * new one, never half of one.
 */

namespace LlmChooser\Config;

use RuntimeException;

/**
 * Create $dir (and parents) if missing. 0750 matches the storage
 * permissions install.sh sets; the process umask can only narrow it.
 *
 * @throws RuntimeException
 */
function ensureDir(string $dir): void
{
	if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
		throw new RuntimeException("Cannot create directory: {$dir}");
	}
}

/**
 * Replace $path with $data atomically (temp file + rename).
 *
 * @throws RuntimeException
 */
function writeFileAtomic(string $path, string $data): void
{
	$tmp = $path . '.tmp' . getmypid();
	if (@file_put_contents($tmp, $data) === false) {
		throw new RuntimeException("Cannot write file: {$path}");
	}
	if (!@rename($tmp, $path)) {
		@unlink($tmp);
		throw new RuntimeException("Cannot replace file: {$path}");
	}
}
