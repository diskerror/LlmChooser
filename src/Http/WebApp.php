<?php

declare(strict_types=1);

namespace LlmChooser\Http;

use LlmChooser\App;
use LlmChooser\Cli\Format;
use LlmChooser\Memory\Allocation;
use LlmChooser\Memory\Device;
use LlmChooser\Process\AdmissionException;
use LlmChooser\Process\Instance;
use LlmChooser\Process\ProcessControl;
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
				Html::page('Error', '<pre>' . Html::e($e->getMessage()) . '</pre>', '', '', $r->path),
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
		$rt->add('GET', '/presets/{id}/log', fn(Request $r, array $p): Response => $this->log($r, $p['id']));
		$rt->add('GET', '/models', fn(Request $r): Response => $this->models($r));
		$rt->add('GET', '/health', fn(Request $r): Response => Response::json(['status' => 'ok']));
		$rt->add('GET', '/api/status', fn(Request $r): Response => $this->apiStatus());
		$rt->add('GET', '/assets/style.css', fn(Request $r): Response => $this->css());
		$rt->add('GET', '/assets/app.js', fn(Request $r): Response => $this->js());
	}

	// ---------------------------------------------------------------- pages

	private function dashboard(Request $r): Response
	{
		$instances = $this->app->processes->refresh();
		$presets   = $this->app->presets->loadAll();

		// Memory section. macOS (and any box without /proc/meminfo) must still
		// render the rest of the dashboard — rule from Step 8.
		$mem = '';
		try {
			$topo  = $this->app->memory->topology();
			$mem  .= '<table><tr><th>Device</th><th>Total</th><th>Available</th><th>Est. in use by LlmChooser</th><th></th></tr>';
			foreach ($topo->devices as $d) {
				if ($topo->budgetId($d->id) !== $d->id) {
					continue; // unified GPUs fold into their budget device
				}
				$inUse = 0;
				foreach ($instances as $i) {
					if ($i->allocation === [] || $i->status === Instance::STOPPED) {
						continue;
					}
					foreach (Allocation::fromArray($i->allocation)->perDevice as $devId => $bytes) {
						if ($topo->budgetId((string)$devId) === $d->id) {
							$inUse += (int)$bytes;
						}
					}
				}
				$mem .= '<tr><td>' . Html::e($d->id) . '</td>'
					. '<td>' . Html::e(Format::gb($d->totalBytes)) . '</td>'
					. '<td>' . Html::e(Format::gb($d->availableBytes)) . '</td>'
					. '<td>' . Html::e(Format::gb($inUse)) . '</td>'
					. '<td><meter min="0" max="' . $d->totalBytes . '" value="' . max(0, $d->totalBytes - $d->availableBytes) . '"></meter></td></tr>';
			}
			$mem .= '</table>';
			if ($topo->isUnified()) {
				$mem .= '<p>unified memory</p>';
			}
		} catch (Throwable $e) {
			$mem = '<p class="flash">Memory info unavailable: ' . Html::e($e->getMessage()) . '</p>';
		}

		$head = '';
		$rows = '';
		foreach ($this->instanceRows($instances, $presets) as $row) {
			$rows .= $row['html'];
			if ($row['status'] === Instance::LOADING) {
				$head = '<meta http-equiv="refresh" content="5">';
			}
		}

		$body = '<section><h2>Memory</h2>' . $mem . '</section>'
			. '<section><h2>Instances</h2><table><tr><th>Preset</th><th>Status</th><th>PID</th><th>Port</th><th>Uptime</th><th>Estimate</th><th>RSS</th><th>Actions</th></tr>'
			. ($rows !== '' ? $rows : '<tr><td colspan="8">No presets.</td></tr>')
			. '</table></section>'
			. $this->brokenBanner();

		return Response::html(Html::page('Dashboard', $body, $r->query('msg'), $head, $r->path));
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
		return Response::html(Html::page('Presets', $body, $r->query('msg'), '', $r->path));
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
		return Response::html(Html::page('New preset', PresetForm::render($preset, $models, true), $r->query('msg'), '', $r->path));
	}

	private function presetCreate(Request $r): Response
	{
		$preset = new Preset(PresetForm::fromPost($r->post));
		$errors = PresetValidator::validate($preset, $this->app->engines, true, $this->app->presets);
		if ($errors !== []) {
			return Response::html(
				Html::page('New preset', PresetForm::render($preset, $this->app->modelScanner()->scan(), true, $errors), '', '', $r->path),
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
			return Response::html(Html::page('Not found', '<p>No preset ' . Html::e($id) . '.</p>', '', '', $r->path), 404);
		}
		$running = $this->instanceIsLive($id);
		return Response::html(
			Html::page('Edit ' . $id, PresetForm::render($preset, $this->app->modelScanner()->scan(), false, [], $running), $r->query('msg'), '', $r->path)
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
			return Response::html(Html::page('Not found', '<p>No preset ' . Html::e($id) . '.</p>', '', '', $r->path), 404);
		}
		$preset->assign(PresetForm::fromPost($r->post));
		$preset->id = $id; // editing never renames; the id input is readonly
		// assigned_port is not in fromPost(), so assign() leaves it untouched.

		$errors = PresetValidator::validate($preset, $this->app->engines, false, $this->app->presets);
		if ($errors !== []) {
			return Response::html(
				Html::page('Edit ' . $id, PresetForm::render($preset, $this->app->modelScanner()->scan(), false, $errors, $this->instanceIsLive($id))),
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
			return Response::html(Html::page('Not enough memory', $body, '', '', $r->path), 200);
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
			return Response::html(Html::page('Not found', '<p>No preset ' . Html::e($id) . '.</p>', '', '', $r->path), 404);
		}
		try {
			$alloc = $this->app->memory->estimate($preset);
		} catch (Throwable $e) {
			return Response::html(Html::page('Estimate', '<p class="flash">Estimate failed: ' . Html::e($e->getMessage()) . '</p>', '', '', $r->path), 200);
		}
		$rows = '';
		foreach ($alloc->breakdown as $k => $v) {
			$rows .= '<tr><td>' . Html::e($k) . '</td><td>' . Html::e(Format::gb((int)$v)) . '</td></tr>';
		}
		$devs = '';
		foreach ($alloc->perDevice as $d => $b) {
			$devs .= '<tr><td>' . Html::e($d) . '</td><td>' . Html::e(Format::gb($b)) . '</td></tr>';
		}
		$body = '<table><tr><th>Part</th><th>Bytes</th></tr>' . $rows
			. '<tr><th>Total</th><th>' . Html::e(Format::gb($alloc->totalBytes())) . '</th></tr></table>'
			. '<h2>Per device</h2><table><tr><th>Device</th><th>Bytes</th></tr>' . $devs . '</table>';
		return Response::html(Html::page('Estimate ' . $id, $body, '', '', $r->path));
	}

	private function log(Request $r, string $id): Response
	{
		$i = $this->app->state->get($id);
		if ($i === null || $i->log_file === '' || !is_file($i->log_file)) {
			return Response::html(Html::page('Not found', '<p>No log for ' . Html::e($id) . '.</p>', '', '', $r->path), 404);
		}
		$text = Format::tail($i->log_file, 200);
		if ($r->query('raw') === '1') {
			return Response::text($text);
		}
		return Response::html(Html::page('Log ' . $id, '<pre class="log">' . Html::e($text) . '</pre>', '', '', $r->path));
	}

	private function models(Request $r): Response
	{
		$rows = '';
		foreach ($this->app->modelScanner()->scan() as $e) {
			$sidecars = 0;
			foreach ($e->sidecars as $list) {
				$sidecars += count($list);
			}
			$rows .= '<tr><td>' . Html::e($e->displayName) . '</td>'
				. '<td>' . Html::e(Format::gb($e->sizeBytes)) . '</td>'
				. '<td>' . Html::e($e->info?->architecture() ?? '-') . '</td>'
				. '<td>' . Html::e((string)($e->info?->contextLength() ?? '-')) . '</td>'
				. '<td>' . ($e->isSharded() ? count($e->shardPaths) : '-') . '</td>'
				. '<td>' . $sidecars . '</td>'
				. '<td>' . Html::e($e->error ?? '') . '</td></tr>';
		}
		$body = '<p>The cache refreshes automatically when a file\'s size or mtime changes.</p>'
			. '<table><tr><th>Model</th><th>Size</th><th>Arch</th><th>Ctx</th><th>Shards</th><th>Sidecars</th><th>Error</th></tr>'
			. ($rows !== '' ? $rows : '<tr><td colspan="7">No models found.</td></tr>')
			. '</table>';
		return Response::html(Html::page('Models', $body, $r->query('msg'), '', $r->path));
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
	 * Dashboard instance rows: one per preset, plus instances whose preset
	 * file is gone — a running server must never look like it does not exist.
	 *
	 * @param iterable<string,Instance> $instances
	 * @return list<array{html:string,status:string}>
	 */
	private function instanceRows(iterable $instances, iterable $presets): array
	{
		$rows = [];
		$seen = [];
		$pc   = new ProcessControl();
		foreach ($presets as $pid => $p) {
			$i          = $instances[$pid] ?? null;
			$seen[$pid] = true;
			$rows[]     = $this->instanceRow((string)$pid, $i, $pc, $p->assigned_port, $p->enabled);
		}
		foreach ($instances as $pid => $i) {
			if (!isset($seen[$pid])) {
				$rows[] = $this->instanceRow((string)$pid, $i, $pc, null, true);
			}
		}
		return $rows;
	}

	private function instanceRow(string $pid, ?Instance $i, ProcessControl $pc, ?int $assignedPort, bool $enabled = true): array
	{
		$status = $i?->status ?? Instance::STOPPED;
		$acts   = '';
		if ($status === Instance::STOPPED || $status === Instance::ERROR) {
			// A parked preset offers no Start (ProcessManager would refuse it).
			if ($enabled) {
				$acts .= Html::postButton("/presets/{$pid}/start", 'Start');
			}
		} else {
			$acts .= Html::postButton("/presets/{$pid}/stop", 'Stop')
				. Html::postButton("/presets/{$pid}/restart", 'Restart');
		}
		$acts .= '<a class="btn" href="/presets/' . Html::e($pid) . '/log">Log</a> ';
		$acts .= Html::postButton("/presets/{$pid}/estimate", 'Estimate');

		$html = '<tr><td><a href="/presets/' . Html::e($pid) . '/edit">' . Html::e($pid) . '</a></td>'
			. '<td class="status-' . Html::e($status) . '">' . Html::e($status) . '</td>'
			. '<td>' . ($i !== null && $i->pid > 0 ? Html::e((string)$i->pid) : '-') . '</td>'
			. '<td>' . Html::e((string)($i?->port ?: ($assignedPort ?? '-'))) . '</td>'
			. '<td>' . ($i !== null && $i->started_at > 0 ? Html::e(Format::duration(time() - $i->started_at)) : '-') . '</td>'
			. '<td>' . ($i !== null && $i->allocation !== [] ? Html::e(Format::gb(Allocation::fromArray($i->allocation)->totalBytes())) : '-') . '</td>'
			. '<td>' . ($i !== null && $i->pid > 0 ? Html::e(Format::gb($pc->rssBytes($i->pid))) : '-') . '</td>'
			. '<td>' . $acts . '</td></tr>';
		if ($i !== null && $i->message !== '' && $status === Instance::ERROR) {
			$html .= '<tr class="messagerow"><td colspan="8">' . Html::e($i->message) . '</td></tr>';
		}
		return ['html' => $html, 'status' => $status];
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
