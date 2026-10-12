<?php

declare(strict_types=1);

namespace LlmChooser\Proxy;

use LlmChooser\App;
use LlmChooser\Config\BrokenFile;
use LlmChooser\Http\Request;
use LlmChooser\Model\GgufException;
use LlmChooser\Model\GgufReader;
use LlmChooser\Preset\Preset;
use LlmChooser\Process\Instance;
use LlmChooser\Process\InstanceCollection;

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

		// A named preset that exists but is parked gets its own precise 404,
		// before the generic "not running" checks — "not running" would send
		// you hunting in the wrong direction. No fallback either: naming a
		// disabled preset explicitly is an error even if another preset runs.
		$named = $this->app->presets->loadAll()[$id] ?? null;
		if ($named !== null && !$named->enabled) {
			$this->error($out, 404, "Preset '{$id}' is disabled.");
			return;
		}

		// A request naming a preset that is not up starts it, unloading the
		// least-recently-used others if memory is short, then waits for it.
		$inst = $running[$id] ?? null;
		if ($named !== null && ($inst === null || !in_array($inst->status, [Instance::LOADING, Instance::READY], true))) {
			try {
				$this->app->processes->startEvicting($id);
			} catch (\Throwable $e) {
				$this->error($out, 503, "Preset '{$id}' could not be started: " . $e->getMessage());
				return;
			}
		}
		if ($named !== null) {
			try {
				$inst = $this->app->processes->waitUntilReady($id, 600);
			} catch (\Throwable $e) {
				$this->error($out, 503, "Preset '{$id}' is not running: " . $e->getMessage());
				return;
			}
		}
		if ($inst === null || $inst->status === Instance::ERROR) {
			$this->error($out, 503, "Preset '{$id}' is not running." . ($inst !== null && $inst->message !== '' ? ' ' . $inst->message : ''));
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

	/** @return array<string, Instance> loading or ready instances of enabled presets */
	private function active(InstanceCollection $running): array
	{
		$presets = $this->app->presets->loadAll();
		$out = [];
		foreach ($running as $id => $i) {
			if ($i->status !== Instance::READY && $i->status !== Instance::LOADING) {
				continue;
			}
			// Defensive: disable stops first, so finding one running is rare.
			$p = $presets[$id] ?? null;
			if ($p !== null && !$p->enabled) {
				continue;
			}
			$out[$id] = $i;
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
			// A parked preset is not offered to API clients (GUI-DESIGN §4).
			if (!$p->enabled) {
				continue;
			}
			$entry = [
				'id'       => $p->args->alias !== '' ? $p->args->alias : $p->id,
				'object'   => 'model',
				'owned_by' => 'llmchooser',
				'preset'   => $p->id,
				'status'   => isset($running[$p->id]) ? $running[$p->id]->status : Instance::STOPPED,
			];
			// Omitted rather than published as null: an unreadable GGUF
			// header is no reason to publish a context_length that might
			// mislead a client into caching a wrong number.
			$ctx = $this->contextLength($p);
			if ($ctx !== null) {
				$entry['context_length'] = $ctx;
			}
			$data[] = $entry;
		}

		// Broken files are NOT listed here (GUI-DESIGN §4): an entry whose
		// enabled state cannot even be read has no business being offered.
		// They still surface on every human-facing surface — CLI stderr,
		// broken_config on error responses, and the explicit-name 422 below.

		$this->json($out, 200, ['object' => 'list', 'data' => $data]);
	}

	/**
	 * The effective prompt context in tokens PER CONVERSATION: the preset's
	 * configured --ctx-size (divided among slots when --parallel N > 1), or (when that is 0, meaning "model default") the GGUF
	 * header's trained context length. Clients like Hermes otherwise have
	 * no way to know this and silently fall back to guessed family
	 * defaults (GUI-DESIGN §4 follow-up, reported 2026-10-07: a client's
	 * name-pattern guesser landed on 131072 for a 262144-context preset
	 * because our preset ids can't contain a "." and so never match
	 * dotted version patterns like "qwen3.8-flash").
	 *
	 * Null when the GGUF header can't be read (missing/corrupt file) —
	 * callers should omit the field rather than publish a lie.
	 */
	private function contextLength(Preset $p): ?int
	{
		if ($p->args->ctx_size > 0) {
			// With an explicit --parallel N > 1, llama-server gives each
			// slot ctx_size / N (rounded down to a multiple of 256), and a
			// conversation that outgrows ITS slot stops with "context
			// full" even though the total is larger. A client needs the
			// per-slot figure to know when to compress; reporting the
			// total made Hermes never react (2026-10-11). parallel -1
			// (auto) uses a unified pool, so the total stands.
			$n = $p->args->parallel;
			if ($n > 1) {
				return max(256, intdiv(intdiv($p->args->ctx_size, $n), 256) * 256);
			}
			return $p->args->ctx_size;
		}
		try {
			$trained = GgufReader::read($p->model_path)->contextLength();
		}
		catch (GgufException) {
			return null;
		}
		return $trained > 0 ? $trained : null;
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
