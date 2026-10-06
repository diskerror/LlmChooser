<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Http;

use LlmChooser\Http\Html;
use LlmChooser\Http\Request;
use LlmChooser\Http\Router;
use LlmChooser\Http\Response;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
	private function router(): Router
	{
		$rt = new Router();
		$rt->add('GET', '/presets/{id}', fn(Request $r, array $p): Response => Response::json($p));
		$rt->add('GET', '/', fn(Request $r, array $p): Response => Response::text('root'));
		return $rt;
	}

	public function testRouteParams(): void
	{
		$res = $this->router()->dispatch(new Request('GET', '/presets/abc'));
		$this->assertSame(200, $res->status);
		$this->assertSame(['id' => 'abc'], json_decode($res->body, true));
	}

	public function testTraversalSegmentDoesNotMatch(): void
	{
		$res = $this->router()->dispatch(new Request('GET', '/presets/../x'));
		$this->assertSame(404, $res->status);
	}

	public function testWrongMethodIs405(): void
	{
		$res = $this->router()->dispatch(new Request('POST', '/presets/abc'));
		$this->assertSame(405, $res->status);
	}

	public function testUnknownPathIs404(): void
	{
		$res = $this->router()->dispatch(new Request('GET', '/nope'));
		$this->assertSame(404, $res->status);
	}

	public function testEscaping(): void
	{
		$this->assertSame(
			'&lt;a href=&quot;x&quot;&gt;&amp;&lt;/a&gt;',
			Html::e('<a href="x">&</a>')
		);
	}
}
