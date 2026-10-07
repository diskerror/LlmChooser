<?php

declare(strict_types=1);

namespace LlmChooser\Http;

use LlmChooser\Engine\Engine;
use LlmChooser\Engine\EngineRepository;
use Throwable;

final class EngineValidator
{
	/** @return array<string,string> field => message */
	public static function validate(Engine $e, bool $isNew, EngineRepository $engines): array
	{
		$errors = [];

		if (!preg_match('/^[A-Za-z0-9_-]+$/', $e->id)) {
			$errors['id'] = 'Only letters, digits, - and _';
		} elseif ($isNew) {
			try {
				$engines->load($e->id);
				$errors['id'] = 'Already exists';
			} catch (Throwable) {
				// fine: it must not exist yet
			}
		}

		if ($e->name === '') {
			$errors['name'] = 'Required';
		}

		if ($e->binary_path_default === '') {
			$errors['binary_path_default'] = 'Required';
		}

		if ($e->host === '') {
			$errors['host'] = 'Required';
		}

		return $errors;
	}
}
