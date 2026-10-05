<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Http;

use LLMchooser\App;
use LLMchooser\Engine\Engine;
use LLMchooser\Engine\EngineRepository;
use LLMchooser\Http\Request;
use LLMchooser\Http\Response;
use LLMchooser\Http\WebApp;
use LLMchooser\Memory\Device;
use LLMchooser\Memory\FixedTopologyProbe;
use LLMchooser\Memory\MemoryTopology;
use LLMchooser\Preset\Preset;
use LLMchooser\Preset\PresetRepository;
use LLMchooser\Process\Instance;
use LLMchooser\Tests\Fixtures\FakeServer;
use LLMchooser\Tests\Model\GgufFixture;
use PHPUnit\Framework\TestCase;

/**
 * Drives the real WebApp handler against a complete temp project root
 * (same shape as CliTest), ports 5880-5889. No HTTP server needed.
 */
class WebAppTest extends TestCase
{
	private string $dir;
	private App $app;
	private WebApp $web;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/llmchooser-web-' . uniqid('', true);
		mkdir($this->dir . '/config/engines', 0775, true);
		mkdir($this->dir . '/storage/presets', 0775, true);

		$bin = FakeServer::makeBinary($this->dir . '/bin');
		GgufFixture::model($this->dir . '/models/m.gguf', 'llama', 3000);

		$engines = new EngineRepository($this->dir . '/config/engines');
		$engines->save(new Engine([
			'id' => 'e',
			'name' => 'E',
			'host' => '127.0.0.1',
			'binary_path_default' => $bin,
			'port_range' => ['min' => 5880, 'max' => 5889],
		]));

		$presets = new PresetRepository($this->dir . '/storage/presets');
		foreach (['p1', 'p2'] as $id) {
			$presets->save(new Preset([
				'id' => $id,
				'name' => strtoupper($id),
				'engine_id' => 'e',
				'model_path' => $this->dir . '/models/m.gguf',
			]));
		}

		file_put_contents($this->dir . '/config/model_roots.json', json_encode([
			'roots' => [$this->dir . '/models'],
			'cache_file' => 'storage/model_cache.json',
		]));

		$this->app = $this->makeApp(100);
		$this->web = new WebApp($this->app);
	}

	private function makeApp(float $availableGb): App
	{
		$g    = 1024 ** 3;
		$topo = new MemoryTopology([
			new Device('cpu', Device::KIND_RAM, 100 * $g, (int)($availableGb * $g)),
		]);
		return new App($this->dir, new FixedTopologyProbe($topo));
	}

	protected function tearDown(): void
	{
		if (isset($this->app)) {
			foreach ($this->app->state->all() as $id => $instance) {
				$this->app->processes->stop((string)$id);
			}
		}
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

	private function get(string $path, array $query = []): Response
	{
		return $this->web->handle(new Request('GET', $path, $query));
	}

	private function post(string $path, array $post = []): Response
	{
		return $this->web->handle(new Request('POST', $path, [], $post));
	}

	public function testDashboard(): void
	{
		$res = $this->get('/');
		$this->assertSame(200, $res->status);
		$this->assertStringContainsString('p1', $res->body);
		$this->assertStringContainsString('p2', $res->body);
		$this->assertStringContainsString('stopped', $res->body);
	}

	public function testPresetListLinks(): void
	{
		$res = $this->get('/presets');
		$this->assertStringContainsString('/presets/p1/edit', $res->body);
	}

	public function testNewForm(): void
	{
		$res = $this->get('/presets/new');
		$this->assertSame(200, $res->status);
		$this->assertStringContainsString('name="args[temp]"', $res->body);
	}

	public function testCreatePreset(): void
	{
		$res = $this->post('/presets/new', [
			'id' => 'p3', 'name' => 'P3', 'engine_id' => 'e',
			'model_path' => $this->dir . '/models/m.gguf',
			'ttl_seconds' => '60',
			'args' => ['temp' => '0.4'],
		]);
		$this->assertSame(303, $res->status);
		$this->assertStringStartsWith('/presets?msg=', $res->headers['Location']);
		$loaded = $this->app->presets->load('p3');
		$this->assertSame(0.4, $loaded->args->temp);
	}

	public function testCreateDuplicateIs422(): void
	{
		$res = $this->post('/presets/new', [
			'id' => 'p1', 'name' => 'Dup', 'engine_id' => 'e',
			'model_path' => $this->dir . '/models/m.gguf',
		]);
		$this->assertSame(422, $res->status);
		$this->assertStringContainsString('Already exists', $res->body);
	}

	public function testEditKeepsPort(): void
	{
		$before = $this->app->presets->load('p1')->assigned_port;
		$res    = $this->post('/presets/p1/edit', [
			'id' => 'IGNORED', 'name' => 'ONE', 'engine_id' => 'e',
			'model_path' => $this->dir . '/models/m.gguf',
			'args' => ['ctx_size' => '4096'],
		]);
		$this->assertSame(303, $res->status);
		$after = $this->app->presets->load('p1');
		$this->assertSame(4096, $after->args->ctx_size);
		$this->assertSame('p1', $after->id);
		$this->assertSame($before, $after->assigned_port);
	}

	public function testStartRedirectAndRefreshMeta(): void
	{
		$res = $this->post('/presets/p1/start');
		$this->assertSame(303, $res->status);
		$this->assertStringStartsWith('/?msg=', $res->headers['Location']);

		$i = $this->app->state->get('p1');
		$this->assertNotNull($i);
		$this->assertContains($i->status, [Instance::LOADING, Instance::READY]);

		$page = $this->get('/');
		if ($this->app->state->get('p1')->status === Instance::LOADING) {
			$this->assertStringContainsString('http-equiv="refresh"', $page->body);
		}
		$this->app->processes->waitUntilReady('p1', 30);
	}

	public function testDeleteWhileRunningThenStopAndDelete(): void
	{
		$this->post('/presets/p1/start');
		$this->app->processes->waitUntilReady('p1', 30);

		$res = $this->post('/presets/p1/delete');
		$this->assertStringContainsString('Stop%20it%20first', $res->headers['Location']);
		$this->assertFileExists($this->dir . '/storage/presets/p1.json');

		$res = $this->post('/presets/p1/stop');
		$this->assertSame(303, $res->status);

		$res = $this->post('/presets/p1/delete');
		$this->assertStringContainsString('Deleted', $res->headers['Location']);
		$this->assertFileDoesNotExist($this->dir . '/storage/presets/p1.json');
	}

	public function testAdmissionRefusalOffersForce(): void
	{
		$this->app   = $this->makeApp(0.000001); // ~1 MB available
		$this->web   = new WebApp($this->app);
		$res         = $this->post('/presets/p1/start');
		$this->assertSame(200, $res->status);
		$this->assertStringContainsString('Not enough memory', $res->body);
		$this->assertStringContainsString('Start anyway', $res->body);
		$this->assertStringContainsString('name="force" value="1"', $res->body);
	}

	public function testLogRaw(): void
	{
		$this->post('/presets/p1/start');
		$this->app->processes->waitUntilReady('p1', 30);
		$res = $this->get('/presets/p1/log', ['raw' => '1']);
		$this->assertSame(200, $res->status);
		$this->assertStringContainsString('text/plain', $res->headers['Content-Type']);
		$this->assertStringContainsString('fake-llama-server args:', $res->body);
	}

	public function testApiStatus(): void
	{
		$res = $this->get('/api/status');
		$this->assertSame(200, $res->status);
		$this->assertStringContainsString('application/json', $res->headers['Content-Type']);
		$this->assertIsArray(json_decode($res->body, true));
	}

	public function testModelsPage(): void
	{
		$res = $this->get('/models');
		$this->assertStringContainsString('m', $res->body);
		$this->assertStringContainsString('llama', $res->body);
	}

	public function testNotFound(): void
	{
		$this->assertSame(404, $this->get('/nope')->status);
	}

	public function testXss(): void
	{
		$this->app->presets->save(new Preset([
			'id' => 'xss', 'name' => '<img src=x onerror=alert(1)>',
			'engine_id' => 'e', 'model_path' => $this->dir . '/models/m.gguf',
		]));
		$body = $this->get('/presets')->body;
		$this->assertStringNotContainsString('<img src=x', $body);
		$this->assertStringContainsString('&lt;img src=x', $body);
	}

	public function testBrokenFileBanner(): void
	{
		file_put_contents($this->dir . '/storage/presets/bad.json', '{nope');
		$res = $this->get('/presets');
		$this->assertSame(200, $res->status);
		$this->assertStringContainsString('p1', $res->body);        // good ones still listed
		$this->assertStringContainsString('bad.json', $res->body);  // broken one reported
		$this->assertStringContainsString('Config file has malformed JSON:', $res->body);
		// the malformed file is never touched
		$this->assertSame('{nope', file_get_contents($this->dir . '/storage/presets/bad.json'));
	}
}
