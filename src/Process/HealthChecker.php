<?php

declare(strict_types=1);

namespace LlmChooser\Process;

class HealthChecker
{
	public const READY       = 'ready';
	public const LOADING     = 'loading';
	public const UNREACHABLE = 'unreachable';

	/** @return array{0:string,1:string} [state, raw body] */
	public function check(string $host, int $port, float $timeout = 2.0): array
	{
		$ctx  = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
		$body = @file_get_contents("http://{$host}:{$port}/health", false, $ctx);
		if ($body === false || !isset($http_response_header[0])) {
			return [self::UNREACHABLE, ''];
		}
		$code = (int)(explode(' ', $http_response_header[0])[1] ?? 0);
		return [$code === 200 ? self::READY : self::LOADING, $body];
	}
}
