<?php

declare(strict_types=1);

namespace LlmChooser\Http;

use LlmChooser\Engine\HelpParser;

/**
 * Widget choices the PHP types alone cannot express. Lists come from
 * DESIGN.md §10 and `llama-server --help`.
 */
final class FieldHints
{
	public const CACHE_TYPES = ['f32', 'f16', 'bf16', 'q8_0', 'q4_0', 'q4_1', 'iq4_nl', 'q5_0', 'q5_1'];

	/** field => list of allowed values (rendered as <select>) */
	public const SELECT = [
		'flash_attn'       => ['auto', 'on', 'off'],
		'cache_type_k'     => self::CACHE_TYPES,
		'cache_type_v'     => self::CACHE_TYPES,
		'split_mode'       => ['none', 'layer', 'row', 'tensor'],
		'spec_type'        => ['none', 'draft-simple', 'draft-eagle3', 'draft-mtp', 'draft-dflash', 'draft-dspark',
		                       'ngram-simple', 'ngram-map-k', 'ngram-map-k4v', 'ngram-mod', 'ngram-cache'],
		'reasoning_effort' => ['default', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
	];

	/** group label => list of field names; groups render in this order */
	public const GROUPS = [
		'Context & memory' => ['ctx_size', 'n_predict', 'batch_size', 'ubatch_size', 'flash_attn', 'cache_type_k', 'cache_type_v', 'parallel', 'no_context_shift', 'threads'],
		'GPU'              => ['n_gpu_layers', 'split_mode', 'tensor_split', 'main_gpu'],
		'Sampling'         => ['temp', 'top_k', 'top_p', 'min_p', 'repeat_penalty', 'presence_penalty', 'frequency_penalty', 'seed'],
		'Speculative'      => ['spec_type', 'spec_draft_n_max'],
		'Chat'             => ['alias', 'jinja', 'reasoning_effort'],
		'Other'            => ['extra_flags'],
	];

	/**
	 * $help plus, for every field whose flag exists in the engine's own --help,
	 * the engine's wording ('upstream', 'upstream_flags') and whether it has
	 * changed since our notes were reviewed ('stale'; needs a 'reviewed' hash).
	 *
	 * @param array<string,array<string,mixed>> $help
	 * @param array<string,array{flags:string,text:string}> $entries from HelpParser::parse()
	 * @return array<string,array<string,mixed>>
	 */
	public static function withUpstream(array $help, array $entries): array
	{
		$index = HelpParser::aliasIndex($entries);
		foreach ($help as $name => $e) {
			$m = HelpParser::match($entries, $index, (string)($e['flag'] ?? ''));
			if ($m === null) {
				continue;
			}
			$help[$name]['upstream']       = $m['text'];
			$help[$name]['upstream_flags'] = $m['flags'];
			$help[$name]['stale']          = isset($e['reviewed']) && $e['reviewed'] !== HelpParser::hash($m['text']);
		}
		return $help;
	}

	public const HELP_FILE = __DIR__ . '/../../resources/field_help.json';

	/** @var array<string, array{label:string,flag:string,level:string,upstream_default:string,short:string,notes:string,reviewed?:string}>|null */
	private static ?array $loaded = null;

	/**
	 * Field help from resources/field_help.json, the single source of the
	 * help shown on the preset form. Loaded once per request.
	 *
	 * @return array<string, array{label:string,flag:string,level:string,upstream_default:string,short:string,notes:string,reviewed?:string}>
	 */
	public static function load(): array
	{
		if (self::$loaded === null) {
			$data = json_decode((string)@file_get_contents(self::HELP_FILE), true);
			if (!is_array($data)) {
				throw new \RuntimeException('Cannot read or parse ' . self::HELP_FILE);
			}
			self::$loaded = $data;
		}
		return self::$loaded;
	}
}
