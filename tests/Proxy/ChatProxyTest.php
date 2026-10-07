<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Proxy;

use LlmChooser\App;
use LlmChooser\Engine\Engine;
use LlmChooser\Engine\EngineRepository;
use LlmChooser\Http\Request;
use LlmChooser\Memory\Device;
use LlmChooser\Memory\FixedTopologyProbe;
use LlmChooser\Memory\MemoryTopology;
use LlmChooser\Preset\Preset;
use LlmChooser\Preset\PresetRepository;
use LlmChooser\Process\Instance;
use LlmChooser\Process\ProcessControl;
use LlmChooser\Proxy\ChatProxy;
use LlmChooser\Tests\Fixtures\FakeServer;
use LlmChooser\Tests\Model\GgufFixture;
use PHPUnit\Framework\TestCase;

/**
 * The /v1 proxy against the fake llama-server (ports 5890-5898).
 * Nothing external: no live LLM server, no network beyond loopback, no real models.
 */
class ChatProxyTest extends TestCase
{
	private string $dir;
	private App $app;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/llmchooser-proxy-' . uniqid('', true);
		mkdir($this->dir . '/storage/engines', 0775, true);
		mkdir($this->dir . '/storage/presets', 0775, true);

		$bin = FakeServer::makeBinary($this->dir . '/bin');
		GgufFixture::model($this->dir . '/models/m.gguf', 'llama', 3000);

		$engines = new EngineRepository($this->dir . '/storage/engines');
		$engines->save(new Engine([
			'id' => 'e',
			'name' => 'E',
			'host' => '127.0.0.1',
			'binary_path_default' => $bin,
			'model_dir' => $this->dir . '/models',
			'port_range' => ['min' => 5890, 'max' => 5898],
		]));

		$presets = new PresetRepository($this->dir . '/storage/presets');
		$presets->save(new Preset([
			'id' => 'a', 'name' => 'A', 'engine_id' => 'e',
			'model_path' => $this->dir . '/models/m.gguf',
			'args' => ['alias' => 'qwen-a'],
		]));
		$presets->save(new Preset([
			'id' => 'b', 'name' => 'B', 'engine_id' => 'e',
			'model_path' => $this->dir . '/models/m.gguf',
		]));

		$g    = 1024 ** 3;
		$topo = new MemoryTopology([
			new Device('cpu', Device::KIND_RAM, 100 * $g, 100 * $g),
		]);
		$this->app = new App($this->dir, new FixedTopologyProbe($topo));
	}

	protected function tearDown(): void
	{
		if (isset($this->app)) {
			foreach ($this->app->state->all() as $id => $instance) {
				$this->app->processes->stop((string)$id);
			}
		}
		putenv('FAKE_LOAD_MS');
		$this->rmrf($this->dir);
	}

	private function rmrf(string $path): void
	{
		if (!is_dir($path)) {
			if (file_exists($path)) {
				unlink($path);
			}
			return;
		}
		foreach (scandir($path) ?: [] as $e) {
			if ($e !== '.' && $e !== '..') {
				$this->rmrf($path . '/' . $e);
			}
		}
		rmdir($path);
	}

	private function req(string $method, string $path, array $query = [], array $headers = [], string $body = ''): Request
	{
		// Request splits nothing; tests pass query as the array directly.
		// headers here are name => value (lower-case keys preferred).
		return new Request($method, $path, $query, [], $headers, $body);
	}

	private function proxyRun(Request $r): CollectingEmitter
	{
		$em = new CollectingEmitter();
		(new ChatProxy($this->app))->handle($r, $em);
		return $em;
	}

	private function startReady(string $id): Instance
	{
		$this->app->processes->start($id);
		$i = $this->app->processes->waitUntilReady($id, 10);
		$this->assertSame(Instance::READY, $i->status);
		return $i;
	}

	private function jsonHeaders(): array
	{
		return ['content-type' => 'application/json'];
	}

	// ------------------------------------------------------------------ 1-13

	public function test_models_lists_presets(): void
	{
		$em = $this->proxyRun($this->req('GET', '/v1/models'));
		$this->assertSame(200, $em->code);
		$data = $em->json()['data'];
		$this->assertCount(2, $data);
		foreach ($data as $m) {
			if ($m['preset'] === 'a') {
				$this->assertSame('qwen-a', $m['id']);
				$this->assertSame('stopped', $m['status']);
			}
		}
	}

	public function test_nothing_running_is_400(): void
	{
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', [], $this->jsonHeaders(), '{"model":"x"}'));
		$this->assertSame(400, $em->code);
		$this->assertStringContainsString('No preset is running', $em->json()['error']['message']);
	}

	public function test_single_running_fallback(): void
	{
		$a = $this->startReady('a');
		$body = '{"model":"anything"}';
		$em   = $this->proxyRun($this->req('POST', '/v1/chat/completions', [], $this->jsonHeaders(), $body));
		$this->assertSame(200, $em->code);
		$json = $em->json();
		$this->assertSame($a->port, $json['port']);
		$this->assertSame($body, $json['body']);
	}

	public function test_query_param_selects_and_is_removed(): void
	{
		$this->startReady('a');
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'a', 'x' => '1'], $this->jsonHeaders(), '{}'));
		$this->assertSame(200, $em->code);
		$this->assertSame('POST /v1/chat/completions?x=1 HTTP/1.1', $em->json()['echo']);
	}

	public function test_model_alias_selects(): void
	{
		$a = $this->startReady('a');
		$this->startReady('b');
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', [], $this->jsonHeaders(), '{"model":"qwen-a"}'));
		$this->assertSame(200, $em->code);
		$this->assertSame($a->port, $em->json()['port']);
	}

	public function test_header_selects(): void
	{
		$this->startReady('a');
		$b = $this->startReady('b');
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', [], ['x-llmchooser-preset' => 'b'], '{"model":"zzz"}'));
		$this->assertSame(200, $em->code);
		$this->assertSame($b->port, $em->json()['port']);
		// the selection header must not be forwarded
		$this->assertStringNotContainsString('x-llmchooser-preset', strtolower($em->json()['body']));
	}

	public function test_two_running_unqualified_is_400(): void
	{
		$this->startReady('a');
		$this->startReady('b');
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', [], $this->jsonHeaders(), '{"model":"zzz"}'));
		$this->assertSame(400, $em->code);
		$this->assertStringContainsString('Several presets', $em->json()['error']['message']);
	}

	public function test_stopped_preset_503(): void
	{
		$this->startReady('a');
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'b'], $this->jsonHeaders(), '{}'));
		$this->assertSame(503, $em->code);
	}

	public function test_loading_is_503(): void
	{
		putenv('FAKE_LOAD_MS=5000');
		$this->app->processes->start('a');
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'a'], $this->jsonHeaders(), '{}'));
		$this->assertSame(503, $em->code);
		$this->assertStringContainsString('still loading', $em->json()['error']['message']);
	}

	public function test_streaming_passes_chunks_through(): void
	{
		$this->startReady('a');
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'a'], $this->jsonHeaders(), '{"stream":true}'));
		$this->assertSame(200, $em->code);
		$this->assertStringContainsString('data: [DONE]', $em->body());
		$this->assertGreaterThanOrEqual(3, count($em->chunks));
		// chunks arrived spread over time, not one buffered dump
		$this->assertGreaterThanOrEqual(0.3, end($em->times) - $em->times[0]);
	}

	public function test_last_used_updated(): void
	{
		$this->startReady('a');
		$before = $this->app->state->get('a')->last_used;
		sleep(1);
		$this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'a'], $this->jsonHeaders(), '{}'));
		$this->assertGreaterThan($before, $this->app->state->get('a')->last_used);
	}

	public function test_dead_upstream(): void
	{
		$a = $this->startReady('a');
		(new ProcessControl())->signal($a->pid, 'KILL');
		usleep(300000);
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'a'], $this->jsonHeaders(), '{}'));
		$this->assertContains($em->code, [502, 503]);
	}

	public function test_response_headers(): void
	{
		$this->startReady('a');
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'a'], $this->jsonHeaders(), '{}'));
		$this->assertContains('X-Fake: 1', $em->headers);
		foreach ($em->headers as $h) {
			$this->assertFalse(stripos($h, 'Content-Length:') === 0, "Content-Length leaked: {$h}");
		}
	}

	// ------------------------------------------------------- 14-20 broken cfg

	private function breakPreset(string $id = 'c'): void
	{
		file_put_contents($this->dir . "/storage/presets/{$id}.json", '{"id":"' . $id . '",,}');
	}

	public function test_named_broken_preset_is_422(): void
	{
		$this->breakPreset();
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'c'], $this->jsonHeaders(), '{}'));
		$this->assertSame(422, $em->code);
		$msg = $em->json()['error']['message'];
		$this->assertStringContainsString('Config file has malformed JSON', $msg);
		$this->assertStringContainsString('c.json', $msg);
	}

	public function test_broken_preset_named_once(): void
	{
		$this->breakPreset();
		$em  = $this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'c'], $this->jsonHeaders(), '{}'));
		$msg = $em->json()['error']['message'];
		$this->assertSame(1, substr_count($msg, 'c.json'));
	}

	public function test_other_broken_files_still_appended(): void
	{
		$this->breakPreset('c');
		$this->breakPreset('d');
		$em  = $this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'c'], $this->jsonHeaders(), '{}'));
		$msg = $em->json()['error']['message'];
		$this->assertSame(1, substr_count($msg, 'c.json'));
		$this->assertSame(1, substr_count($msg, 'd.json'));
	}

	public function test_generic_error_mentions_broken_file(): void
	{
		$this->breakPreset();
		$em  = $this->proxyRun($this->req('POST', '/v1/chat/completions'));
		$this->assertSame(400, $em->code);
		$msg = $em->json()['error']['message'];
		$this->assertStringContainsString('No preset is running', $msg);
		$this->assertStringContainsString('c.json', $msg);
	}

	public function test_error_carries_broken_config(): void
	{
		$this->breakPreset();
		$em   = $this->proxyRun($this->req('POST', '/v1/chat/completions'));
		$list = $em->json()['error']['broken_config'];
		$this->assertCount(1, $list);
		$this->assertSame('c', $list[0]['id']);
		$this->assertStringEndsWith('c.json', $list[0]['path']);
	}

	public function test_no_broken_files_no_noise(): void
	{
		$this->breakPreset();
		unlink($this->dir . '/storage/presets/c.json');
		$em  = $this->proxyRun($this->req('POST', '/v1/chat/completions'));
		$this->assertSame(400, $em->code);
		$err = $em->json()['error'];
		$this->assertSame('No preset is running. Start one first.', $err['message']);
		$this->assertArrayNotHasKey('broken_config', $err);
	}

	public function test_models_excludes_broken_files(): void
	{
		// Phase 8: /v1/models is a client-facing offer; an unreadable file
		// is no longer listed. It still surfaces via the 422/400 paths above.
		$this->breakPreset();
		$em = $this->proxyRun($this->req('GET', '/v1/models'));
		$this->assertSame(200, $em->code);
		foreach ($em->json()['data'] as $m) {
			$this->assertNotSame('c', $m['id']);
		}
	}

	// ------------------------------------------------------- Phase 8: enabled

	public function test_models_excludes_disabled_preset(): void
	{
		$this->app->presets->setEnabled('b', false);
		$em = $this->proxyRun($this->req('GET', '/v1/models'));
		$this->assertSame(200, $em->code);
		$data = $em->json()['data'];
		$this->assertCount(1, $data);
		$this->assertSame('a', $data[0]['preset']);
	}

	public function test_disabled_preset_named_explicitly_is_404(): void
	{
		$this->startReady('a');
		$this->app->presets->setEnabled('b', false);
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'b'], $this->jsonHeaders(), '{}'));
		$this->assertSame(404, $em->code);
		$this->assertStringContainsString('disabled', $em->json()['error']['message']);
	}

	public function test_disabled_preset_no_fallback(): void
	{
		// Nothing running at all: naming the disabled preset must still be
		// the precise 404, not the generic "No preset is running" 400.
		$this->app->presets->setEnabled('b', false);
		$em = $this->proxyRun($this->req('POST', '/v1/chat/completions', ['preset' => 'b'], $this->jsonHeaders(), '{}'));
		$this->assertSame(404, $em->code);
		$this->assertStringContainsString('disabled', $em->json()['error']['message']);
	}
}
