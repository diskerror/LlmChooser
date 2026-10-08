<?php

declare(strict_types=1);

namespace LlmChooser\Http;

use LlmChooser\Engine\EngineRepository;
use LlmChooser\Model\ChatTemplateFamily;
use LlmChooser\Model\GgufReader;
use LlmChooser\Preset\Preset;
use LlmChooser\Preset\PresetRepository;
use Throwable;

final class PresetValidator
{
	/** @return array<string,string> field => message */
	public static function validate(Preset $p, EngineRepository $engines, bool $isNew, PresetRepository $presets): array
	{
		$errors = [];

		if (!preg_match('/^(?!\.)(?!.*\.\.)[A-Za-z0-9_.-]+(?<!\.)$/', $p->id)) {
			$errors['id'] = 'Only letters, digits, - . and _ (no leading/trailing dot, no "..")';
		} elseif ($isNew) {
			try {
				$presets->load($p->id);
				$errors['id'] = 'Already exists';
			} catch (Throwable) {
				// fine: it must not exist yet
			}
		}

		if ($p->name === '') {
			$errors['name'] = 'Required';
		}

		try {
			$engines->load($p->engine_id);
		} catch (Throwable) {
			$errors['engine_id'] = 'Unknown engine';
		}

		if ($p->model_path === '') {
			$errors['model_path'] = 'Required';
		} elseif (!is_file($p->model_path)) {
			$errors['model_path'] = 'File not found';
		}

		foreach (['mmproj_path', 'model_draft_path'] as $f) {
			$v = $p->{$f};
			if ($v !== null && $v !== '' && !is_file($v)) {
				$errors[$f] = 'File not found';
			}
		}

		if (!preg_match('/^(auto|all|-?\d+)$/', (string)$p->args->n_gpu_layers)) {
			$errors['args.n_gpu_layers'] = "number, 'auto' or 'all'";
		}

		$ts = (string)$p->args->tensor_split;
		if ($ts !== '' && !preg_match('/^\d+(\.\d+)?(,\d+(\.\d+)?)*$/', $ts)) {
			$errors['args.tensor_split'] = 'comma-separated ratios, e.g. 3,1';
		}

		if (preg_match('/(^|\s)--(host|port)(\s|=|$)/', (string)$p->args->extra_flags)) {
			$errors['args.extra_flags'] = 'Must not set --host or --port';
		}

		foreach (FieldHints::SELECT as $field => $allowed) {
			if (!in_array((string)$p->args->{$field}, $allowed, true)) {
				$errors['args.' . $field] = 'Not an allowed value';
			}
		}

		// Template-specific reasoning_effort enum (Phase 13). Runs AFTER the
		// generic SELECT loop so the more informative family message wins
		// when a value is outside both domains. Unknown families (and
		// unreadable headers) stay permissive: we don't know their rules.
		if ($p->model_path !== '' && is_file($p->model_path)) {
			try {
				$family = ChatTemplateFamily::detect(GgufReader::read($p->model_path)->chatTemplate());
			} catch (Throwable) {
				$family = null; // corrupt/unreadable header: model_path errors cover it
			}
			if ($family !== null) {
				$valid  = ChatTemplateFamily::reasoningEfforts($family);
				$effort = (string)$p->args->reasoning_effort;
				if ($effort !== '' && $effort !== 'default' && !in_array($effort, $valid, true)) {
					$errors['args.reasoning_effort'] =
						'This model only accepts: ' . implode(', ', $valid) . " (got '{$effort}')";
				}
			}
		}

		return $errors;
	}
}
