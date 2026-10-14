<?php

declare(strict_types=1);

namespace LlmChooser\Engine;

/** Is this path an inference binary the web server's user can run? */
final class BinaryCheck
{
	/** Null when usable, else a short reason suitable for showing next to the field. */
	public static function problem(string $path): ?string
	{
		$path = trim($path);
		if ($path === '') {
			return null; // emptiness is the caller's concern (required vs "engine default")
		}
		if (!file_exists($path)) {
			return 'binary not found';
		}
		if (is_dir($path)) {
			return 'path is a directory';
		}
		if (!is_executable($path)) {
			return 'not executable';
		}
		return null;
	}
}
