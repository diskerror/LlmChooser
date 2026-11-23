<?php

declare(strict_types=1);

namespace LlmChooser\Http;

final readonly class Request
{
	/**
	 * @param array<string,mixed>  $query
	 * @param array<string,mixed>  $post
	 * @param array<string,string> $headers lower-case names
	 */
	public function __construct(
		public string $method,
		public string $path,
		public array $query = [],
		public array $post = [],
		public array $headers = [],
		public string $body = '',
	) {}

	public static function fromGlobals(): self
	{
		$uri  = $_SERVER['REQUEST_URI'] ?? '/';
		$path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
		$h    = [];
		foreach (function_exists('getallheaders') ? getallheaders() : [] as $k => $v) {
			$h[strtolower($k)] = (string)$v;
		}
		return new self(
			strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
			$path,
			$_GET,
			$_POST,
			$h,
			(string)file_get_contents('php://input'),
		);
	}

	public function query(string $k, string $default = ''): string
	{
		$v = $this->query[$k] ?? $default;
		return is_string($v) ? $v : $default;
	}

	public function header(string $name): ?string
	{
		return $this->headers[strtolower($name)] ?? null;
	}
}
