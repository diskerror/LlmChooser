<?php

declare(strict_types=1);

namespace LlmChooser\Config;

/**
 * The one rule for a preset/engine id. The id becomes a filename under
 * storage/, so this is the only thing standing between an id and path
 * traversal: dots are allowed (model ids like "qwen3.8-flash" need them),
 * but never ".." or a leading/trailing dot.
 *
 * The D modifier rejects a trailing newline: without it, $ also matches
 * just before a final "\n", so "abc\n" would slip through.
 */
final class Id
{
	public const PATTERN = '/^(?!\\.)(?!.*\\.\\.)[A-Za-z0-9_.-]+(?<!\\.)$/D';

	public static function isValid(string $id): bool
	{
		return preg_match(self::PATTERN, $id) === 1;
	}
}
