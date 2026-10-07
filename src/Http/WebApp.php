<?php

declare(strict_types=1);

namespace LlmChooser\Http;

use LlmChooser\App;
use LlmChooser\Cli\Format;
use LlmChooser\Memory\Allocation;
use LlmChooser\Memory\MemoryEstimator;
use LlmChooser\Model\GgufInfo;
use LlmChooser\Model\ModelEntry;
use LlmChooser\Process\AdmissionException;
use LlmChooser\Process\Instance;
use LlmChooser\Preset\Preset;
use Throwable;

/**
 * All web routes and handlers. Every value printed goes through Html::e()
 * (rule 10); every state change is POST + 303 redirect (rules 11-12);
 * start never waits for the model to load (rule 13).
 */
final class WebApp
{
	private Router $router;

	public function __construct(private readonly App $app)
	{
		$this->router = new Router();
		$this->addRoutes();
	}

	public function handle(Request $r): Response
	{
		try {
			return $this->router->dispatch($r);
		} catch (Throwable $e) {
			return Response::html(
				Html::page('Error', '<pre>' . Html::e($e->getMessage()) . '</pre>', '', '', $r->path, $this->headerSummary($this->app->state->all())),
				500
			);
		}
	}

	private function addRoutes(): void
	{
		$rt = $this->router;
		$rt->add('GET', '/', fn(Request $r): Response => $this->dashboard($r));
		$rt->add('GET', '/presets', fn(Request $r): Response => $this->presetList($r));
		$rt->add('GET', '/presets/new', fn(Request $r): Response => $this->presetNew($r));
		$rt->add('POST', '/presets/new', fn(Request $r): Response => $this->presetCreate($r));
		$rt->add('GET', '/presets/{id}/edit', fn(Request $r, array $p): Response => $this->presetEdit($r, $p['id']));
		$rt->add('POST', '/presets/{id}/edit', fn(Request $r, array $p): Response => $this->presetSave($r, $p['id']));
		$rt->add('POST', '/presets/{id}/delete', fn(Request $r, array $p): Response => $this->presetDelete($p['id']));
		$rt->add('POST', '/presets/{id}/enable', fn(Request $r, array $p): Response => $this->setEnabled($p['id'], true));
		$rt->add('POST', '/presets/{id}/disable', fn(Request $r, array $p): Response => $this->setEnabled($p['id'], false));
		$rt->add('POST', '/presets/{id}/start', fn(Request $r, array $p): Response => $this->start($r, $p['id'], 'start'));
		$rt->add('POST', '/presets/{id}/restart', fn(Request $r, array $p): Response => $this->start($r, $p['id'], 'restart'));
		$rt->add('POST', '/presets/{id}/stop', fn(Request $r, array $p): Response => $this->stop($p['id']));
		$rt->add('POST', '/presets/{id}/estimate', fn(Request $r, array $p): Response => $this->estimate($r, $p['id']));
		$rt->add('POST', '/presets/{id}/test', fn(Request $r, array $p): Response => $this->test($p['id']));
		$rt->add('GET', '/presets/{id}/log', fn(Request $r, array $p): Response => $this->log($r, $p['id']));
		$rt->add('GET', '/models', fn(Request $r): Response => $this->models($r));
		$rt->add('GET', '/health', fn(Request $r): Response => Response::json(['status' => 'ok']));
		$rt->add('GET', '/api/status', fn(Request $r): Response => $this->apiStatus());
		$rt->add('GET', '/api/dashboard-panels', fn(Request $r): Response => $this->apiDashboardPanels());
		$rt->add('GET', '/assets/style.css', fn(Request $r): Response => $this->css());
		$rt->add('GET', '/assets/app.js', fn(Request $r): Response => $this->js());
	}

	// ---------------------------------------------------------------- pages

	private function dashboard(Request $r): Response
	{
		$instances = $this->app->processes->refresh();
		$presets   = $this->app->presets->loadAll();

		$loaded   = $this->loadedRows($instances, $presets);
		$available = $this->availableRows($instances, $presets);

		$head = '';
		foreach ($instances as $i) {
			if ($i->status === Instance::LOADING) {
				$head = '<meta http-equiv="refresh" content="5">';
				break;
			}
		}

		$body = $this->memorySection($instances)
			. '<section><h2>Loaded</h2>' . $this->loadedTable($loaded) . '</section>'
			. '<section><h2>Available</h2>'
			. '<table><tr><th>Preset</th><th>Model</th><th>Ctx</th><th>Est. memory</th><th>Fits?</th><th>Actions</th></tr>'
			. '<tbody id="available-tbody">'
			. ($available !== '' ? $available : '<tr><td colspan="6">Nothing available to start.</td></tr>')
			. '</tbody></table></section>'
			. $this->brokenBanner();

		return Response::html(Html::page('Dashboard', $body, $r->query('msg'), $head, $r->path, $this->headerSummary($instances)));
	}

	/** @param iterable<string,Instance> $instances @param iterable<string,Preset> $presets */
	private function loadedTable(string $rows): string
	{
		return '<table><tr><th>Preset</th><th>Model</th><th>Status</th><th>Port</th><th>Ctx</th>'
			. '<th>Memory (est.)</th><th>Uptime</th><th>Last used</th><th>Actions</th></tr>'
			. '<tbody id="loaded-tbody">'
			. ($rows !== '' ? $rows : '<tr><td colspan="9">Nothing loaded.</td></tr>')
			. '</tbody></table>';
	}

	private function presetList(Request $r): Response
	{
		$instances = $this->app->processes->refresh();

		// Enabled first, disabled last, original order within each group
		// (GUI-DESIGN.md §4).
		$presets = $this->app->presets->loadAll();
		$ordered = [];
		foreach ([true, false] as $want) {
			foreach ($presets as $pid => $p) {
				if ($p->enabled === $want) {
					$ordered[$pid] = $p;
				}
			}
		}

		$rows = '';
		foreach ($ordered as $pid => $p) {
			$i      = $instances[$pid] ?? null;
			$status = $i?->status ?? Instance::STOPPED;
			$run    = $status === Instance::LOADING || $status === Instance::READY;
			$port   = (string)($i?->port ?: ($p->assigned_port ?? '-'));
			$toggle = Html::postButton(
				"/presets/{$pid}/" . ($p->enabled ? 'disable' : 'enable'),
				$p->enabled ? 'Disable' : 'Enable',
				'',
				'',
				$p->enabled && $run ? 'disable-form' : '',
				$p->enabled && $run ? ['data-running' => '1'] : [],
			);
			$acts   = '<a class="btn" href="/presets/' . Html::e($pid) . '/edit">Edit</a> ';
			$acts  .= '<a class="btn" href="/presets/new?copy=' . Html::e(rawurlencode((string)$pid)) . '">Duplicate</a> ';
			// Delete only appears when stopped (GUI-DESIGN.md §4).
			if (!$run) {
				$acts .= Html::postButton("/presets/{$pid}/delete", 'Delete', 'danger', "Delete preset {$pid}?");
			}
			$rows  .= '<tr' . ($p->enabled ? '' : ' class="disabled-row"') . '>'
				. '<td>' . $toggle . '</td>'
				. '<td><a href="/presets/' . Html::e($pid) . '/edit">' . Html::e($pid) . '</a></td>'
				. '<td>' . Html::e($p->name) . '</td>'
				. '<td>' . Html::e($p->model_path !== '' ? basename($p->model_path) : '-') . '</td>'
				. '<td>' . Html::e($p->engine_id) . '</td>'
				. '<td>' . Html::e($p->args->alias !== '' ? $p->args->alias : '-') . '</td>'
				. '<td>' . Html::e($port) . '</td>'
				. '<td class="status-' . Html::e($status) . '">' . Html::e($status) . '</td>'
				. '<td>' . $acts . '</td></tr>';
		}

		$body = '<p><a class="btn primary" href="/presets/new">New preset</a></p>'
			. $this->brokenBanner()
			. '<table><tr><th>enabled</th><th>id</th><th>name</th><th>model</th><th>engine</th><th>alias</th><th>port</th><th>status</th><th>actions</th></tr>'
			. ($rows !== '' ? $rows : '<tr><td colspan="9">No presets yet.</td></tr>')
			. '</table>';
		return Response::html(Html::page('Presets', $body, $r->query('msg'), '', $r->path, $this->headerSummary($instances)));
	}

	/**
	 * One-click enable/disable (GUI-DESIGN.md §4). Disabling always stops
	 * first if running; the JS "Stop it now?" confirm runs before submit,
	 * and with JS off the stop-then-disable happens unconditionally — the
	 * accepted degraded path, same shape as the meta-refresh fallback.
	 */
	private function setEnabled(string $id, bool $enabled): Response
	{
		$stopped = false;
		if (!$enabled) {
			$i = $this->app->state->get($id);
			if ($i !== null && $i->status !== Instance::STOPPED) {
				$this->app->processes->stop($id);
				$stopped = true;
			}
		}
		$this->app->presets->setEnabled($id, $enabled);
		$msg = ($enabled ? 'Enabled ' : 'Disabled ') . $id . ($stopped ? ' (was running)' : '');
		return Response::redirect('/presets', $msg);
	}

	private function presetNew(Request $r): Response
	{
		$models = $this->app->modelScanner()->scan();
		$copy   = $r->query('copy');
		if ($copy !== '') {
			try {
				$src           = $this->app->presets->load($copy);
				$preset        = new Preset($src->toArray());
				$preset->id    = $copy . '-copy';
				$preset->assigned_port = null;
			} catch (Throwable) {
				$preset = new Preset();
			}
		} else {
			$preset = new Preset();
		}

		// "New preset from this model" (GUI-DESIGN.md §5): pre-fill the
		// model and its sidecars, and a ctx that fits current free memory
		// at q8_0 KV. Halving loop, capped — if even a small ctx does not
		// fit, ctx stays 0 and Start's admission check surfaces the
		// problem, same as a hand-made preset.
		$model = $r->query('model');
		if ($model !== '') {
			$preset->model_path = $model;
			foreach ($models as $e) {
				if ($e->path !== $model || $e->info === null) {
					continue;
				}
				if ($e->sidecars['mmproj'] !== []) {
					$preset->mmproj_path = $e->sidecars['mmproj'][0];
				}
				if ($e->sidecars['draft'] !== []) {
					$preset->model_draft_path = $e->sidecars['draft'][0];
				}
				$preset->args->ctx_size = $this->fittingCtx($e);
				break;
			}
		}

		return Response::html(Html::page('New preset', PresetForm::render($preset, $models, true), $r->query('msg'), '', $r->path, $this->headerSummary($this->app->state->all())));
	}

	/**
	 * Largest ctx from the model's trained maximum down, halving (max 6
	 * steps), whose weights + q8_0 KV + fixed overhead fit in the biggest
	 * budget device's free memory. 0 when no probe (macOS) or nothing fits
	 * — 0 means "model default", which Start's admission check gates.
	 */
	private function fittingCtx(ModelEntry $e): int
	{
		$ctx = $e->info?->contextLength() ?? 0;
		if ($ctx <= 0) {
			return 0;
		}
		try {
			$topo = $this->app->memory->topology();
		} catch (Throwable) {
			return 0;
		}
		$available = 0;
		foreach ($topo->devices as $d) {
			if ($topo->budgetId($d->id) === $d->id) {
				$available = max($available, $d->availableBytes);
			}
		}
		$est  = new MemoryEstimator();
		$per1k = $est->kvBytesPer1k($e->info, 'q8_0');
		for ($i = 0; $i < 6; ++$i) {
			$need = $e->sizeBytes + (int)ceil($per1k * $ctx / 1000) + MemoryEstimator::OVERHEAD_FIXED_BYTES;
			if ($need <= $available) {
				return $ctx;
			}
			$ctx = intdiv($ctx, 2);
		}
		return 0;
	}

	private function presetCreate(Request $r): Response
	{
		$preset = new Preset(PresetForm::fromPost($r->post));
		$errors = PresetValidator::validate($preset, $this->app->engines, true, $this->app->presets);
		if ($errors !== []) {
			return Response::html(
				Html::page('New preset', PresetForm::render($preset, $this->app->modelScanner()->scan(), true, $errors), '', '', $r->path, $this->headerSummary($this->app->state->all())),
				422
			);
		}
		$this->app->presets->save($preset);
		return Response::redirect('/presets', 'Created ' . $preset->id);
	}

	private function presetEdit(Request $r, string $id): Response
	{
		try {
			$preset = $this->app->presets->load($id);
		} catch (Throwable) {
			return Response::html(Html::page('Not found', '<p>No preset ' . Html::e($id) . '.</p>', '', '', $r->path, $this->headerSummary($this->app->state->all())), 404);
		}
		$running = $this->instanceIsLive($id);
		return Response::html(
			Html::page('Edit ' . $id, PresetForm::render($preset, $this->app->modelScanner()->scan(), false, [], $running), $r->query('msg'), '', $r->path, $this->headerSummary($this->app->state->all()))
		);
	}

	private function instanceIsLive(string $id): bool
	{
		$i = $this->app->state->get($id);
		return $i !== null && ($i->status === Instance::LOADING || $i->status === Instance::READY);
	}

	private function presetSave(Request $r, string $id): Response
	{
		try {
			$preset = $this->app->presets->load($id);
		} catch (Throwable) {
			return Response::html(Html::page('Not found', '<p>No preset ' . Html::e($id) . '.</p>', '', '', $r->path, $this->headerSummary($this->app->state->all())), 404);
		}
		$preset->assign(PresetForm::fromPost($r->post));
		$preset->id = $id; // editing never renames; the id input is readonly
		// assigned_port is not in fromPost(), so assign() leaves it untouched.

		$errors = PresetValidator::validate($preset, $this->app->engines, false, $this->app->presets);
		if ($errors !== []) {
			return Response::html(
				Html::page('Edit ' . $id, PresetForm::render($preset, $this->app->modelScanner()->scan(), false, $errors, $this->instanceIsLive($id)), '', '', $r->path, $this->headerSummary($this->app->state->all())),
				422
			);
		}
		$this->app->presets->save($preset);
		$running = $this->instanceIsLive($id);

		// "Save & restart" (GUI-DESIGN.md §4 bottom bar): only meaningful
		// while running; a refused start must not lose the saved file.
		if ($running && ($r->post['save_restart'] ?? '') === '1') {
			try {
				$this->app->processes->restart($id);
				return Response::redirect('/', 'Saved and restarting ' . $id);
			} catch (AdmissionException $e) {
				return Response::redirect('/', 'Saved ' . $id . ' — restart refused: ' . $e->getMessage());
			}
		}
		$msg = 'Saved ' . $id . ($running ? ' — restart it to apply' : '');
		return Response::redirect('/presets', $msg);
	}

	private function presetDelete(string $id): Response
	{
		$i = $this->app->state->get($id);
		if ($i !== null && $i->status !== Instance::STOPPED) {
			return Response::redirect('/presets', 'Stop it first');
		}
		try {
			$this->app->presets->delete($id);
		} catch (Throwable) {
			return Response::redirect('/presets', 'Stop it first');
		}
		return Response::redirect('/presets', 'Deleted ' . $id);
	}

	private function start(Request $r, string $id, string $op): Response
	{
		$force = ($r->post['force'] ?? '') === '1';
		try {
			$this->app->processes->{$op}($id, force: $force);
			return Response::redirect('/', ucfirst($op) . 'ing ' . $id);
		} catch (AdmissionException $e) {
			$body = '<pre>' . Html::e($e->getMessage()) . '</pre>'
				. '<form method="post" action="/presets/' . Html::e($id) . '/' . Html::e($op) . '">'
				. '<input type="hidden" name="force" value="1">'
				. '<button type="submit" class="danger">Start anyway</button></form>';
			return Response::html(Html::page('Not enough memory', $body, '', '', $r->path, $this->headerSummary($this->app->state->all())), 200);
		}
	}

	private function stop(string $id): Response
	{
		$this->app->processes->stop($id);
		return Response::redirect('/', 'Stopped ' . $id);
	}

	private function estimate(Request $r, string $id): Response
	{
		try {
			$preset = $this->app->presets->load($id);
		} catch (Throwable) {
			return Response::html(Html::page('Not found', '<p>No preset ' . Html::e($id) . '.</p>', '', '', $r->path, $this->headerSummary($this->app->state->all())), 404);
		}
		try {
			$alloc = $this->app->memory->estimate($preset);
		} catch (Throwable $e) {
			return Response::html(Html::page('Estimate', '<p class="flash">Estimate failed: ' . Html::e($e->getMessage()) . '</p>', '', '', $r->path, $this->headerSummary($this->app->state->all())), 200);
		}
		$rows = '';
		foreach ($alloc->breakdown as $k => $v) {
			$rows .= '<tr><td>' . Html::e($k) . '</td><td>' . Html::e(Format::humanBytes((int)$v)) . '</td></tr>';
		}
		$devs = '';
		foreach ($alloc->perDevice as $d => $b) {
			$devs .= '<tr><td>' . Html::e($d) . '</td><td>' . Html::e(Format::humanBytes($b)) . '</td></tr>';
		}
		$body = '<table><tr><th>Part</th><th>Bytes</th></tr>' . $rows
			. '<tr><th>Total</th><th>' . Html::e(Format::humanBytes($alloc->totalBytes())) . '</th></tr></table>'
			. '<h2>Per device</h2><table><tr><th>Device</th><th>Bytes</th></tr>' . $devs . '</table>';
		return Response::html(Html::page('Estimate ' . $id, $body, '', '', $r->path, $this->headerSummary($this->app->state->all())));
	}

	private function log(Request $r, string $id): Response
	{
		$i = $this->app->state->get($id);
		if ($i === null || $i->log_file === '' || !is_file($i->log_file)) {
			return Response::html(Html::page('Not found', '<p>No log for ' . Html::e($id) . '.</p>', '', '', $r->path, $this->headerSummary($this->app->state->all())), 404);
		}
		$text = Format::tail($i->log_file, 200);
		if ($r->query('raw') === '1') {
			return Response::text($text);
		}
		return Response::html(Html::page('Log ' . $id, '<pre class="log">' . Html::e($text) . '</pre>', '', '', $r->path, $this->headerSummary($this->app->state->all())));
	}

	private function models(Request $r): Response
	{
		$usedBy = $this->usedByMap();
		$rows   = '';
		foreach ($this->app->modelScanner()->scan() as $e) {
			$info = $e->info;
			$rows .= '<tbody class="model-block">';
			$rows .= '<tr class="model-row"' . ($info !== null ? ' data-sort-ctx="' . $info->contextLength() . '"' : '') . '><td>'
				. Html::e($e->displayName)
				. ($e->isSharded() ? ' <span class="muted">(' . count($e->shardPaths) . ' parts)</span>' : '')
				. ($e->error !== null ? ' <span class="badge badge-error">Error</span>' : '')
				. '</td>';
			$rows .= '<td>' . Html::e($info?->architecture() ?? '-') . '</td>';
			$rows .= '<td data-sort="' . $this->paramSortValue($e) . '">' . Html::e($this->paramCell($e)) . '</td>';
			$rows .= '<td>' . Html::e($info !== null ? $info->fileTypeName() : '-') . '</td>';
			$rows .= '<td data-sort="' . $e->sizeBytes . '">' . Html::e(Format::humanBytes($e->sizeBytes)) . '</td>';
			$rows .= '<td data-sort="' . ($info?->contextLength() ?? 0) . '">'
				. Html::e((string)($info?->contextLength() ?? '-'))
				. ($info !== null && $info->hasRopeScaling() ? ' ★' : '')
				. '</td>';
			$rows .= '<td data-sort="' . ($info?->blockCount() ?? 0) . '">' . Html::e((string)($info?->blockCount() ?? '-')) . '</td>';
			$rows .= '<td>' . Html::e($this->headsCell($info)) . '</td>';
			$rows .= '<td>' . Html::e($this->headDimCell($info)) . '</td>';
			$rows .= '<td>' . $this->extrasCell($e) . '</td>';
			$rows .= '<td>' . $this->usedByCell($usedBy[$e->path] ?? []) . '</td></tr>';
			if ($e->error !== null) {
				$rows .= '<tr class="messagerow"><td colspan="11">⚠ ' . Html::e($e->error) . '</td></tr>';
			}
			if ($info !== null) {
				$rows .= '<tr class="drawer-row"><td colspan="11">' . $this->modelDrawer($e, $info) . '</td></tr>';
			}
			$rows .= '</tbody>';
		}

		$th = static fn(string $label, bool $sortable = true): string =>
			'<th' . ($sortable ? ' class="sortable"' : '') . '>' . Html::e($label) . '</th>';
		$body = '<p>The cache refreshes automatically when a file\'s size or mtime changes. Click a row to expand its details.</p>'
			. '<table id="models-table"><thead><tr>'
			. $th('Model', false) . $th('Arch') . $th('Params') . $th('Quant') . $th('Size')
			. $th('Max ctx') . $th('Layers') . $th('Heads (Q/KV)', false) . $th('Head dim', false)
			. $th('Extras', false) . $th('Used by', false)
			. '</tr></thead>'
			. ($rows !== '' ? $rows : '<tbody><tr><td colspan="11">No models found.</td></tr></tbody>')
			. '</table>';
		return Response::html(Html::page('Models', $body, $r->query('msg'), '', $r->path, $this->headerSummary($this->app->state->all())));
	}

	/**
	 * Params column: size_label when present, else the tensor-sum estimate
	 * (the tensor info section only — weights are never read).
	 */
	private function paramCell(ModelEntry $e): string
	{
		$info = $e->info;
		if ($info === null) {
			return '-';
		}
		$label = $info->sizeLabel();
		if ($label !== '') {
			return $label;
		}
		$n = $info->approxParamCount();
		return $n > 0 ? '~' . Format::paramsHuman($n) : '-';
	}

	private function paramSortValue(ModelEntry $e): int
	{
		return $e->info?->approxParamCount() ?? 0;
	}

	/** "32/4 (GQA 8×)" when Q/KV > 1, else "32/32". */
	private function headsCell(?GgufInfo $info): string
	{
		if ($info === null || $info->headCount() <= 0) {
			return '-';
		}
		$q  = $info->headCount();
		$kv = $info->headCountKv();
		$r  = $kv > 0 ? intdiv($q, $kv) : 0;
		return $r > 1 ? $q . '/' . $kv . ' (GQA ' . $r . '×)' : $q . '/' . $kv;
	}

	/** keyLength, plus "/ valueLength" when they differ (MLA models). */
	private function headDimCell(?GgufInfo $info): string
	{
		if ($info === null || $info->keyLength() <= 0) {
			return '-';
		}
		$k = $info->keyLength();
		$v = $info->valueLength();
		return $k === $v ? (string)$k : $k . ' / ' . $v;
	}

	/** 👁 mmproj sidecar(s), ⚡ draft/MTP sidecar(s). */
	private function extrasCell(ModelEntry $e): string
	{
		$out = [];
		if ($e->sidecars['mmproj'] !== []) {
			$out[] = '<span title="vision projector">👁</span>';
		}
		if ($e->sidecars['draft'] !== []) {
			$out[] = '<span title="draft/MTP head">⚡</span>';
		}
		return $out === [] ? '' : implode(' ', $out);
	}

	/**
	 * "Used by": preset ids referencing a model path, as edit links
	 * (GUI-DESIGN.md §5). Built once per request here rather than on
	 * ModelEntry — ModelEntry has no access to the preset repository.
	 *
	 * @return array<string,list<string>> path => preset ids
	 */
	private function usedByMap(): array
	{
		$map = [];
		foreach ($this->app->presets->loadAll() as $pid => $p) {
			if ($p->model_path !== '') {
				$map[$p->model_path][] = (string)$pid;
			}
		}
		return $map;
	}

	/** @param list<string> $ids */
	private function usedByCell(array $ids): string
	{
		$links = [];
		foreach ($ids as $id) {
			$links[] = '<a href="/presets/' . Html::e($id) . '/edit">' . Html::e($id) . '</a>';
		}
		return $links === [] ? '' : implode(', ', $links);
	}

	/**
	 * Details drawer (GUI-DESIGN.md §5): KV per 1K at three cache types,
	 * total at max ctx vs free memory, chat template, MoE/RoPE/vocab,
	 * raw metadata, "New preset from this model".
	 */
	private function modelDrawer(ModelEntry $e, GgufInfo $info): string
	{
		$est = new \LlmChooser\Memory\MemoryEstimator();
		$per = [];
		foreach (['f16', 'q8_0', 'q4_0'] as $type) {
			$per[$type] = $est->kvBytesPer1k($info, $type);
		}

		$out = '<details class="drawer"><summary>Details</summary>';
		$out .= '<table class="kv"><tr><th>KV cache / 1K tokens</th><td>'
			. 'f16: ' . Html::e(Format::humanBytes($per['f16']))
			. ' · q8_0: ' . Html::e(Format::humanBytes($per['q8_0']))
			. ' · q4_0: ' . Html::e(Format::humanBytes($per['q4_0']))
			. '</td></tr>';

		// Total at the model's trained max ctx, weights + KV at f16
		// (llama.cpp's default cache type) + the estimator's fixed overhead.
		$ctx   = $info->contextLength();
		$total = $e->sizeBytes + (int)ceil($per['f16'] * $ctx / 1000) + \LlmChooser\Memory\MemoryEstimator::OVERHEAD_FIXED_BYTES;
		$out .= '<tr><th>Total at ' . Html::e((string)$ctx) . ' ctx (f16 KV)</th><td>'
			. Html::e(Format::humanBytes($total)) . ' — ' . Html::e(Format::humanBytes($e->sizeBytes)) . ' weights'
			. $this->freeMemoryNote($total)
			. '</td></tr>';

		$out .= '<tr><th>Chat template</th><td>'
			. ($info->hasChatTemplate() ? 'yes — ' . Html::e($info->chatTemplateFirstLine()) : 'no')
			. '</td></tr>';
		if ($info->expertCount() > 0) {
			$out .= '<tr><th>Experts</th><td>' . Html::e($info->expertCount() . ' (active ' . $info->expertUsedCount() . ')') . '</td></tr>';
		}
		$out .= '<tr><th>RoPE base</th><td>' . Html::e((string)$info->ropeFreqBase()) . '</td></tr>';
		$out .= '<tr><th>Vocab</th><td>' . Html::e((string)$info->vocabSize()) . '</td></tr>';
		$out .= '</table>';

		$out .= '<details><summary>Raw metadata (' . count($info->metadata) . ' keys)</summary>'
			. $this->rawMetadataTable($info->metadata) . '</details>';

		$out .= '<p><a class="btn" href="/presets/new?model=' . Html::e(rawurlencode($e->path)) . '">New preset from this model</a></p>';
		$out .= '</details>';
		return $out;
	}

	/** " vs free memory" tail for the drawer; silently omitted when no probe (macOS). */
	private function freeMemoryNote(int $total): string
	{
		try {
			$topo = $this->app->memory->topology();
		} catch (Throwable) {
			return '';
		}
		$notes = [];
		foreach ($topo->devices as $d) {
			if ($topo->budgetId($d->id) !== $d->id) {
				continue;
			}
			$notes[] = Html::e($d->id) . ' free ' . Html::e(Format::humanBytes($d->availableBytes))
				. ($d->availableBytes >= $total ? ' ✓' : ' ✗');
		}
		return $notes === [] ? '' : ' vs ' . implode(', ', $notes);
	}

	/** @param array<string,mixed> $metadata */
	private function rawMetadataTable(array $metadata): string
	{
		$rows = '';
		$keys = array_keys($metadata);
		sort($keys);
		foreach ($keys as $key) {
			$rows .= '<tr><td><code>' . Html::e((string)$key) . '</code></td><td>'
				. Html::e($this->metaValueText($metadata[$key])) . '</td></tr>';
		}
		return '<table class="kv">' . $rows . '</table>';
	}

	/** Long values truncated; skipped arrays are absent from $metadata entirely. */
	private function metaValueText(mixed $v): string
	{
		if (is_bool($v)) {
			return $v ? 'true' : 'false';
		}
		if (is_array($v)) {
			$first = implode(', ', array_map(static fn($x) => (string)$x, array_slice($v, 0, 8)));
			return '[' . count($v) . '] ' . $first . (count($v) > 8 ? ', …' : '');
		}
		$s = (string)$v;
		return strlen($s) > 160 ? substr($s, 0, 160) . '…' : $s;
	}

	private function apiStatus(): Response
	{
		$list = [];
		foreach ($this->app->processes->refresh() as $i) {
			$list[] = [
				'preset_id'  => $i->preset_id,
				'status'     => $i->status,
				'pid'        => $i->pid,
				'port'       => $i->port,
				'started_at' => $i->started_at,
				'message'    => $i->message,
			];
		}
		return Response::json($list);
	}

	/**
	 * Dashboard auto-refresh payload (GUI-DESIGN.md §3): HTML fragments
	 * built by the SAME row renderers the full page uses, so the two can
	 * never drift. Deliberately not raw JSON + client templating — this
	 * app renders all markup in PHP.
	 */
	private function apiDashboardPanels(): Response
	{
		$instances = $this->app->processes->refresh();
		$presets   = $this->app->presets->loadAll();
		return Response::json([
			'loaded_html'   => $this->loadedRows($instances, $presets),
			'available_html' => $this->availableRows($instances, $presets),
		]);
	}

	/**
	 * Test button (GUI-DESIGN.md §3): one real one-token chat completion
	 * straight to the instance — never through ChatProxy, since host and
	 * port are already known and the response is a single JSON object.
	 * "ttft_ms" is really time-to-first-response-byte (curl's header
	 * callback fires on headers, not tokens); labelled that way in the
	 * payload comment for the JS.
	 */
	private function test(string $id): Response
	{
		$i = $this->app->state->get($id);
		if ($i === null || $i->status !== Instance::READY) {
			return Response::json(['ok' => false, 'error' => 'Preset is not running.']);
		}

		$model = $id;
		try {
			$p = $this->app->presets->load($id);
			$model = $p->args->alias !== '' ? $p->args->alias : $id;
		} catch (Throwable) {
			// preset file gone; the instance still answers to its id alias
		}

		$body = json_encode([
			'model'       => $model,
			'messages'    => [['role' => 'user', 'content' => 'hi']],
			'max_tokens'  => 1,
			'stream'      => false,
		], JSON_UNESCAPED_SLASHES);

		$begin     = microtime(true);
		$firstByte = null;
		$response  = '';
		$ch        = curl_init("http://{$i->host}:{$i->port}/v1/chat/completions");
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 120, // a cold model can take a while for one token
			CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$firstByte): int {
				if ($firstByte === null && trim($line) !== '') {
					$firstByte = microtime(true);
				}
				return strlen($line);
			},
			CURLOPT_WRITEFUNCTION  => function ($ch, string $data) use (&$response): int {
				$response .= $data;
				return strlen($data);
			},
		]);
		$ok   = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err  = curl_error($ch);
		curl_close($ch);
		$end = microtime(true);

		if ($ok === false) {
			return Response::json(['ok' => false, 'error' => 'upstream failed: ' . ($err !== '' ? $err : 'no response')]);
		}
		if ($code < 200 || $code >= 300) {
			$detail = trim(preg_replace('/\s+/', ' ', $response) ?? '');
			return Response::json(['ok' => false, 'error' => 'HTTP ' . $code . ($detail !== '' ? ': ' . substr($detail, 0, 200) : '')]);
		}

		return Response::json([
			'ok'       => true,
			// time to first response byte, not literally first token (see doc comment)
			'ttft_ms'  => (int)round((($firstByte ?? $end) - $begin) * 1000),
			'total_ms' => (int)round(($end - $begin) * 1000),
		]);
	}

	private function css(): Response
	{
		$file = dirname(__DIR__, 2) . '/public/assets/style.css';
		$css  = is_file($file) ? (string)file_get_contents($file) : '';
		return new Response(200, $css, ['Content-Type' => 'text/css; charset=utf-8']);
	}

	private function js(): Response
	{
		$file = dirname(__DIR__, 2) . '/public/assets/app.js';
		$js   = is_file($file) ? (string)file_get_contents($file) : '';
		return new Response(200, $js, ['Content-Type' => 'text/javascript; charset=utf-8']);
	}

	/**
	 * Loaded panel rows: one per instance that is LOADING, READY or ERROR
	 * — including instances whose preset file is gone (GUI-DESIGN.md §3).
	 * ERROR deviates from the doc's "running or loading" wording: a dead
	 * instance's message must stay visible somewhere, and Available is
	 * the wrong place for it (the preset IS there, listed by Start).
	 *
	 * @param iterable<string,Instance> $instances
	 * @param iterable<string,Preset> $presets
	 */
	private function loadedRows(iterable $instances, iterable $presets): string
	{
		$byPreset = [];
		foreach ($presets as $pid => $p) {
			$byPreset[$pid] = $p;
		}
		$rows = '';
		foreach ($instances as $pid => $i) {
			if (!in_array($i->status, [Instance::LOADING, Instance::READY, Instance::ERROR], true)) {
				continue;
			}
			$p     = $byPreset[$pid] ?? null;
			$model = $p !== null && $p->model_path !== '' ? basename($p->model_path) : '-';
			$alloc = $i->allocation !== [] ? Allocation::fromArray($i->allocation) : null;
			if ($i->status === Instance::ERROR) {
				$acts = Html::postButton("/presets/{$pid}/start", 'Start')
					. '<a class="btn" href="/presets/' . Html::e($pid) . '/log">Log</a>';
			} else {
				$acts = Html::postButton("/presets/{$pid}/stop", 'Stop')
					. Html::postButton("/presets/{$pid}/restart", 'Restart')
					. '<a class="btn" href="/presets/' . Html::e($pid) . '/log">Log</a> '
					. Html::postButton("/presets/{$pid}/test", 'Test', '', '', 'test-form')
					. '<span class="test-result"></span>';
			}
			$rows .= '<tr><td><a href="/presets/' . Html::e($pid) . '/edit">' . Html::e($pid) . '</a></td>'
				. '<td>' . Html::e($model) . '</td>'
				. '<td>' . $this->badge($i->status) . '</td>'
				. '<td>' . Html::e((string)($i->port ?: '-')) . '</td>'
				. '<td>' . $this->ctxCell($p) . '</td>'
				. '<td>' . ($alloc !== null ? Html::e(Format::humanBytes($alloc->totalBytes())) : '-') . '</td>'
				. '<td>' . ($i->started_at > 0 ? Html::e(Format::duration(time() - $i->started_at)) : '-') . '</td>'
				. '<td>' . ($i->last_used > 0 ? Html::e(Format::duration(time() - $i->last_used)) . ' ago' : '-') . '</td>'
				. '<td>' . $acts . '</td></tr>';
			if ($i->message !== '') {
				$rows .= '<tr class="messagerow"><td colspan="9">' . Html::e($i->message) . '</td></tr>';
			}
		}
		return $rows;
	}

	/**
	 * Available panel rows: enabled presets that are not running
	 * (GUI-DESIGN.md §3 — disabled presets never appear here). "Fits?"
	 * reuses the Phase 3 admission check against current free memory.
	 *
	 * @param iterable<string,Instance> $instances
	 * @param iterable<string,Preset> $presets
	 */
	private function availableRows(iterable $instances, iterable $presets): string
	{
		$running = [];
		foreach ($instances as $i) {
			if (in_array($i->status, [Instance::LOADING, Instance::READY], true) && $i->allocation !== []) {
				$running[] = Allocation::fromArray($i->allocation);
			}
		}

		$rows = '';
		foreach ($presets as $pid => $p) {
			if (!$p->enabled || isset($instances[$pid])) {
				continue;
			}
			[$estCell, $fitsCell] = $this->fitCells($p, $running);
			$acts = Html::postButton("/presets/{$pid}/start", 'Start')
				. '<a class="btn" href="/presets/' . Html::e($pid) . '/edit">Edit</a> '
				. '<a class="btn" href="/presets/new?copy=' . Html::e(rawurlencode((string)$pid)) . '">Duplicate</a>';
			$rows .= '<tr><td><a href="/presets/' . Html::e($pid) . '/edit">' . Html::e($pid) . '</a></td>'
				. '<td>' . Html::e($p->model_path !== '' ? basename($p->model_path) : '-') . '</td>'
				. '<td>' . $this->ctxCell($p) . '</td>'
				. '<td>' . $estCell . '</td>'
				. '<td>' . $fitsCell . '</td>'
				. '<td>' . $acts . '</td></tr>';
		}
		return $rows;
	}

	/**
	 * Estimate + "Fits?" cells for one not-running preset. "?" whenever
	 * memory cannot be read — macOS has no probe, and a preset with no
	 * model file cannot be estimated (GUI-DESIGN.md §3).
	 *
	 * @param list<Allocation> $running
	 * @return array{0:string,1:string}
	 */
	private function fitCells(Preset $p, array $running): array
	{
		if ($p->model_path === '') {
			return ['-', '?'];
		}
		try {
			$result = $this->app->memory->admit($p, $running);
		} catch (Throwable) {
			return ['?', '?'];
		}
		$est = Format::humanBytes($this->app->memory->estimate($p)->totalBytes());
		if ($result->fits) {
			return [$est, '✓ fits'];
		}
		// Biggest per-device gap is what actually blocks the start.
		$worst = 0;
		foreach ($result->perBudget as $r) {
			$worst = max($worst, $r['need'] - $r['available']);
		}
		return [$est, '✗ needs ' . Format::humanBytes($worst) . ' more'];
	}

	/** Text badge per GUI-DESIGN.md §3: wording differs per status, CSS carries fill/outline. */
	private function badge(string $status): string
	{
		$label = match ($status) {
			Instance::READY   => 'ready',
			Instance::LOADING => 'loading…',
			Instance::ERROR   => 'error',
			default           => $status,
		};
		return '<span class="badge badge-' . Html::e($status) . '">' . Html::e($label) . '</span>';
	}

	/** Ctx column: the configured ctx_size, with the model's trained max when 0 means "model default". */
	private function ctxCell(?Preset $p): string
	{
		if ($p === null) {
			return '-';
		}
		$ctx = $p->args->ctx_size;
		return (string)($ctx === 0 ? 'model max' : $ctx);
	}

	/**
	 * Memory section: totals per budget device plus a stacked bar whose
	 * segments are the running instances (GUI-DESIGN.md §3 — "why won't
	 * X start" at a glance). macOS must still render the rest of the
	 * dashboard, hence the try/catch.
	 *
	 * @param iterable<string,Instance> $instances
	 */
	private function memorySection(iterable $instances): string
	{
		try {
			$topo  = $this->app->memory->topology();
			// Unified boxes: system RAM held by the GPU driver (amdgpu GTT)
			// is a large slice of "used" that no process RSS accounts for —
			// show it so the numbers explain themselves.
			$heldTotal = 0;
			foreach ($topo->devices as $d) {
				$heldTotal += $d->driverHeldBytes;
			}
			$mem = '<table><tr><th>Device</th><th>Total</th><th>Available</th>'
				. ($heldTotal > 0 ? '<th>GPU-held (GTT)</th>' : '')
				. '<th>Est. in use by LlmChooser</th><th></th></tr>';
			foreach ($topo->devices as $d) {
				if ($topo->budgetId($d->id) !== $d->id) {
					continue; // unified GPUs fold into their budget device
				}
				$segs  = '';
				$inUse = 0;
				foreach ($instances as $i) {
					if ($i->allocation === [] || $i->status === Instance::STOPPED) {
						continue;
					}
					$bytes = 0;
					foreach (Allocation::fromArray($i->allocation)->perDevice as $devId => $b) {
						if ($topo->budgetId((string)$devId) === $d->id) {
							$bytes += (int)$b;
						}
					}
					if ($bytes <= 0) {
						continue;
					}
					$inUse += $bytes;
					$pct   = min(100, $d->totalBytes > 0 ? round($bytes / $d->totalBytes * 100, 2) : 0);
					$segs .= '<div class="segment' . ($i->status === Instance::LOADING ? ' loading' : '')
						. '" style="width:' . $pct . '%" title="'
						. Html::e($i->preset_id . ': ' . Format::humanBytes($bytes)) . '">'
						. Html::e($i->preset_id) . '</div>';
				}
				// Free space: unlabelled remainder segment.
				$freePct = $d->totalBytes > 0 ? max(0, round(($d->totalBytes - $inUse) / $d->totalBytes * 100, 2)) : 0;
				// GPU-held (GTT) segment, drawn before the instance segments
				// (it is pre-existing occupancy on unified boxes).
				$heldSeg = '';
				if ($d->driverHeldBytes > 0 && $d->totalBytes > 0) {
					$heldSeg = '<div class="segment gtheld" style="width:'
						. min(100, round($d->driverHeldBytes / $d->totalBytes * 100, 2))
						. '%" title="held by GPU driver (amdgpu GTT)"></div>';
				}
				$mem .= '<tr><td>' . Html::e($d->id) . '</td>'
					. '<td>' . Html::e(Format::humanBytes($d->totalBytes)) . '</td>'
					. '<td>' . Html::e(Format::humanBytes($d->availableBytes)) . '</td>'
					. ($heldTotal > 0 ? '<td>' . Html::e($d->driverHeldBytes > 0 ? Format::humanBytes($d->driverHeldBytes) : '-') . '</td>' : '')
					. '<td>' . Html::e(Format::humanBytes($inUse)) . '</td>'
					. '<td><div class="membar">' . $heldSeg . $segs
					. '<div class="segment free" style="width:' . $freePct . '%"></div></div></td></tr>';
			}
			$mem .= '</table>';
			if ($topo->isUnified()) {
				$mem .= '<p>unified memory</p>';
			}
			return '<section><h2>Memory</h2>' . $mem . '</section>';
		} catch (Throwable $e) {
			return '<section><h2>Memory</h2><p class="flash">Memory info unavailable: ' . Html::e($e->getMessage()) . '</p></section>';
		}
	}

	/**
	 * Header status line (GUI-DESIGN.md §2): "host · N running · RAM
	 * used/total GB". Empty string when memory cannot be read at all —
	 * a header that lies is worse than no header.
	 *
	 * @param iterable<string,Instance> $instances
	 */
	private function headerSummary(iterable $instances): string
	{
		$running = 0;
		foreach ($instances as $i) {
			if (in_array($i->status, [Instance::LOADING, Instance::READY], true)) {
				$running++;
			}
		}
		$parts = [gethostname() ?: 'host', $running . ' running'];
		try {
			$topo = $this->app->memory->topology();
			foreach ($topo->devices as $d) {
				if ($d->id !== 'cpu') {
					continue;
				}
				$used = max(0, $d->totalBytes - $d->availableBytes);
				$parts[] = 'RAM ' . Format::humanBytes($used) . '/' . Format::humanBytes($d->totalBytes);
				if ($d->driverHeldBytes > 0) {
					$parts[] = 'GPU-held ' . Format::humanBytes($d->driverHeldBytes);
				}
			}
		} catch (Throwable) {
			// probe failure: summary keeps host + count only
		}
		return implode(' · ', $parts);
	}

	private function brokenBanner(): string
	{
		$lines = '';
		foreach ([...$this->app->presets->broken(), ...$this->app->engines->broken()] as $f) {
			$lines .= '<div>⚠ ' . Html::e($f->message()) . '</div>';
		}
		// Deliberately no fix/delete button: the policy is never to touch a
		// malformed file (Phase 2A). Fixing it is a text-editor job.
		return $lines === '' ? '' : '<div class="broken">' . $lines . '</div>';
	}
}
