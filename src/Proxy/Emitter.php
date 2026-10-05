<?php

declare(strict_types=1);

namespace LLMchooser\Proxy;

/** Where the proxy writes its response. Tests use a collecting subclass. */
class Emitter
{
	public function status(int $code): void
	{
		http_response_code($code);
	}

	public function header(string $line): void
	{
		header($line, false);
	}

	public function write(string $chunk): void
	{
		echo $chunk;
		flush();
	}

	/** Called once before the first body byte. */
	public function begin(): void
	{
		while (ob_get_level() > 0) {
			ob_end_flush();
		}
	}
}
