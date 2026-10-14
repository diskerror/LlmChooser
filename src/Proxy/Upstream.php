<?php

declare(strict_types=1);

namespace LlmChooser\Proxy;

use LlmChooser\Http\Request;

/** Forwards one request to http://host:port and streams the answer to an Emitter. */
final class Upstream
{
	/** Request headers that must not be forwarded. */
	private const DROP_REQUEST = ['host', 'connection', 'content-length', 'transfer-encoding', 'expect',
		'keep-alive', 'te', 'trailer', 'upgrade', 'proxy-connection', 'x-llmchooser-preset'];

	/** Response headers that must not be passed back (PHP/the web server set their own). */
	private const DROP_RESPONSE = ['transfer-encoding', 'connection', 'content-length', 'keep-alive', 'date', 'server'];

	/** @return array{0:bool,1:string} [ok, curl error] */
	public function forward(Request $r, string $host, int $port, string $pathAndQuery, string $body, Emitter $out): array
	{
		$headers = [];
		foreach ($r->headers as $k => $v) {
			if (!in_array(strtolower($k), self::DROP_REQUEST, true)) {
				$headers[] = "{$k}: {$v}";
			}
		}
		$headers[] = 'Expect:';   // disable curl's 100-continue

		$started = false;
		$ch      = curl_init("http://{$host}:{$port}{$pathAndQuery}");
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => $r->method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 0,          // generations can take a long time
			CURLOPT_HEADERFUNCTION => function ($ch, string $line) use ($out) {
				$t = trim($line);
				if (preg_match('#^HTTP/\S+\s+(\d+)#', $t, $m)) {
					$out->status((int)$m[1]);
				}
				elseif ($t !== '' && str_contains($t, ':')) {
					$name = strtolower(trim(explode(':', $t, 2)[0]));
					if (!in_array($name, self::DROP_RESPONSE, true)) {
						$out->header($t);
					}
				}
				return strlen($line);
			},
			CURLOPT_WRITEFUNCTION  => function ($ch, string $data) use ($out, &$started) {
				if (!$started) {
					$out->begin();
					$started = true;
				}
				$out->write($data);
				return strlen($data);
			},
		]);
		if (!in_array($r->method, ['GET', 'HEAD'], true)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$ok  = curl_exec($ch) !== false;
		$err = $ok ? '' : curl_error($ch);
		return [$ok, $err];
	}
}
