<?php

declare(strict_types=1);

namespace LLMchooser\Http;

use LLMchooser\Cli\Format;
use LLMchooser\Model\ModelEntry;
use LLMchooser\Preset\Preset;
use LLMchooser\Preset\PresetArgs;
use ReflectionProperty;

/**
 * The preset form, GENERATED from PresetArgs + FieldHints — no hand-written
 * field per flag, so a new arg appears in the UI the moment it exists in the
 * class (worst case in the 'Unsorted' group, never missing).
 */
final class PresetForm
{
	/** @param list<ModelEntry> $models */
	public static function render(Preset $p, array $models, bool $isNew, array $errors = []): string
	{
		$action = $isNew ? '/presets/new' : '/presets/' . Html::e($p->id) . '/edit';
		$out    = '';

		if ($errors !== []) {
			$out .= '<ul class="errors">';
			foreach ($errors as $field => $msg) {
				$out .= '<li><strong>' . Html::e($field) . '</strong>: ' . Html::e($msg) . '</li>';
			}
			$out .= '</ul>';
		}

		$out .= '<form method="post" action="' . $action . '">';
		$out .= self::textF('id', $p->id, ['required', 'pattern' => '[A-Za-z0-9_-]+', 'readonly' => !$isNew]);
		$out .= self::textF('name', $p->name);
		$out .= self::textF('engine_id', $p->engine_id, ['required']);
		$out .= self::textF('binary_path', $p->binary_path, ['placeholder' => 'engine default']);
		$out .= self::modelSelect($p, $models);
		$out .= self::datalist($models);
		$out .= self::textF('mmproj_path', (string)($p->mmproj_path ?? ''), ['list' => 'sidecars', 'placeholder' => 'none']);
		$out .= self::textF('model_draft_path', (string)($p->model_draft_path ?? ''), ['list' => 'sidecars', 'placeholder' => 'none']);
		$out .= '<label>ttl_seconds<input type="number" step="1" name="ttl_seconds" value="' . Html::e($p->ttl_seconds) . '"></label>';
		$out .= '<label>notes<textarea name="notes" rows="3" cols="60">' . Html::e($p->notes) . '</textarea></label>';

		// Args, grouped. Any PresetArgs name not in GROUPS lands in 'Unsorted'
		// so a later-added field never silently disappears from the form.
		$grouped = [];
		foreach (FieldHints::GROUPS as $label => $names) {
			foreach ($names as $n) {
				$grouped[$n] = $label;
			}
		}
		$groups = FieldHints::GROUPS;
		$extra  = [];
		foreach ((new PresetArgs())->getPublicNames() as $name) {
			if (!isset($grouped[$name])) {
				$extra[] = $name;
			}
		}
		if ($extra !== []) {
			$groups['Unsorted'] = $extra;
		}

		foreach ($groups as $label => $names) {
			$out .= '<fieldset><legend>' . Html::e($label) . '</legend>';
			foreach ($names as $name) {
				$out .= self::argField($name, $p->args->{$name});
			}
			$out .= '</fieldset>';
		}

		$out .= '<p><button type="submit" class="primary">' . ($isNew ? 'Create preset' : 'Save') . '</button></p>';
		$out .= '</form>';
		return $out;
	}

	/** @return array<string,mixed> ready for new Preset(...) */
	public static function fromPost(array $post): array
	{
		$out = [];
		foreach (['id', 'name', 'engine_id', 'binary_path', 'model_path', 'mmproj_path', 'model_draft_path', 'notes'] as $k) {
			$out[$k] = trim((string)($post[$k] ?? ''));
		}
		$out['ttl_seconds'] = trim((string)($post['ttl_seconds'] ?? ''));
		$out['args']        = $post['args'] ?? [];
		return $out;
	}

	private static function textF(string $name, string $value, array $attrs = []): string
	{
		$a = '';
		foreach ($attrs as $k => $v) {
			if ($v === false) {
				continue;
			}
			$a .= is_int($k) ? ' ' . Html::e($v) : ' ' . Html::e($k) . '="' . Html::e($v) . '"';
		}
		return '<label>' . Html::e($name) . '<input type="text" name="' . Html::e($name) . '" value="'
			. Html::e($value) . '"' . $a . '></label>';
	}

	/** @param list<ModelEntry> $models */
	private static function modelSelect(Preset $p, array $models): string
	{
		$paths = [];
		$out   = '<label>model_path<select name="model_path">';
		foreach ($models as $e) {
			$paths[$e->path] = true;
			$label           = $e->displayName . ' — ' . Format::gb($e->sizeBytes) . ($e->error !== null ? ' [error]' : '');
			$out            .= '<option value="' . Html::e($e->path) . '"'
				. ($e->path === $p->model_path ? ' selected' : '') . '>' . Html::e($label) . '</option>';
		}
		if ($p->model_path !== '' && !isset($paths[$p->model_path])) {
			$out = '<label>model_path<select name="model_path"><option value="' . Html::e($p->model_path)
				. '" selected>(not found) ' . Html::e($p->model_path) . '</option>'
				. substr($out, strlen('<label>model_path<select name="model_path">'));
		}
		$out .= '</select></label>';
		return $out;
	}

	/** @param list<ModelEntry> $models */
	private static function datalist(array $models): string
	{
		$seen = [];
		$out  = '<datalist id="sidecars">';
		foreach ($models as $e) {
			foreach ($e->sidecars as $list) {
				foreach ($list as $path) {
					if (!isset($seen[$path])) {
						$seen[$path] = true;
						$out        .= '<option value="' . Html::e($path) . '">';
					}
				}
			}
		}
		$out .= '</datalist>';
		return $out;
	}

	private static function argField(string $name, mixed $value): string
	{
		$type = (new ReflectionProperty(PresetArgs::class, $name))->getType()?->getName() ?? 'string';
		$help = FieldHints::HELP[$name] ?? '';
		$help = $help !== '' ? '<small>' . Html::e($help) . '</small>' : '';
		$n    = 'args[' . Html::e($name) . ']';

		if (isset(FieldHints::SELECT[$name])) {
			$out = '<label>' . Html::e($name) . '<select name="' . $n . '">';
			foreach (FieldHints::SELECT[$name] as $opt) {
				$out .= '<option value="' . Html::e($opt) . '"' . ((string)$value === $opt ? ' selected' : '') . '>' . Html::e($opt) . '</option>';
			}
			return $out . '</select>' . $help . '</label>';
		}

		return match ($type) {
			'bool' => '<label>' . Html::e($name)
				// hidden-first: an unchecked box then still submits 0
				. '<input type="hidden" name="' . $n . '" value="0">'
				. '<input type="checkbox" name="' . $n . '" value="1"' . ($value ? ' checked' : '') . '></label>' . $help,
			'int' => '<label>' . Html::e($name) . '<input type="number" step="1" name="' . $n . '" value="' . Html::e($value) . '"></label>' . $help,
			'float' => '<label>' . Html::e($name) . '<input type="number" step="any" name="' . $n . '" value="' . Html::e($value) . '"></label>' . $help,
			default => '<label>' . Html::e($name) . '<input type="text" name="' . $n . '" value="' . Html::e($value) . '"></label>' . $help,
		};
	}
}
