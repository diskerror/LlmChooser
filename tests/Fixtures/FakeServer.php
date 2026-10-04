<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Fixtures;

final class FakeServer
{
	/** Writes "$dir/llama-server" (executable) that runs the fake with the current PHP. */
	public static function makeBinary(string $dir): string
	{
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		$bin = $dir . '/llama-server';
		file_put_contents(
			$bin,
			"#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' '
			. escapeshellarg(__DIR__ . '/fake-llama-server.php') . " \"\$@\"\n"
		);
		chmod($bin, 0755);
		return $bin;
	}
}
