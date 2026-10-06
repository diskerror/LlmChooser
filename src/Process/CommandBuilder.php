<?php

declare(strict_types=1);

namespace LlmChooser\Process;

use LlmChooser\Engine\Engine;
use LlmChooser\Preset\Preset;
use RuntimeException;

/**
 * Turns a Preset + Engine into the llama-server argv.
 *
 * Arguments are emitted in a fixed order so that two builds of the same
 * preset compare equal in logs, and so a missing flag is obvious rather than
 * hidden by ordering drift.
 */
final class CommandBuilder
{
	public function build(Preset $preset, Engine $engine, int $port): Command
	{
		$binary = $preset->binary_path !== '' ? $preset->binary_path : $engine->binary_path_default;
		if ($binary === '') {
			throw new RuntimeException('No llama-server binary set for preset ' . $preset->id);
		}

		$argv = [$binary, '--host', $engine->host, '--port', (string)$port];

		if ($preset->model_path === '') {
			throw new RuntimeException("Preset {$preset->id} has no model_path");
		}
		$argv[] = '--model';
		$argv[] = $preset->model_path;

		if ($preset->mmproj_path !== null && $preset->mmproj_path !== '') {
			$argv[] = '--mmproj';
			$argv[] = $preset->mmproj_path;
		}
		if ($preset->model_draft_path !== null && $preset->model_draft_path !== '') {
			$argv[] = '--model-draft';
			$argv[] = $preset->model_draft_path;
		}

		$a = $preset->args;

		$argv[] = '--ctx-size';
		$argv[] = (string)$a->ctx_size;
		$argv[] = '--predict';
		$argv[] = (string)$a->n_predict;
		$argv[] = '--batch-size';
		$argv[] = (string)$a->batch_size;
		$argv[] = '--ubatch-size';
		$argv[] = (string)$a->ubatch_size;
		$argv[] = '--flash-attn';
		$argv[] = $a->flash_attn;
		$argv[] = '--cache-type-k';
		$argv[] = $a->cache_type_k;
		$argv[] = '--cache-type-v';
		$argv[] = $a->cache_type_v;
		$argv[] = '--n-gpu-layers';
		$argv[] = $a->n_gpu_layers;
		$argv[] = '--split-mode';
		$argv[] = $a->split_mode;

		if ($a->tensor_split !== '') {
			$argv[] = '--tensor-split';
			$argv[] = $a->tensor_split;
		}

		$argv[] = '--main-gpu';
		$argv[] = (string)$a->main_gpu;
		$argv[] = '--parallel';
		$argv[] = (string)$a->parallel;

		$argv[] = $a->no_context_shift ? '--no-context-shift' : '--context-shift';

		$argv[] = '--temp';
		$argv[] = self::num($a->temp);
		$argv[] = '--top-k';
		$argv[] = (string)$a->top_k;
		$argv[] = '--top-p';
		$argv[] = self::num($a->top_p);
		$argv[] = '--min-p';
		$argv[] = self::num($a->min_p);
		$argv[] = '--repeat-penalty';
		$argv[] = self::num($a->repeat_penalty);
		$argv[] = '--presence-penalty';
		$argv[] = self::num($a->presence_penalty);
		$argv[] = '--frequency-penalty';
		$argv[] = self::num($a->frequency_penalty);
		$argv[] = '--seed';
		$argv[] = (string)$a->seed;

		if ($a->spec_type !== 'none') {
			$argv[] = '--spec-type';
			$argv[] = $a->spec_type;
			$argv[] = '--spec-draft-n-max';
			$argv[] = (string)$a->spec_draft_n_max;
		}

		$argv[] = '--alias';
		$argv[] = $a->alias !== '' ? $a->alias : $preset->id;

		$argv[] = $a->jinja ? '--jinja' : '--no-jinja';

		if ($a->reasoning_effort !== 'default') {
			$argv[] = '--reasoning-effort';
			$argv[] = $a->reasoning_effort;
		}

		$argv[] = '--threads';
		$argv[] = (string)$a->threads;

		$extra = self::splitFlags($a->extra_flags);
		foreach ($extra as $flag) {
			// --host/--port are owned by port management; letting a preset
			// override them would desync the state from what is bound.
			if ($flag === '--host' || $flag === '--port') {
				throw new RuntimeException('extra_flags must not set --host or --port');
			}
		}
		array_push($argv, ...$extra);

		// The patched ngrams build links its own shared libraries.
		return new Command($argv, ['LD_LIBRARY_PATH' => dirname($binary)]);
	}

	/**
	 * A float with no trailing zeros: 0.8 -> "0.8", 1.0 -> "1", 0.0 -> "0".
	 *
	 * %F is used rather than %g so values are never printed in exponent form.
	 */
	public static function num(float $v): string
	{
		$s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
		return ($s === '' || $s === '-0') ? '0' : $s;
	}

	/**
	 * Split on whitespace, keeping quoted runs together and dropping the
	 * quotes: '--a 1 --b "x y"' -> ['--a', '1', '--b', 'x y'].
	 *
	 * A character loop rather than a regex so an unbalanced quote produces
	 * something predictable (the run ends at end-of-string) instead of a
	 * backtracking mess.
	 *
	 * @return list<string>
	 */
	public static function splitFlags(string $s): array
	{
		$out     = [];
		$cur     = '';
		$quote   = null;
		$started = false;

		$len = strlen($s);
		for ($i = 0; $i < $len; ++$i) {
			$c = $s[$i];

			if ($quote !== null) {
				if ($c === $quote) {
					$quote = null;
				} else {
					$cur .= $c;
				}
				continue;
			}

			if ($c === '"' || $c === "'") {
				$quote   = $c;
				$started = true;
				continue;
			}

			if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
				if ($started) {
					$out[] = $cur;
					$cur     = '';
					$started = false;
				}
				continue;
			}

			$cur     .= $c;
			$started = true;
		}

		if ($started) {
			$out[] = $cur;
		}

		return $out;
	}
}
