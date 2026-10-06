<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Proxy;

use LlmChooser\Proxy\Emitter;

final class CollectingEmitter extends Emitter
{
	public int $code = 200;
	/** @var list<string> */
	public array $headers = [];
	/** @var list<string> */
	public array $chunks = [];
	/** @var list<float> microtime(true) of each chunk */
	public array $times = [];

	public function status(int $code): void { $this->code = $code; }
	public function header(string $line): void { $this->headers[] = $line; }
	public function begin(): void {}
	public function write(string $chunk): void
	{
		$this->chunks[] = $chunk;
		$this->times[]  = microtime(true);
	}
	public function body(): string { return implode('', $this->chunks); }
	public function json(): array { return json_decode($this->body(), true) ?? []; }
}
