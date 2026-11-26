<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Http;

use LlmChooser\Http\Html;
use LlmChooser\Memory\Allocation;
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

	public function test_mapped_suffix_only_when_something_is_mapped(): void
	{
		$fmt = static fn(int $b): string => (string)$b;
		$this->assertSame('', Html::mapped(null, $fmt));
		$this->assertSame('', Html::mapped(new Allocation(['cpu' => 5], ['weights' => 5]), $fmt));

		$a    = new Allocation(['cpu' => 5], ['weights' => 5], ['weights' => 3, 'ngrams' => 4]);
		$html = Html::mapped($a, $fmt, ' GB');
		$this->assertStringContainsString('>+ 7 GB mapped</span>', $html);
		$this->assertStringContainsString('class="mapped"', $html);
		$this->assertStringNotContainsString('⚠', $html);
		$this->assertStringContainsString('⚠', Html::mapped($a, $fmt, '', true));
		$this->assertStringNotContainsString('per token', $html);
		$r = new Allocation(['cpu' => 5], ['weights' => 5], ['weights' => 3], [], 2 * 1024 ** 3);
		$this->assertStringContainsString('≈ 2.0 GiB read per token if uncached.', Html::mapped($r, $fmt));
	}
}
