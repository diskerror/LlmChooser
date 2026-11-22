<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Http;

use LlmChooser\Http\Html;
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
}
