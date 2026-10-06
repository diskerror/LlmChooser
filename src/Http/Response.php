<?php

declare(strict_types=1);

namespace LlmChooser\Http;

final class Response
{
	/** @param array<string,string> $headers */
	public function __construct(
		public readonly int $status = 200,
		public readonly string $body = '',
		public readonly array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
	) {}

	public static function html(string $body, int $status = 200): self
	{
		return new self($status, $body);
	}

	public static function json(mixed $data, int $status = 200): self
	{
		return new self($status, (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), ['Content-Type' => 'application/json']);
	}

	public static function redirect(string $location, string $msg = ''): self
	{
		if ($msg !== '') {
			$location .= (str_contains($location, '?') ? '&' : '?') . 'msg=' . rawurlencode($msg);
		}
		return new self(303, '', ['Location' => $location]);
	}

	public static function text(string $body, int $status = 200): self
	{
		return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
	}

	public function send(): void
	{
		http_response_code($this->status);
		foreach ($this->headers as $k => $v) {
			header("{$k}: {$v}");
		}
		echo $this->body;
	}
}
