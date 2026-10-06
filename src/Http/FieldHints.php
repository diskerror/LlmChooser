<?php

declare(strict_types=1);

namespace LlmChooser\Http;

/**
 * Widget choices the PHP types alone cannot express. Lists come from
 * DESIGN.md §10 and the beelink `llama-server --help`.
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

	public const HELP_FILE = __DIR__ . '/../../resources/field_help.json';

	/** @var array<string, array{label:string,flag:string,level:string,upstream_default:string,short:string,full:string}>|null */
	private static ?array $loaded = null;

	/**
	 * Compiled field help from resources/field_help.json, generated from
	 * FIELD-HELP.md by `llmchooser help-compile`. Loaded once per request.
	 *
	 * @return array<string, array{label:string,flag:string,level:string,upstream_default:string,short:string,full:string}>
	 */
	public static function load(): array
	{
		if (self::$loaded === null) {
			$data = json_decode((string)@file_get_contents(self::HELP_FILE), true);
			if (!is_array($data)) {
				throw new \RuntimeException('Cannot read or parse ' . self::HELP_FILE
					. ' — run: llmchooser help-compile <FIELD-HELP.md>');
			}
			self::$loaded = $data;
		}
		return self::$loaded;
	}
}
