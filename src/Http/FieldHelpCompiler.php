<?php

declare(strict_types=1);

namespace LlmChooser\Http;

use RuntimeException;

/**
 * Parses FIELD-HELP.md (the authoring copy, see its own header comment for
 * the entry format) into the array shape field_help.json is written from.
 * Keep this parser strict: a silently-skipped field is worse than a loud
 * parse error, because the completeness test (FieldHelpTest) is the only
 * thing standing between a missing entry and a form row with no help.
 */
final class FieldHelpCompiler
{
	/** @return array<string, array{label:string,flag:string,level:string,upstream_default:string,short:string,full:string}> */
	public function compile(string $markdown): array
	{
		// Drop fenced code blocks first: the format description at the top
		// of the file shows a literal "### field_name" example that is not
		// an entry. Replacing each block (fences included) with a blank
		// line keeps paragraph splitting intact.
		$markdown = preg_replace('/^```.*?^```[ 	]*$/ms', '', $markdown) ?? $markdown;

		$out = [];
		// Split on "### field_name" headers; the part before the first
		// one is front matter (title, format description) and is dropped.
		$blocks = preg_split('/^### (\S+)\s*$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
		array_shift($blocks); // drop the front matter
		for ($i = 0; $i < count($blocks); $i += 2) {
			$name = $blocks[$i];
			$body = $blocks[$i + 1] ?? '';
			$out[$name] = $this->parseEntry($name, $body);
		}
		if ($out === []) {
			throw new RuntimeException('No ### field entries found');
		}
		return $out;
	}

	/** @return array{label:string,flag:string,level:string,upstream_default:string,short:string,full:string} */
	private function parseEntry(string $name, string $body): array
	{
		$meta = ['label' => null, 'flag' => null, 'level' => null, 'upstream_default' => null, 'short' => null];
		$lines = explode("\n", $body);
		$fullStart = null;
		foreach ($lines as $i => $line) {
			if (preg_match('/^-\s*(label|flag|level|upstream_default|short):\s*(.*)$/', $line, $m)) {
				if ($meta[$m[1]] !== null) {
					throw new RuntimeException("Field '{$name}': duplicate '{$m[1]}:' line");
				}
				$meta[$m[1]] = trim($m[2]);
				continue;
			}
			$trimmed = trim($line);
			if ($trimmed === '' || $trimmed === '---') {
				continue;
			}
			// First non-meta, non-blank line starts the "full" text.
			$fullStart = $i;
			break;
		}
		foreach ($meta as $key => $value) {
			if ($value === null) {
				throw new RuntimeException("Field '{$name}': missing '{$key}:' line");
			}
		}
		if (!in_array($meta['level'], ['basic', 'advanced'], true)) {
			throw new RuntimeException("Field '{$name}': level must be 'basic' or 'advanced', got '{$meta['level']}'");
		}
		$full = $fullStart === null ? '' : trim(implode("\n", array_slice($lines, $fullStart)));
		// Entries end at the next "### " header (already split away) or a
		// trailing "---" section rule; strip one if the regex left it in.
		$full = rtrim((string)preg_replace('/\n---\s*$/', '', $full));

		return [
			'label'            => $meta['label'],
			'flag'             => $meta['flag'],
			'level'            => $meta['level'],
			'upstream_default' => $meta['upstream_default'],
			'short'            => $meta['short'],
			'full'             => $full,
		];
	}
}
