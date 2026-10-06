<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Http;

use LlmChooser\App;
use LlmChooser\Engine\Engine;
use LlmChooser\Engine\EngineRepository;
use LlmChooser\Http\Request;
use LlmChooser\Http\Response;
use LlmChooser\Http\WebApp;
use LlmChooser\Memory\Device;
use LlmChooser\Memory\FixedTopologyProbe;
use LlmChooser\Memory\MemoryTopology;
use LlmChooser\Preset\Preset;
use LlmChooser\Preset\PresetRepository;
use LlmChooser\Process\Instance;
use LlmChooser\Tests\Fixtures\FakeServer;
use LlmChooser\Tests\Model\GgufFixture;
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

	// ---- Phase 10: enabled switch on the Presets tab ----

	public function testPresetListShowsEnableToggle(): void
	{
		$res = $this->get('/presets');
		$this->assertStringContainsString('/presets/p1/disable', $res->body);
		$this->assertStringContainsString('Disable', $res->body);
	}

	public function testDisableRouteDisablesAndRedirects(): void
	{
		$res = $this->post('/presets/p1/disable');
		$this->assertSame(303, $res->status);
		$this->assertStringStartsWith('/presets?msg=', $res->headers['Location']);
		$this->assertFalse($this->app->presets->load('p1')->enabled);
	}

	public function testDisableRouteStopsRunningPresetFirst(): void
	{
		$this->post('/presets/p1/start');
		$this->app->processes->waitUntilReady('p1', 30);

		$res = $this->post('/presets/p1/disable');
		$this->assertSame(303, $res->status);
		$this->assertStringContainsString('was%20running', $res->headers['Location']);
		$this->assertNull($this->app->state->get('p1'));
		$this->assertFalse($this->app->presets->load('p1')->enabled);
	}

	public function testEnableRouteEnables(): void
	{
		$this->post('/presets/p1/disable');
		$res = $this->post('/presets/p1/enable');
		$this->assertSame(303, $res->status);
		$this->assertTrue($this->app->presets->load('p1')->enabled);
	}

	public function testDisabledRowsSortedLast(): void
	{
		$this->app->presets->setEnabled('p1', false);
		$body = $this->get('/presets')->body;
		$posP2 = strpos($body, '/presets/p2/edit');
		$posP1 = strpos($body, '/presets/p1/edit');
		$this->assertLessThan($posP1, $posP2, 'disabled p1 must render after enabled p2');
		$this->assertStringContainsString('class="disabled-row"', $body);
	}

	public function testDashboardHidesStartForDisabledStoppedPreset(): void
	{
		$this->app->presets->setEnabled('p1', false);
		$body = $this->get('/')->body;
		$this->assertStringNotContainsString('/presets/p1/start', $body);
		$this->assertStringContainsString('/presets/p2/start', $body);
	}

	public function testRunningPresetRowHasDisableConfirmForm(): void
	{
		$this->post('/presets/p1/start');
		$this->app->processes->waitUntilReady('p1', 30);
		$body = $this->get('/presets')->body;
		$this->assertStringContainsString('disable-form', $body);
		$this->assertStringContainsString('data-running="1"', $body);
		// Delete must not be offered while running.
		$this->assertStringNotContainsString('/presets/p1/delete', $body);
	}

	public function testTabStripMarksActiveTab(): void
	{
		$this->assertStringContainsString('href="/presets" class="active"', $this->get('/presets')->body);
		$this->assertStringContainsString('href="/" class="active"', $this->get('/')->body);
		$this->assertStringContainsString('href="/models" class="active"', $this->get('/models')->body);
		// a preset edit page belongs to the Presets tab
		$this->assertStringContainsString('href="/presets" class="active"', $this->get('/presets/p1/edit')->body);
	}

	public function testAppJsRoute(): void
	{
		$res = $this->get('/assets/app.js');
		$this->assertSame(200, $res->status);
		$this->assertStringContainsString('text/javascript', $res->headers['Content-Type']);
	}

	public function testEditFormPostsEnabledAndKeepsItOnPartialPost(): void
	{
		$body = $this->get('/presets/p1/edit')->body;
		$this->assertStringContainsString('name="enabled"', $body);

		// a POST that omits enabled (older client) must not park the preset
		$this->post('/presets/p1/edit', [
			'id' => 'p1', 'name' => 'ONE', 'engine_id' => 'e',
			'model_path' => $this->dir . '/models/m.gguf',
		]);
		$this->assertTrue($this->app->presets->load('p1')->enabled);

		// posting enabled=0 does park it
		$this->post('/presets/p1/edit', [
			'id' => 'p1', 'name' => 'ONE', 'enabled' => '0', 'engine_id' => 'e',
			'model_path' => $this->dir . '/models/m.gguf',
		]);
		$this->assertFalse($this->app->presets->load('p1')->enabled);
	}
}
