<?php

declare(strict_types=1);

namespace LLMchooser\Http;

use LLMchooser\Engine\EngineRepository;
use LLMchooser\Preset\Preset;
use LLMchooser\Preset\PresetRepository;
use Throwable;

final class PresetValidator
{
	/** @return array<string,string> field => message */
	public static function validate(Preset $p, EngineRepository $engines, bool $isNew, PresetRepository $presets): array
	{
		$errors = [];

		if (!preg_match('/^[A-Za-z0-9_-]+$/', $p->id)) {
			$errors['id'] = 'Only letters, digits, - and _';
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

		return $errors;
	}
}
