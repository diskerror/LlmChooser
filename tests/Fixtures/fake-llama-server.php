<?php
// Fake llama-server for tests. Understands --host and --port; ignores everything else.
// Env FAKE_LOAD_MS: /health returns 503 until this many ms after start (default 300).
// Env FAKE_EXIT_AFTER_MS: exit with code 3 after this many ms (simulates a crash).
// Env FAKE_IGNORE_TERM=1: ignore SIGTERM (tests the SIGKILL fallback).
// Other paths: JSON {"echo": request line, "body": request body, "port": N}.
// If the body contains "stream":true, answers with 3 SSE chunks 200 ms apart instead.
$host = '127.0.0.1';
$port = 0;
for ($i = 1; $i < count($argv); ++$i) {
	if ($argv[$i] === '--host') { $host = $argv[++$i]; }
	elseif ($argv[$i] === '--port') { $port = (int)$argv[++$i]; }
}
fwrite(STDERR, 'fake-llama-server args: ' . implode(' ', array_slice($argv, 1)) . "\n");
if (getenv('FAKE_IGNORE_TERM') === '1' && function_exists('pcntl_signal')) {
	pcntl_async_signals(true);
	pcntl_signal(SIGTERM, SIG_IGN);
}
$start  = microtime(true);
$loadMs = (int)(getenv('FAKE_LOAD_MS') ?: 300);
$exitMs = getenv('FAKE_EXIT_AFTER_MS');
$server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
	fwrite(STDERR, "bind failed: {$errstr}\n");
	exit(2);
}
while (true) {
	if ($exitMs !== false && (microtime(true) - $start) * 1000 >= (int)$exitMs) {
		fwrite(STDERR, "fake crash\n");
		exit(3);
	}
	$conn = @stream_socket_accept($server, 0.1);
	if (!$conn) {
		continue;
	}
	// Read headers, then exactly Content-Length body bytes.
	$req = '';
	while (!str_contains($req, "\r\n\r\n") && !feof($conn)) {
		$req .= fread($conn, 8192);
	}
	[$head, $body] = array_pad(explode("\r\n\r\n", $req, 2), 2, '');
	$len = preg_match('/^content-length:\s*(\d+)/mi', $head, $m) ? (int)$m[1] : 0;
	while (strlen($body) < $len && !feof($conn)) {
		$body .= fread($conn, $len - strlen($body));
	}
	$ready = ((microtime(true) - $start) * 1000) >= $loadMs;
	if (str_starts_with($head, 'GET /health')) {
		$out  = $ready ? '{"status":"ok"}' : '{"error":{"code":503,"message":"Loading model"}}';
		$code = $ready ? '200 OK' : '503 Service Unavailable';
		fwrite($conn, "HTTP/1.1 {$code}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($out) . "\r\nConnection: close\r\n\r\n{$out}");
	}
	elseif (str_contains($body, '"stream":true')) {
		fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\n");
		for ($n = 0; $n < 3; ++$n) {
			fwrite($conn, "data: {\"chunk\":{$n}}\n\n");
			fflush($conn);
			usleep(200000);
		}
		fwrite($conn, "data: [DONE]\n\n");
	}
	else {
		$out = json_encode(['echo' => strtok($head, "\r\n"), 'body' => $body, 'port' => $port]);
		fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nX-Fake: 1\r\nContent-Length: " . strlen($out) . "\r\nConnection: close\r\n\r\n{$out}");
	}
	fclose($conn);
}
