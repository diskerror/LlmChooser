<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Http;

use LlmChooser\Http\Html;
use LlmChooser\Preset\Preset;
use LlmChooser\Tests\Model\GgufFixture;
use PHPUnit\Framework\TestCase;

class HtmlTest extends TestCase
{
	public function test_attrs(): void
	{
		$this->assertSame(' placeholder="a&quot;b" required', Html::attrs(['placeholder' => 'a"b', 'required', 'disabled' => false]));
		$this->assertSame('', Html::attrs([]));
	}

	public function test_options_marks_selected(): void
	{
		$this->assertSame(
			'<option value="a">a</option><option value="b" selected>b</option>',
			Html::options(['a', 'b'], 'b')
		);
	}

	public function test_checkbox_submits_zero_when_unchecked(): void
	{
		$this->assertSame(
			'<input type="hidden" name="x" value="0"><input type="checkbox" name="x" value="1" checked title="t">',
			Html::checkbox('x', true, ' title="t"')
		);
	}

	public function test_lazy_mark_follows_model_and_lazy_mode(): void
	{
		$dir = sys_get_temp_dir() . '/llmchooser-html-' . uniqid('', true);
		mkdir($dir);
		try {
			GgufFixture::model($dir . '/ng.gguf', 'qwen4exp');
			GgufFixture::model($dir . '/plain.gguf', 'llama');
			$mk = static fn(string $m, string $mode): Preset => new Preset(['id' => 'p', 'model_path' => $dir . '/' . $m, 'args' => ['lazy_mode' => $mode]]);

			$this->assertStringContainsString('>*</span>', Html::lazyMark($mk('ng.gguf', 'auto')));
			$this->assertStringContainsString('>*</span>', Html::lazyMark($mk('ng.gguf', 'on')));
			$this->assertSame('', Html::lazyMark($mk('ng.gguf', 'off')));
			$this->assertSame('', Html::lazyMark($mk('plain.gguf', 'on')));
			$this->assertSame('', Html::lazyMark(null));
		}
		finally {
			exec('rm -rf ' . escapeshellarg($dir));
		}
	}
}
