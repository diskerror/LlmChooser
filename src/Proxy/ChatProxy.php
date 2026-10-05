<?php

declare(strict_types=1);

namespace LLMchooser\Proxy;

use LLMchooser\App;
use LLMchooser\Config\BrokenFile;
use LLMchooser\Http\Request;
use LLMchooser\Process\Instance;
use LLMchooser\Process\InstanceCollection;

/**
 * OpenAI-compatible passthrough: /v1/* is forwarded to one preset's running instance.
 *
 * Preset selection, first match wins:
 *   1. ?preset=ID query parameter (removed before forwarding)
 *   2. X-LLMChooser-Preset header
 *   3. JSON body "model" equal to a preset id, or to a preset's args.alias
 *   4. exactly one instance is running -> that one
 * Otherwise 400.
 */
final class ChatProxy
{
	public function __construct(
		private readonly App $app,
		private readonly Upstream $upstream = new Upstream(),
	) {}

	public function handle(Request $r, Emitter $out): void
	{
		$running = $this->app->processes->refresh();

		if ($r->method === 'GET' && $r->path === '/v1/models') {
			$this->models($running, $out);
			return;
		}

		// A preset whose file is malformed is NOT in loadAll(), so asking for
		// it by name would otherwise fall through to "no preset is running",
		// which sends you hunting in the wrong direction. Answer precisely.
		$bad = $this->brokenNamed($this->requestedName($r));
		if ($bad !== null) {
			$this->error($out, 422, $bad->message());
			return;
		}

		$id = $this->selectPreset($r, $running);
		if ($id === null) {
			$this->error($out, 400, count($this->active($running)) === 0
				? 'No preset is running. Start one first.'
				: 'Several presets are running. Choose one with ?preset=ID, the X-LLMChooser-Preset header, or "model" in the body.');
			return;
		}

		$inst = $running[$id] ?? null;
		if ($inst === null || $inst->status === Instance::ERROR) {
			$this->error($out, 503, "Preset '{$id}' is not running.");
			return;
		}
		if ($inst->status === Instance::LOADING) {
			$this->error($out, 503, "Preset '{$id}' is still loading. Retry shortly.");
			return;
		}

		$this->app->state->update(function (InstanceCollection $c) use ($id): void {
			if (isset($c[$id])) {
				$c[$id]->last_used = time();
			}
		});

		[$ok, $err] = $this->upstream->forward($r, $inst->host, $inst->port, $this->forwardPath($r), $r->body, $out);
		if (!$ok) {
			$this->error($out, 502, "Upstream error for '{$id}': {$err}");
		}
	}

	private function selectPreset(Request $r, InstanceCollection $running): ?string
	{
		$q = $r->query('preset');
		if ($q !== '') {
			return $q;
		}
		$h = $r->header('X-LLMChooser-Preset');
		if ($h !== null && $h !== '') {
			return $h;
		}
		$model = $this->bodyModel($r);
		if ($model !== null) {
			foreach ($this->app->presets->loadAll() as $p) {
				if ($p->id === $model || ($p->args->alias !== '' && $p->args->alias === $model)) {
					return $p->id;
				}
			}
		}
		$active = $this->active($running);
		return count($active) === 1 ? array_key_first($active) : null;
	}

	private function bodyModel(Request $r): ?string
	{
		if ($r->body === '' || !str_contains(strtolower($r->header('Content-Type') ?? ''), 'json')) {
			return null;
		}
		$data = json_decode($r->body, true);
		return (is_array($data) && isset($data['model']) && is_string($data['model'])) ? $data['model'] : null;
	}

	/** @return array<string, Instance> loading or ready instances */
	private function active(InstanceCollection $running): array
	{
		$out = [];
		foreach ($running as $id => $i) {
			if ($i->status === Instance::READY || $i->status === Instance::LOADING) {
				$out[$id] = $i;
			}
		}
		return $out;
	}

	/** Path + query string, without our own "preset" parameter. */
	private function forwardPath(Request $r): string
	{
		$q = $r->query;
		unset($q['preset']);
		return $r->path . ($q === [] ? '' : '?' . http_build_query($q));
	}

	private function models(InstanceCollection $running, Emitter $out): void
	{
		$data = [];
		foreach ($this->app->presets->loadAll() as $p) {
			$data[] = [
				'id'       => $p->args->alias !== '' ? $p->args->alias : $p->id,
				'object'   => 'model',
				'owned_by' => 'llmchooser',
				'preset'   => $p->id,
				'status'   => isset($running[$p->id]) ? $running[$p->id]->status : Instance::STOPPED,
			];
		}

		// Unreadable presets are listed too, with the parse error. Omitting
		// them is what makes a broken file look like a vanished model.
		// NOTE: loadAll() above populates broken(); do not reorder.
		foreach ($this->app->presets->broken() as $f) {
			$data[] = [
				'id'       => $f->id,
				'object'   => 'model',
				'owned_by' => 'llmchooser',
				'preset'   => $f->id,
				'status'   => 'error',
				'error'    => $f->message(),
			];
		}

		$this->json($out, 200, ['object' => 'list', 'data' => $data]);
	}

	/** The preset/model the client explicitly asked for, if any. */
	private function requestedName(Request $r): ?string
	{
		$q = $r->query('preset');
		if ($q !== '') {
			return $q;
		}
		$h = $r->header('X-LLMChooser-Preset');
		if ($h !== null && $h !== '') {
			return $h;
		}
		return $this->bodyModel($r);
	}

	/** The quarantined file matching $name, if the client named a broken preset. */
	private function brokenNamed(?string $name): ?BrokenFile
	{
		if ($name === null || $name === '') {
			return null;
		}
		foreach ($this->brokenConfig() as $f) {
			if ($f->id === $name) {
				return $f;
			}
		}
		return null;
	}

	/**
	 * Config files that could not be parsed. loadAll() populates broken();
	 * call it first so the list is current.
	 *
	 * @return list<BrokenFile>
	 */
	private function brokenConfig(): array
	{
		$this->app->presets->loadAll();
		return $this->app->presets->broken();
	}

	/**
	 * Appended to every error message. A plain chat client shows only
	 * error.message, so the unreadable file has to be stated there or the
	 * user sees a misleading "no preset is running" and hunts in the wrong
	 * place. BrokenFile::message() already names the path.
	 *
	 * Files already named in $message are skipped: the 422 branch passes
	 * BrokenFile::message() as the message itself, and appending the same
	 * string again prints it twice. Other broken files are still appended.
	 */
	private function brokenSuffix(string $message): string
	{
		$parts = [];
		foreach ($this->brokenConfig() as $f) {
			if (!str_contains($message, $f->message())) {
				$parts[] = $f->message();
			}
		}

		return $parts === [] ? '' : ' ' . implode(' ', $parts);
	}

	private function error(Emitter $out, int $code, string $message): void
	{
		$err = [
			'message' => $message . $this->brokenSuffix($message),
			'type'    => 'invalid_request_error',
			'code'    => $code,
		];

		// Machine-readable copy for the web UI and any client that looks.
		$broken = $this->brokenConfig();
		if ($broken !== []) {
			$err['broken_config'] = array_map(
				static fn(BrokenFile $f): array => ['id' => $f->id, 'path' => $f->path, 'message' => $f->message()],
				$broken
			);
		}

		$this->json($out, $code, ['error' => $err]);
	}

	private function json(Emitter $out, int $code, array $data): void
	{
		$out->status($code);
		$out->header('Content-Type: application/json');
		$out->begin();
		$out->write((string)json_encode($data, JSON_UNESCAPED_SLASHES));
	}
}
