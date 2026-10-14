<?php

declare(strict_types=1);

namespace LlmChooser\Engine;

/**
 * Parses llama.cpp's `--help` text into one entry per option.
 *
 * Layout (common/arg.cpp): the option spec sits in a 40-column field and its
 * description starts at column 40; continuation lines are indented 40
 * spaces. A spec of 40+ characters pushes the description to the following
 * lines. "----- section -----" lines are headings, not options.
 */
final class HelpParser
{
	public const DESC_COLUMN = 40;

	/**
	 * @return array<string,array{flags:string,text:string}> keyed by {@see key()}
	 */
	public static function parse(string $help): array
	{
		$entries = [];
		$cur     = null;
		foreach (preg_split('/\R/', $help) ?: [] as $line) {
			if ($line !== '' && $line[0] === '-' && !preg_match('/^-{3,}/', $line)) {
				self::flush($entries, $cur);
				$head = substr($line, 0, self::DESC_COLUMN);
				if (strlen($line) > self::DESC_COLUMN && substr($head, -1) !== ' ') {
					// Spec overflows its column: the whole line is the spec.
					$cur = ['flags' => trim($line), 'text' => ''];
				} else {
					$cur = ['flags' => trim($head), 'text' => trim(substr($line, self::DESC_COLUMN))];
				}
			} elseif ($cur !== null && preg_match('/^ {' . self::DESC_COLUMN . ',}\S/', $line)) {
				$cur['text'] .= ' ' . trim($line);
			} else {
				self::flush($entries, $cur);
			}
		}
		self::flush($entries, $cur);

		return $entries;
	}

	/** Canonical key for a flag spec: its first long option, else its first short one. */
	public static function key(string $spec): ?string
	{
		if (preg_match('/--[A-Za-z0-9][A-Za-z0-9-]*/', $spec, $m)) {
			return $m[0];
		}
		if (preg_match('/(?<![A-Za-z0-9-])-[A-Za-z0-9]\b/', $spec, $m)) {
			return $m[0];
		}
		return null;
	}

	/** Every option token (-c, --ctx-size, ...) named in a flag spec. @return list<string> */
	public static function tokens(string $spec): array
	{
		preg_match_all('/(?<![A-Za-z0-9-])--?[A-Za-z0-9][A-Za-z0-9-]*/', $spec, $m);
		return $m[0];
	}

	/**
	 * Alias -> entry key for every token of every entry, so a field documented
	 * as "-md, --model-draft" finds the entry that upstream now lists as
	 * "--spec-draft-model, -md, --model-draft".
	 *
	 * @param array<string,array{flags:string,text:string}> $entries
	 * @return array<string,string>
	 */
	public static function aliasIndex(array $entries): array
	{
		$index = [];
		foreach ($entries as $key => $e) {
			foreach (self::tokens($e['flags']) as $t) {
				$index[$t] ??= $key;
			}
		}
		return $index;
	}

	/**
	 * The upstream entry for a field's flag spec, or null.
	 *
	 * @param array<string,array{flags:string,text:string}> $entries
	 * @param array<string,string> $index from aliasIndex()
	 * @return ?array{flags:string,text:string}
	 */
	public static function match(array $entries, array $index, string $spec): ?array
	{
		foreach (self::tokens($spec) as $t) {
			if (isset($index[$t])) {
				return $entries[$index[$t]];
			}
		}
		return null;
	}

	/** Short, stable fingerprint of an upstream text, for "changed since reviewed". */
	public static function hash(string $text): string
	{
		return substr(sha1(preg_replace('/\s+/', ' ', trim($text)) ?? $text), 0, 12);
	}

	/** @param array<string,array{flags:string,text:string}> $entries */
	private static function flush(array &$entries, ?array &$cur): void
	{
		if ($cur !== null) {
			$cur['text'] = trim((string)preg_replace('/\s+/', ' ', $cur['text']));
			$key = self::key($cur['flags']);
			if ($key !== null && !isset($entries[$key])) {
				$entries[$key] = $cur;
			}
		}
		$cur = null;
	}
}
