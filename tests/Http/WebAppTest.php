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
		// Phase 11: stopped presets show under Available, not as 'stopped' rows.
		$this->assertStringContainsString('Available', $res->body);
		$this->assertStringContainsString('Nothing loaded.', $res->body);
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

	// ---- Phase 11: Loaded/Available panels, memory bar, Test ----

	/** HTML between two markers, so 'appears in panel A not B' is real. */
	private function section(string $body, string $h2): string
	{
		$start = strpos($body, '<h2>' . $h2 . '</h2>');
		$this->assertIsInt($start, "no {$h2} section");
		$end = strpos($body, '</section>', $start);
		return substr($body, $start, $end - $start);
	}

	public function testDashboardSplitsLoadedAndAvailable(): void
	{
		$this->post('/presets/p1/start');
		$this->app->processes->waitUntilReady('p1', 30);

		$body = $this->get('/')->body;
		$loaded    = $this->section($body, 'Loaded');
		$available = $this->section($body, 'Available');
		$this->assertStringContainsString('/presets/p1/edit', $loaded);
		$this->assertStringContainsString('badge-ready', $loaded);
		$this->assertStringNotContainsString('/presets/p2/edit', $loaded);
		$this->assertStringContainsString('/presets/p2/edit', $available);
		$this->assertStringNotContainsString('/presets/p1/edit', $available);
	}

	public function testStatusColumnShowsActivityInPlaceOfReady(): void
	{
		$this->post('/presets/p1/start');
		$this->app->processes->waitUntilReady('p1', 30);
		$probe = new class extends \LlmChooser\Process\ActivityProbe {
			public function probe(string $host, int $port): array
			{
				return ['phase' => 'generating', 'busy' => 1, 'slots' => 1, 'decoded' => 42,
					'slot_ctx' => 65536, 'peak_ctx' => 64000, 'gen_tps' => 7.5, 'prompt_tps' => 0.0];
			}
		};
		$web    = new WebApp($this->app, $probe);
		$loaded = $this->section($web->handle(new \LlmChooser\Http\Request('GET', '/', [], [], []))->body, 'Loaded');
		$this->assertStringContainsString('badge-busy">generating · 42 tok · 7.5 t/s', $loaded);
		$this->assertStringContainsString('badge-warn">ctx peak 62.5K/64K', $loaded);
		$this->assertStringNotContainsString('badge-ready', $loaded);
		$this->assertStringNotContainsString('Activity', $loaded);
	}

	public function testDashboardExcludesDisabledFromAvailable(): void
	{
		$this->app->presets->setEnabled('p1', false);
		$available = $this->section($this->get('/')->body, 'Available');
		$this->assertStringNotContainsString('/presets/p1/edit', $available);
		$this->assertStringContainsString('/presets/p2/edit', $available);
	}

	public function testAvailableHasNoFitsColumnAndShowsBareGb(): void
	{
		$available = $this->section($this->get('/')->body, 'Available');
		$this->assertStringNotContainsString('Fits?', $available);
		$this->assertStringNotContainsString('fits', $available);
		$this->assertStringContainsString('<td class="num">2.0</td>', $available); // GB, one decimal, no unit
	}

	public function testPresetFormShowsLiveMemoryEstimate(): void
	{
		$this->assertMatchesRegularExpression('#Context &amp; memory[^<]*— <span id="mem-estimate"[^>]*>[\d.]+ GB</span> estimated#u', $this->get('/presets/p1/edit')->body);

		$res  = $this->post('/api/preset-estimate', ['model_path' => $this->dir . '/models/m.gguf', 'args' => ['ctx_size' => '512']]);
		$data = json_decode($res->body, true);
		$this->assertMatchesRegularExpression('/^\d+\.\d$/', $data['gb']);
		$this->assertNull(json_decode($this->post('/api/preset-estimate', [])->body, true)['gb']);
	}

	public function testMemoryPanelColumnsAndLiveFragment(): void
	{
		$body = $this->get('/')->body;
		$this->assertStringContainsString('<th class="num">Total (GB)</th><th class="num">In use (GB)</th><th class="num">Available (GB)</th>', $body);
		$this->assertStringNotContainsString('GPU-held', $body);
		$this->assertArrayHasKey('memory_html', json_decode($this->get('/api/dashboard-panels')->body, true));
	}

	public function testMemoryBarRendersOneSegmentPerRunningInstance(): void
	{
		$this->post('/presets/p1/start');
		$this->post('/presets/p2/start');
		$this->app->processes->waitUntilReady('p1', 30);
		$this->app->processes->waitUntilReady('p2', 30);

		$mem = $this->section($this->get('/')->body, 'Memory');
		$this->assertSame(2, substr_count($mem, 'class="segment"') + substr_count($mem, 'class="segment loading"'));
		$this->assertStringContainsString('title="p1:', $mem);
		$this->assertStringContainsString('title="p2:', $mem);
	}

	public function testApiDashboardPanelsReturnsHtmlFragments(): void
	{
		$this->post('/presets/p1/start');
		$this->app->processes->waitUntilReady('p1', 30);

		$res = $this->get('/api/dashboard-panels');
		$this->assertSame(200, $res->status);
		$data = json_decode($res->body, true);
		$this->assertIsArray($data);
		$this->assertArrayHasKey('loaded_html', $data);
		$this->assertArrayHasKey('available_html', $data);
		$this->assertStringContainsString('/presets/p1/edit', $data['loaded_html']);
		$this->assertStringContainsString('/presets/p2/edit', $data['available_html']);
	}

	public function testTestEndpointRunningPresetReturnsTiming(): void
	{
		$this->post('/presets/p1/start');
		$this->app->processes->waitUntilReady('p1', 30);

		$res = $this->post('/presets/p1/test');
		$this->assertSame(200, $res->status);
		$data = json_decode($res->body, true);
		$this->assertTrue($data['ok'], $res->body);
		$this->assertIsInt($data['ttft_ms']);
		$this->assertIsInt($data['total_ms']);
		$this->assertGreaterThanOrEqual(0, $data['ttft_ms']);
		$this->assertGreaterThanOrEqual($data['ttft_ms'], $data['total_ms']);
	}

	public function testTestEndpointNotRunningPreset(): void
	{
		$res  = $this->post('/presets/p1/test');
		$data = json_decode($res->body, true);
		$this->assertFalse($data['ok']);
		$this->assertStringContainsString('not running', $data['error']);
	}

	public function testTestEndpointDeadUpstream(): void
	{
		$this->post('/presets/p1/start');
		$this->app->processes->waitUntilReady('p1', 30);
		$i = $this->app->state->get('p1');
		(new \LlmChooser\Process\ProcessControl())->signal((int)$i->pid, 'KILL');
		// give the OS a moment, then skip refresh() so the status is still
		// READY and the curl path is what reports the death.
		usleep(300000);

		$res  = $this->post('/presets/p1/test');
		$data = json_decode($res->body, true);
		$this->assertFalse($data['ok'], $res->body);
		$this->assertStringContainsString('upstream failed', $data['error']);
	}

	public function testHeaderShowsRunningCountAndMemory(): void
	{
		$this->post('/presets/p1/start');
		$this->app->processes->waitUntilReady('p1', 30);

		$body = $this->get('/')->body;
		$this->assertStringContainsString('<span class="summary">', $body);
		$this->assertStringContainsString('1 running', $body);
		$this->assertStringContainsString('RAM ', $body);
		$this->assertStringContainsString('/100.0 GB', $body);

		// summary appears on other pages too
		$this->assertStringContainsString('1 running', $this->get('/presets')->body);
	}

	// ---- Phase 12: Models table + drawer ----

	public function testModelsTableShowsNewColumns(): void
	{
		// base fixture: 32 blocks, ctx 8192, embed 4096, 32/8 heads (GQA 4x), head dim 128
		$body = $this->get('/models')->body;
		$this->assertStringContainsString('<th class="sortable">Arch</th>', $body);
		$this->assertStringContainsString('llama', $body);
		$this->assertStringContainsString('>8192<', $body);
		$this->assertStringContainsString('>32<', $body);            // layers
		$this->assertStringContainsString('32/8 (GQA 4×)', $body);   // heads
		$this->assertStringContainsString('>128<', $body);           // head dim
	}

	public function testModelsHasPresetColumn(): void
	{
		$body = $this->get('/models')->body;
		$this->assertStringContainsString('<th>Has preset</th>', $body);
		// both p1 and p2 reference the only fixture model -> one ✓, no links
		$this->assertStringContainsString('✓', $body);
		$this->assertStringNotContainsString('Used by', $body);
	}

	public function testModelsSizeColumnIsBareGbNumber(): void
	{
		$body = $this->get('/models')->body;
		$this->assertStringContainsString('<th class="sortable">Size (GB)</th>', $body);
		// size cell: digits and a dot only, no unit suffix
		$this->assertMatchesRegularExpression('/<td data-sort="\d+">[\d.]+<\/td>/', $body);
	}

	public function testModelNameCellIsThreeLines(): void
	{
		// fixture display name is just "m" (no directories): only the file line
		$this->assertStringContainsString('<div class="model-name">m</div>', $this->get('/models')->body);

		// nested model: provider/middle/file split across the three divs
		$dir = $this->dir . '/models/acme/batch1';
		mkdir($dir, 0775, true);
		GgufFixture::model($dir . '/big.gguf', 'llama', 500);
		$this->app = $this->makeApp(100);
		$this->web = new WebApp($this->app);
		$body = $this->get('/models')->body;
		$this->assertStringContainsString('<div class="model-name">big</div>', $body);
		$this->assertStringContainsString('<div class="model-path muted">batch1</div>', $body);
		$this->assertStringContainsString('<div class="model-provider muted">acme</div>', $body);
	}

	public function testModelDrawerShowsKvCacheEstimates(): void
	{
		$body = $this->get('/models')->body;
		$this->assertStringContainsString('KV cache / 1K tokens', $body);
		// exact expectations: f16 1000*32*(1024+1024)*2 = 131,072,000 = 125 MiB
		$f16 = 1000 * 32 * 2048 * 2;
		$q80 = (int)ceil(1000 * 32 * 2048 * (34 / 32));
		$q40 = (int)ceil(1000 * 32 * 2048 * (18 / 32));
		$this->assertStringContainsString('f16: ' . $this->bin($f16), $body);
		$this->assertStringContainsString('q8_0: ' . $this->bin($q80), $body);
		$this->assertStringContainsString('q4_0: ' . $this->bin($q40), $body);
		$this->assertGreaterThan($q80, $f16);
		$this->assertGreaterThan($q40, $q80);
	}

	private function bin(int $bytes): string
	{
		$gib = 1024 ** 3;
		$mib = 1024 ** 2;
		return match (true) {
			$bytes >= $gib => sprintf('%.1f GiB', $bytes / $gib),
			$bytes >= $mib => sprintf('%.0f MiB', $bytes / $mib),
			default        => $bytes . ' B',
		};
	}

	public function testNewPresetFromModelPrefillsFields(): void
	{
		$path = $this->dir . '/models/m.gguf';
		$body = $this->get('/presets/new', ['model' => $path])->body;
		$this->assertStringContainsString('value="' . htmlspecialchars($path, ENT_QUOTES) . '" selected', $body);
		// 100 GiB free, tiny fixture: max ctx 8192 fits at q8_0 KV.
		$this->assertStringContainsString('name="args[ctx_size]" value="8192"', $body);
	}

	public function testNewPresetFromModelCtxBacksOffWhenTight(): void
	{
		// 1.5 GiB free: 8192 ctx needs ~570 MiB q8_0 KV + 1 GiB fixed
		// overhead = ~1.6 GiB > budget; half (4096) needs ~1.3 GiB, fits.
		$this->app = $this->makeApp(1.5);
		$this->web = new WebApp($this->app);
		$body = $this->get('/presets/new', ['model' => $this->dir . '/models/m.gguf'])->body;
		$this->assertStringContainsString('name="args[ctx_size]" value="4096"', $body);
	}

	public function testScanErrorRowStillShowsWarningBadge(): void
	{
		file_put_contents($this->dir . '/models/broken.gguf', 'GGUF3-not-really');
		$this->app = $this->makeApp(100); // fresh app: cache must not hide the new file
		$this->web = new WebApp($this->app);
		$body = $this->get('/models')->body;
		$this->assertStringContainsString('badge-error', $body);
		$this->assertStringContainsString('broken', $body);
	}

	// ---- Engines tab ----

	public function testEnginesListShowsSeededEngineAndInUse(): void
	{
		$body = $this->get('/engines')->body;
		$this->assertSame(200, $this->get('/engines')->status);
		$this->assertStringContainsString('/engines/e/edit', $body);
		$this->assertStringContainsString('<th>in use</th>', $body);
		// p1 and p2 both reference engine 'e'
		$this->assertStringContainsString('p1', $body);
		$this->assertStringContainsString('p2', $body);
	}

	public function testEnginesListOffersDuplicateAndNew(): void
	{
		$body = $this->get('/engines')->body;
		$this->assertStringContainsString('/engines/new', $body);
		$this->assertStringContainsString('/engines/new?copy=e', $body);
	}

	public function testEngineNewForm(): void
	{
		$res = $this->get('/engines/new');
		$this->assertSame(200, $res->status);
		$this->assertStringContainsString('name="binary_path_default"', $res->body);
		$this->assertStringContainsString('name="model_dir"', $res->body);
	}

	public function testEngineNewFormPrefillsFromCopy(): void
	{
		$body = $this->get('/engines/new', ['copy' => 'e'])->body;
		$this->assertStringContainsString('value="e-copy"', $body);
		$this->assertStringContainsString('value="' . htmlspecialchars((string)$this->app->engines->load('e')->binary_path_default, ENT_QUOTES) . '"', $body);
		$this->assertStringContainsString('value="' . htmlspecialchars($this->dir . '/models', ENT_QUOTES) . '"', $body);
	}

	public function testCreateEngine(): void
	{
		$res = $this->post('/engines/new', [
			'id' => 'e2', 'name' => 'E2', 'binary_path_default' => '/bin/llama-server',
			'model_dir' => $this->dir . '/models', 'host' => '127.0.0.1',
			'port_min' => '5990', 'port_max' => '5999',
		]);
		$this->assertSame(303, $res->status);
		$this->assertStringStartsWith('/engines?msg=', $res->headers['Location']);
		$loaded = $this->app->engines->load('e2');
		$this->assertSame('E2', $loaded->name);
		$this->assertSame($this->dir . '/models', $loaded->model_dir);
	}

	public function testCreateEngineDuplicateIdIs422(): void
	{
		$res = $this->post('/engines/new', [
			'id' => 'e', 'name' => 'Dup', 'binary_path_default' => '/bin/llama-server',
			'host' => '127.0.0.1', 'port_min' => '6000', 'port_max' => '6010',
		]);
		$this->assertSame(422, $res->status);
		$this->assertStringContainsString('Already exists', $res->body);
	}

	public function testCreateEngineMissingRequiredFieldsIs422(): void
	{
		$res = $this->post('/engines/new', ['id' => 'e3']);
		$this->assertSame(422, $res->status);
		$this->assertStringContainsString('Required', $res->body);
	}

	public function testEngineEditForm(): void
	{
		$body = $this->get('/engines/e/edit')->body;
		$this->assertSame(200, $this->get('/engines/e/edit')->status);
		$this->assertStringContainsString('value="e"', $body);
		$this->assertStringContainsString('readonly', $body); // id locked after creation
	}

	public function testEngineEditNotFound(): void
	{
		$this->assertSame(404, $this->get('/engines/ghost/edit')->status);
	}

	public function testEngineSaveUpdatesFieldsAndKeepsId(): void
	{
		$res = $this->post('/engines/e/edit', [
			'id' => 'IGNORED', 'name' => 'E renamed', 'binary_path_default' => '/bin/llama-server',
			'model_dir' => $this->dir . '/models2', 'host' => '127.0.0.1',
			'port_min' => '5880', 'port_max' => '5889',
		]);
		$this->assertSame(303, $res->status);
		$after = $this->app->engines->load('e');
		$this->assertSame('e', $after->id); // never renamed
		$this->assertSame('E renamed', $after->name);
		$this->assertSame($this->dir . '/models2', $after->model_dir);
	}

	public function testEngineSaveValidationFailureIs422(): void
	{
		$res = $this->post('/engines/e/edit', [
			'id' => 'e', 'name' => '', 'binary_path_default' => '/bin/llama-server',
			'host' => '127.0.0.1', 'port_min' => '5880', 'port_max' => '5889',
		]);
		$this->assertSame(422, $res->status);
		$this->assertStringContainsString('Required', $res->body);
	}

	public function testEngineDeleteRefusedWhileInUse(): void
	{
		$res = $this->post('/engines/e/delete');
		$this->assertStringContainsString('In%20use%20by', $res->headers['Location']);
		$this->assertFileExists($this->dir . '/storage/engines/e.json');
	}

	public function testEngineDeleteSucceedsWhenUnused(): void
	{
		$this->post('/engines/new', [
			'id' => 'unused', 'name' => 'Unused', 'binary_path_default' => '/bin/llama-server',
			'host' => '127.0.0.1', 'port_min' => '6100', 'port_max' => '6110',
		]);
		$res = $this->post('/engines/unused/delete');
		$this->assertStringContainsString('Deleted', $res->headers['Location']);
		$this->assertFileDoesNotExist($this->dir . '/storage/engines/unused.json');
	}

	public function testPresetFormHasEnginePickerDialog(): void
	{
		$body = $this->get('/presets/new')->body;
		$this->assertStringContainsString('engine-picker-dialog', $body);
		$this->assertStringContainsString('class="engine-pick" data-engine-id="e"', $body);
		$this->assertStringContainsString('<input type="hidden" name="engine_id" id="engine_id"', $body);
	}

	public function testTabStripIncludesEngines(): void
	{
		$this->assertStringContainsString('href="/engines" class="active"', $this->get('/engines')->body);
		$this->assertStringNotContainsString('href="/engines" class="active"', $this->get('/models')->body);
	}
}
