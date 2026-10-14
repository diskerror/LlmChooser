<?php

declare(strict_types=1);

namespace LlmChooser\Http;

use LlmChooser\Engine\BinaryCheck;
use LlmChooser\Engine\Engine;

/** The engine form: id, name, binary path, model directory, host, port range. */
final class EngineForm
{
	/**
	 * @param array<string,string> $errors
	 * @param ?array{source:string,version:string,captured_at:string} $helpInfo what help text the engine currently has
	 */
	public static function render(Engine $e, bool $isNew, array $errors = [], ?array $helpInfo = null, bool $reloadHelp = false): string
	{
		$action = $isNew ? '/engines/new' : '/engines/' . Html::e($e->id) . '/edit';

		$out = '';
		if ($errors !== []) {
			$out .= '<ul class="errors">';
			foreach ($errors as $field => $msg) {
				$out .= '<li><strong>' . Html::e($field) . '</strong>: ' . Html::e($msg) . '</li>';
			}
			$out .= '</ul>';
		}

		$out .= '<form method="post" action="' . $action . '">';
		$out .= self::row('id', 'ID', self::input('id', $e->id, ['required', 'pattern' => '(?!\.)(?!.*\.\.)[A-Za-z0-9_.-]+(?&lt;!\.)', 'readonly' => !$isNew]), self::err($errors, 'id'));
		$out .= self::row('name', 'Name', self::input('name', $e->name, ['required']), self::err($errors, 'name'));
		$out .= self::row('binary_path_default', 'Binary path', self::input('binary_path_default', $e->binary_path_default, ['required', 'placeholder' => '/path/to/llama-server', 'id' => 'binary-path', 'data-binary-check' => '1', 'data-disables' => 'reload-help']) . self::binaryMsg($e->binary_path_default), self::err($errors, 'binary_path_default'));
		$binaryProblem = BinaryCheck::problem($e->binary_path_default) !== null || trim($e->binary_path_default) === '';
		$out .= self::row('reload_help', 'Help text', self::helpControl($helpInfo, $binaryProblem, $reloadHelp), '');
		$out .= self::row('model_dir', 'Model directory', self::input('model_dir', $e->model_dir, ['placeholder' => 'directory scanned for .gguf models']), self::err($errors, 'model_dir'));
		$out .= self::row('extra_flags', 'Extra flags', self::input('extra_flags', $e->extra_flags, ['placeholder' => 'e.g. --verbose']) . ' <span class="default">added to every preset on this engine, before the preset\'s own extra flags. --host and --port are not allowed.</span>', self::err($errors, 'extra_flags'));
		$out .= self::row('host', 'Host', self::input('host', $e->host, ['required']), self::err($errors, 'host'));
		$out .= self::row('port_min', 'Port range (min)', self::input('port_min', (string)$e->port_range->min, ['type' => 'number']), self::err($errors, 'port_range'));
		$out .= self::row('port_max', 'Port range (max)', self::input('port_max', (string)$e->port_range->max, ['type' => 'number']), '');

		$out .= '<p class="form-actions"><button type="submit" class="primary">'
			. ($isNew ? 'Create engine' : 'Save') . '</button>'
			. ' <a class="btn" href="/engines">Cancel</a></p>';
		$out .= '</form>';
		return $out;
	}

	/** @return array<string,mixed> ready for new Engine(...) */
	public static function fromPost(array $post): array
	{
		return [
			'id'                  => trim((string)($post['id'] ?? '')),
			'name'                => trim((string)($post['name'] ?? '')),
			'binary_path_default' => trim((string)($post['binary_path_default'] ?? '')),
			'model_dir'           => trim((string)($post['model_dir'] ?? '')),
			'host'                => trim((string)($post['host'] ?? '')),
			'extra_flags'         => trim((string)($post['extra_flags'] ?? '')),
			'port_range'          => [
				'min' => (int)($post['port_min'] ?? 0),
				'max' => (int)($post['port_max'] ?? 0),
			],
		];
	}

	/** Red "binary not found"-style note next to a path field; app.js keeps it current as the field changes. */
	public static function binaryMsg(string $path): string
	{
		return '<span class="binary-msg" role="alert">' . Html::e(BinaryCheck::problem($path) ?? '') . '</span>';
	}

	/** @param ?array{source:string,version:string,captured_at:string} $info */
	private static function helpControl(?array $info, bool $disabled, bool $checked): string
	{
		$status = 'using the bundled llama.cpp help snapshot';
		if ($info !== null && $info['source'] === 'engine') {
			$status = 'captured ' . ($info['captured_at'] !== '' ? substr($info['captured_at'], 0, 10) : '')
				. ($info['version'] !== '' ? ' from ' . strtok($info['version'], "\n") : '');
		}
		return '<label><input type="checkbox" name="reload_help" value="1" id="reload-help"'
			. ($checked && !$disabled ? ' checked' : '') . ($disabled ? ' disabled' : '') . '> reload help text from the binary when saving</label>'
			. ' <span class="default">' . Html::e($status) . '</span>';
	}

	/** @param array<string,string> $errors */
	private static function err(array $errors, string $key): string
	{
		return (string)($errors[$key] ?? '');
	}

	private static function row(string $name, string $label, string $control, string $error): string
	{
		$errHtml = $error !== '' ? '<div class="field-error">⚠ <strong>' . Html::e($error) . '</strong></div>' : '';
		return '<div class="field-row">'
			. '<label class="field-label">' . Html::e($label) . '</label>'
			. '<div class="control">' . $control . $errHtml . '</div></div>';
	}

	/** @param array<string,string|bool> $attrs */
	private static function input(string $name, string $value, array $attrs = []): string
	{
		$type = (string)($attrs['type'] ?? 'text');
		unset($attrs['type']);
		$a = '';
		foreach ($attrs as $k => $v) {
			if ($v === false) {
				continue;
			}
			$a .= is_int($k) ? ' ' . Html::e((string)$v) : ' ' . Html::e($k) . '="' . Html::e((string)$v) . '"';
		}
		return '<input type="' . Html::e($type) . '" name="' . Html::e($name) . '" value="' . Html::e($value) . '"' . $a . '>';
	}
}
