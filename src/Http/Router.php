<?php

declare(strict_types=1);

namespace LLMchooser\Http;

final class Router
{
	/** @var list<array{0:string,1:string,2:callable}> */
	private array $routes = [];

	/**
	 * $pattern like "/presets/{id}/edit". "{name}" matches one path segment
	 * of [A-Za-z0-9_-]+. Handler gets (Request $r, array $params).
	 */
	public function add(string $method, string $pattern, callable $handler): void
	{
		$regex          = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[A-Za-z0-9_-]+)', $pattern) . '$#';
		$this->routes[] = [$method, $regex, $handler];
	}

	public function dispatch(Request $r): Response
	{
		$pathMatched = false;
		foreach ($this->routes as [$method, $regex, $handler]) {
			if (!preg_match($regex, $r->path, $m)) {
				continue;
			}
			$pathMatched = true;
			if ($method !== $r->method) {
				continue;
			}
			$params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
			return $handler($r, $params);
		}
		return $pathMatched
			? Response::text('Method Not Allowed', 405)
			: Response::html(Html::page('Not found', '<p>Not found.</p>'), 404);
	}
}
