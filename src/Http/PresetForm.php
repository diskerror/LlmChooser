<?php

declare(strict_types=1);

namespace LlmChooser\Http;

use LlmChooser\Cli\Format;
use LlmChooser\Engine\EngineCollection;
use LlmChooser\Model\ChatTemplateFamily;
use LlmChooser\Model\GgufReader;
use LlmChooser\Model\ModelEntry;
use LlmChooser\Preset\Preset;
use LlmChooser\Preset\PresetArgs;
use ReflectionProperty;
use Throwable;

/**
 * The preset form, GENERATED from Preset + PresetArgs + field_help.json —
 * no hand-written field per flag, so a new arg appears in the UI the
 * moment it exists in the class (worst case in the 'Unsorted' group,
 * never missing). Row shape, help toggles and non-default dots follow
 * GUI-DESIGN.md §4.
 */
final class PresetForm
{
	/**
	 * @param list<ModelEntry> $models
	 * @param array<string,string> $errors field ('args.x' for args) => message
	 */
	public static function render(Preset $p, array $models, bool $is_new, array $errors = [], bool $running = false, ?EngineCollection $engines = null, ?int $estimate_bytes = null, ?array $engine_help = null): string
	{
		$action = $is_new ? '/presets/new' : '/presets/' . Html::e($p->id) . '/edit';
		$help   = FieldHints::load();
		if ($engine_help !== null) {
			$help = FieldHints::withUpstream($help, $engine_help);
		}
		$defaults = new Preset();
		$arg_defaults = new PresetArgs();

		// ctx_size's effective default comes from the selected model, not
		// llama.cpp (GUI-DESIGN.md §4): show "model: 8192" when the preset
		// says 0 and the model's GGUF knows.
		$model_ctx = 0;
		foreach ($models as $e) {
			if ($e->path === $p->model_path) {
				$model_ctx = $e->info?->contextLength() ?? 0;
				break;
			}
		}

		// reasoning_effort's allowed set is template-specific (Phase 13):
		// a detected family renders the field as a <select>; unknown
		// families keep the generic free-text-style select. Prefer the
		// scanned model's cached header; only parse the file directly for
		// paths outside the scan (e.g. a preset pointing elsewhere).
		$family = null;
		if ($p->model_path !== '') {
			$template = null;
			foreach ($models as $e) {
				if ($e->path === $p->model_path) {
					$template = $e->info?->chatTemplate();
					break;
				}
			}
			if ($template === null && is_file($p->model_path)) {
				try {
					$template = GgufReader::read($p->model_path)->chatTemplate();
				} catch (Throwable) {
					$template = '';
				}
			}
			$family = $template !== null ? ChatTemplateFamily::detect($template) : null;
		}

		$out = '';
		if ($errors !== []) {
			$out .= '<ul class="errors">';
			foreach ($errors as $field => $msg) {
				$out .= '<li><strong>' . Html::e($field) . '</strong>: ' . Html::e($msg) . '</li>';
			}
			$out .= '</ul>';
		}

		$out .= '<div class="form-toolbar">'
			. '<label><input type="checkbox" id="advanced-toggle"> advanced mode</label>'
			. '<label><input type="checkbox" id="full-help-toggle"> full help</label>'
			. '</div>';

		$out .= '<form method="post" action="' . $action . '">';

		// ---- General (GUI-DESIGN.md §4: enabled first) ----
		$out .= '<details open><summary>General</summary>';
		$out .= self::fieldRow('enabled', Html::checkbox('enabled', (bool)$p->enabled),
			(bool)$p->enabled, (bool)$defaults->enabled, $help, self::err($errors, 'enabled'));
		$out .= self::fieldRow('id', self::textInput('id', $p->id, $help, ['required', 'pattern' => '(?!\.)(?!.*\.\.)[A-Za-z0-9_.-]+(?&lt;!\.)', 'readonly' => !$is_new]),
			$p->id, $defaults->id, $help, self::err($errors, 'id'));
		$out .= self::fieldRow('name', self::textInput('name', $p->name, $help),
			$p->name, $defaults->name, $help, self::err($errors, 'name'));
		$out .= self::fieldRow('engine_id', self::enginePicker($p, $engines, $help),
			$p->engine_id, $defaults->engine_id, $help, self::err($errors, 'engine_id'));
		$out .= self::fieldRow('binary_path', self::textInput('binary_path', $p->binary_path, $help, ['placeholder' => 'engine default', 'data-binary-check' => '1']) . EngineForm::binaryMsg($p->binary_path),
			$p->binary_path, $defaults->binary_path, $help, self::err($errors, 'binary_path'));
		$out .= self::fieldRow('model_path', self::modelSelect($p, $models, $help),
			$p->model_path, $defaults->model_path, $help, self::err($errors, 'model_path'));
		$out .= self::datalist($models);
		$out .= self::fieldRow('mmproj_path', self::textInput('mmproj_path', (string)($p->mmproj_path ?? ''), $help, ['list' => 'sidecars', 'placeholder' => 'none']),
			(string)($p->mmproj_path ?? ''), (string)($defaults->mmproj_path ?? ''), $help, self::err($errors, 'mmproj_path'));
		$out .= self::fieldRow('model_draft_path', self::textInput('model_draft_path', (string)($p->model_draft_path ?? ''), $help, ['list' => 'sidecars', 'placeholder' => 'none']),
			(string)($p->model_draft_path ?? ''), (string)($defaults->model_draft_path ?? ''), $help, self::err($errors, 'model_draft_path'));
		$out .= self::fieldRow('ttl_seconds', self::numberInput('ttl_seconds', $p->ttl_seconds, $help, '1'),
			$p->ttl_seconds, $defaults->ttl_seconds, $help, self::err($errors, 'ttl_seconds'));
		$out .= self::fieldRow('notes', self::textArea('notes', $p->notes, $help),
			$p->notes, $defaults->notes, $help, self::err($errors, 'notes'));
		$out .= '</details>';

		// ---- Args, grouped. Any PresetArgs name not in GROUPS lands in
		// 'Unsorted' so a later-added field never silently disappears. ----
		$grouped = [];
		foreach (FieldHints::GROUPS as $label => $names) {
			foreach ($names as $n) {
				$grouped[$n] = $label;
			}
		}
		$groups = FieldHints::GROUPS;
		$extra  = [];
		foreach ($arg_defaults->getPublicNames() as $name) {
			if (!isset($grouped[$name])) {
				$extra[] = $name;
			}
		}
		if ($extra !== []) {
			$groups['Unsorted'] = $extra;
		}

		foreach ($groups as $label => $names) {
			$suffix = '';
			if ($label === 'Context & memory') {
				// Running worst-case RAM for this preset (weights + full-ctx KV
				// + overhead); app.js refreshes it as the form changes.
				$suffix = ' — <span id="mem-estimate" data-url="/api/preset-estimate">'
					. ($estimate_bytes !== null ? Html::e(Format::gb($estimate_bytes)) . ' GB' : '?') . '</span> estimated';
			}
			$out .= '<details><summary>' . Html::e($label) . $suffix . '</summary>';
			foreach ($names as $name) {
				$value = $p->args->{$name};
				$override = '';
				if ($name === 'ctx_size' && (int)$value === 0 && $model_ctx > 0) {
					$override = 'default model: ' . $model_ctx;
				}
				if ($name === 'reasoning_effort' && $family !== null) {
					$control  = self::familyReasoningControl($value, $family, $help);
					$override = 'template family: ' . $family;
				} else {
					$control = self::argControl($name, $value, $help);
				}
				$out .= self::fieldRow($name, $control,
					$value, $arg_defaults->{$name}, $help, self::err($errors, 'args.' . $name), $override);
			}
			$out .= '</details>';
		}

		$out .= '<p class="form-actions"><button type="submit" class="primary">'
			. ($is_new ? 'Create preset' : 'Save') . '</button>';
		if (!$is_new && $running) {
			$out .= ' <button type="submit" name="save_restart" value="1">Save &amp; restart</button>';
		}
		$out .= ' <a class="btn" href="/presets">Cancel</a></p>';
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
		// Only touch enabled when the form actually posted it (the hidden-
		// first checkbox always does; an older/partial POST must not park
		// the preset by omission).
		if (array_key_exists('enabled', $post)) {
			$out['enabled'] = ((string)$post['enabled']) === '1';
		}
		return $out;
	}

	/**
	 * One OPNsense-style row: label + ⓘ + non-default dot on the left,
	 * control + default hint + inline error + hidden help on the right.
	 * $name is the form-visible name and the field_help.json key.
	 *
	 * @param array<string,array<string,string>> $help
	 */
	private static function fieldRow(
		string $name,
		string $control,
		mixed $value,
		mixed $default,
		array $help,
		string $error = '',
		string $default_override = '',
	): string {
		$entry = $help[$name] ?? null;
		// An undocumented field must never crash the page, and must never
		// be prominent either: label = raw name, level = advanced.
		$label = $entry['label'] ?? $name;
		$level = ($entry['level'] ?? 'advanced') === 'basic' ? 'basic' : 'advanced';
		$notes = $entry['notes'] ?? '';
		$up    = $entry['upstream'] ?? '';
		$flag  = $entry['flag'] ?? '';
		$updef = $entry['upstream_default'] ?? '';

		$non_default = $value != $default; // loose: int vs "0" from POST round-trips
		$dot = $non_default ? ' <span class="dot">●</span>' : '';

		$hint = $default_override;
		if ($hint === '' && $updef !== '' && $updef !== 'n/a') {
			$hint = 'default ' . $updef;
		}

		// Error rows get their help forced open (GUI-DESIGN.md §4).
		$help_html = '<div class="help"' . ($error !== '' ? '' : ' hidden') . '>'
			. ($flag !== '' && $flag !== '(none)' ? '<code class="flag">' . Html::e($flag) . '</code> ' : '')
			. ($up !== '' ? '<p class="help-upstream"><span class="help-src">llama.cpp:</span> ' . Html::e($up) . '</p>' : '')
			. ($notes !== '' ? '<p class="help-notes">' . nl2br(Html::e($notes)) . '</p>' : '')
			. (!empty($entry['stale']) ? '<p class="help-stale">⚠ llama.cpp\'s text for this option has changed since these notes were reviewed.</p>' : '')
			. '</div>';
		$err_html = $error !== ''
			? '<div class="field-error">⚠ <strong>' . Html::e($error) . '</strong></div>'
			: '';

		return '<div class="field-row ' . $level . ($non_default ? ' non-default' : '') . '">'
			. '<label class="field-label">'
			. '<button type="button" class="help-toggle" aria-label="Help for ' . Html::e($label) . '">ⓘ</button> '
			. Html::e($label) . $dot
			. '</label>'
			. '<div class="control">'
			. $control
			. ($hint !== '' ? ' <span class="default">' . Html::e($hint) . '</span>' : '')
			. $err_html . $help_html
			. '</div></div>';
	}

	/** @param array<string,string> $errors */
	private static function err(array $errors, string $key): string
	{
		return (string)($errors[$key] ?? '');
	}

	/** @param array<string,array<string,string>> $help */
	private static function title(array $help, string $key): string
	{
		$short = $help[$key]['short'] ?? '';
		return $short !== '' ? ' title="' . Html::e($short) . '"' : '';
	}

	/** @param array<string,array<string,string>> $help */
	private static function textInput(string $name, string $value, array $help, array $attrs = []): string
	{
		return '<input type="text" name="' . Html::e($name) . '" value="' . Html::e($value) . '"'
			. self::title($help, $name) . Html::attrs($attrs) . '>';
	}

	/** @param array<string,array<string,string>> $help */
	private static function numberInput(string $name, int|float $value, array $help, string $step): string
	{
		return '<input type="number" step="' . Html::e($step) . '" name="' . Html::e($name)
			. '" value="' . Html::e($value) . '"' . self::title($help, $name) . '>';
	}

	/** @param array<string,array<string,string>> $help */
	private static function textArea(string $name, string $value, array $help): string
	{
		return '<textarea name="' . Html::e($name) . '" rows="3" cols="60"'
			. self::title($help, $name) . '>' . Html::e($value) . '</textarea>';
	}

	/** Hidden input + button that opens a <dialog> popup listing engine names only (per GUI-DESIGN.md §4 picker). @param array<string,array<string,string>> $help */
	private static function enginePicker(Preset $p, ?EngineCollection $engines, array $help): string
	{
		$current = $p->engine_id;
		$list    = '';
		if ($engines !== null) {
			foreach ($engines as $eid => $e) {
				$list .= '<li><button type="button" class="engine-pick" data-engine-id="' . Html::e((string)$eid) . '">'
					. Html::e($e->name !== '' ? $e->name : (string)$eid) . '</button></li>';
			}
		}
		$label = $current;
		if ($engines !== null && $current !== '' && isset($engines[$current])) {
			$label = $engines[$current]->name !== '' ? $engines[$current]->name : $current;
		}

		return '<input type="hidden" name="engine_id" id="engine_id" value="' . Html::e($current) . '">'
			. '<button type="button" class="engine-picker-open" data-target="engine-picker-dialog"' . self::title($help, 'engine_id') . '>'
			. '<span class="engine-picker-label">' . ($label !== '' ? Html::e($label) : 'Choose engine…') . '</span>'
			. '</button>'
			. '<dialog id="engine-picker-dialog" class="engine-picker-dialog">'
			. '<h3>Choose an engine</h3>'
			. '<ul class="engine-picker-list">' . ($list !== '' ? $list : '<li class="muted">No engines yet. <a href="/engines/new">Create one</a>.</li>') . '</ul>'
			. '<button type="button" class="engine-picker-close">Cancel</button>'
			. '</dialog>';
	}

	/** @param list<ModelEntry> $models @param array<string,array<string,string>> $help */
	private static function modelSelect(Preset $p, array $models, array $help): string
	{
		$paths = [];
		$out   = '<select name="model_path"' . self::title($help, 'model_path') . '>';
		foreach ($models as $e) {
			$paths[$e->path] = true;
			$label           = $e->display_name . ' — ' . Format::humanBytes($e->size_bytes)
				. ($e->ngrams()?->sizeSuffix(Format::humanBytes(...)) ?? '') . ($e->error !== null ? ' [error]' : '');
			$ctx             = $e->info?->contextLength() ?? 0;
			$out            .= '<option value="' . Html::e($e->path) . '"'
				. ($e->path === $p->model_path ? ' selected' : '') . ($ctx > 0 ? ' data-ctx="' . $ctx . '"' : '') . '>' . Html::e($label) . '</option>';
		}
		if ($p->model_path !== '' && !isset($paths[$p->model_path])) {
			$out .= '<option value="' . Html::e($p->model_path) . '" selected>(not found) ' . Html::e($p->model_path) . '</option>';
		}
		$out .= '</select>';
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

	/**
	 * reasoning_effort when the model's chat template family is known
	 * (Phase 13): a <select> of 'default' plus the family's accepted
	 * values. A saved value outside that set is still shown (selected),
	 * never silently rewritten — validation flags it, the form stays
	 * honest about what's actually stored.
	 *
	 * @param array<string,array<string,string>> $help
	 */
	private static function familyReasoningControl(mixed $value, string $family, array $help): string
	{
		$n     = 'args[' . Html::e('reasoning_effort') . ']';
		$title = self::title($help, 'reasoning_effort');
		$out   = '<select name="' . $n . '"' . $title . '>';
		$opts  = array_merge(['default'], ChatTemplateFamily::reasoningEfforts($family));
		$out  .= Html::options($opts, (string)$value);
		if ((string)$value !== '' && !in_array((string)$value, $opts, true)) {
			$out .= '<option value="' . Html::e($value) . '" selected>' . Html::e($value) . ' (invalid for this model)</option>';
		}
		return $out . '</select>';
	}

	/** The control for one PresetArgs field (the row wrapper is fieldRow). */
	private static function argControl(string $name, mixed $value, array $help): string
	{
		$n    = 'args[' . Html::e($name) . ']';
		$title = self::title($help, $name);

		if (isset(FieldHints::SELECT[$name])) {
			return '<select name="' . $n . '"' . $title . '>' . Html::options(FieldHints::SELECT[$name], (string)$value) . '</select>';
		}

		$type = (new ReflectionProperty(PresetArgs::class, $name))->getType()?->getName() ?? 'string';
		return match ($type) {
			'bool'    => Html::checkbox('args[' . $name . ']', (bool)$value, $title),
			'int'     => '<input type="number" step="1" name="' . $n . '" value="' . Html::e($value) . '"' . $title
				. (isset(FieldHints::POW2_STEP[$name])
					? ' min="' . FieldHints::POW2_STEP[$name] . '" data-pow2-step' . ($name === 'ctx_size' ? ' data-max-from="model_path"' : '')
					: '') . '>',
			'float'   => '<input type="number" step="any" name="' . $n . '" value="' . Html::e($value) . '"' . $title . '>',
			default   => '<input type="text" name="' . $n . '" value="' . Html::e($value) . '"' . $title . '>',
		};
	}
}
