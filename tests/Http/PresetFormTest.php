<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Http;

use LlmChooser\Engine\Engine;
use LlmChooser\Engine\EngineRepository;
use LlmChooser\Http\FieldHints;
use LlmChooser\Http\PresetForm;
use LlmChooser\Http\PresetValidator;
use LlmChooser\Model\ModelEntry;
use LlmChooser\Preset\Preset;
use LlmChooser\Preset\PresetArgs;
use LlmChooser\Preset\PresetRepository;
use LlmChooser\Tests\Model\GgufFixture;
use PHPUnit\Framework\TestCase;

class PresetFormTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/llmchooser-form-' . uniqid('', true);
		mkdir($this->dir, 0775, true);
	}

	protected function tearDown(): void
	{
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

	/** @return list<ModelEntry> */
	private function models(): array
	{
		$m = $this->dir . '/m.gguf';
		GgufFixture::model($m, 'llama', 3000);
		$side = $this->dir . '/mmproj-mm.gguf';
		file_put_contents($side, 'x');
		return [new ModelEntry($m, 'm', 3000, [$m], ['mmproj' => [$side], 'draft' => [], 'ngrams' => []], null, null)];
	}

	public function testRenderNewContainsEveryField(): void
	{
		$html = PresetForm::render(new Preset(['id' => '', 'engine_id' => 'e']), $this->models(), true);
		$this->assertStringContainsString('name="args[temp]"', $html);
		$this->assertMatchesRegularExpression('/<select[^>]*name="args\[flash_attn\]"/', $html);
		$this->assertStringContainsString('<input type="hidden" name="args[jinja]" value="0">', $html);
		$this->assertStringContainsString('<input type="checkbox" name="args[jinja]" value="1" checked>', $html);
		foreach ((new PresetArgs())->getPublicNames() as $name) {
			$this->assertStringContainsString('name="args[' . $name . ']"', $html, "missing field {$name}");
		}
	}

	public function testFromPostCoercion(): void
	{
		$data = PresetForm::fromPost([
			'id' => ' p3 ', 'name' => 'P3', 'engine_id' => 'e',
			'model_path' => '/x.gguf', 'mmproj_path' => '',
			'args' => ['jinja' => '0', 'ctx_size' => '8192'],
		]);
		$p = new Preset($data);
		$this->assertSame('p3', $p->id);
		$this->assertFalse($p->args->jinja);
		$this->assertSame(8192, $p->args->ctx_size);
		$this->assertNull($p->mmproj_path);
	}

	public function testMissingModelPathShowsNotFound(): void
	{
		$html = PresetForm::render(new Preset(['id' => 'p', 'model_path' => '/gone/x.gguf']), $this->models(), false);
		$this->assertStringContainsString('(not found) /gone/x.gguf', $html);
	}

	public function testEscaping(): void
	{
		$html = PresetForm::render(new Preset(['id' => 'p', 'name' => '<script>']), $this->models(), false);
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
	}

	public function testValidator(): void
	{
		$engines = new EngineRepository($this->dir . '/engines');
		$engines->save(new Engine(['id' => 'e', 'port_range' => ['min' => 5880, 'max' => 5889]]));
		$presets = new PresetRepository($this->dir . '/presets');
		$presets->save(new Preset(['id' => 'p1', 'engine_id' => 'e']));

		// bad id
		$e = PresetValidator::validate(new Preset(['id' => '../x', 'name' => 'N', 'engine_id' => 'e', 'model_path' => '/x']), $engines, false, $presets);
		$this->assertSame('Only letters, digits, - and _', $e['id']);

		// duplicate id when new
		$e = PresetValidator::validate(new Preset(['id' => 'p1', 'name' => 'N', 'engine_id' => 'e', 'model_path' => $this->dir . '/x.gguf']), $engines, true, $presets);
		$this->assertSame('Already exists', $e['id']);

		// forbidden extra flags
		$e = PresetValidator::validate(new Preset(['id' => 'q', 'name' => 'N', 'engine_id' => 'e', 'model_path' => '/x.gguf', 'args' => ['extra_flags' => '--port 1']]), $engines, true, $presets);
		$this->assertSame('Must not set --host or --port', $e['args.extra_flags']);

		// select domain
		$e = PresetValidator::validate(new Preset(['id' => 'q', 'name' => 'N', 'engine_id' => 'e', 'model_path' => '/x.gguf', 'args' => ['flash_attn' => 'sideways']]), $engines, true, $presets);
		$this->assertSame('Not an allowed value', $e['args.flash_attn']);
		$this->assertSame('File not found', $e['model_path']);

		// all valid, with real fixture files
		$model = $this->dir . '/good.gguf';
		GgufFixture::model($model, 'llama', 3000);
		$mm = $this->dir . '/mm.gguf';
		file_put_contents($mm, 'x');
		$ok = new Preset([
			'id' => 'q', 'name' => 'N', 'engine_id' => 'e', 'model_path' => $model,
			'mmproj_path' => $mm, 'ttl_seconds' => 60,
			'args' => ['flash_attn' => 'on', 'n_gpu_layers' => 'auto', 'tensor_split' => '3,1.5'],
		]);
		$this->assertSame([], PresetValidator::validate($ok, $engines, true, $presets));
		$this->assertNotEmpty(FieldHints::GROUPS);
	}
}
