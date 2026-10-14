<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Engine;

use LlmChooser\Engine\BinaryCheck;
use LlmChooser\Engine\EngineHelp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EngineHelpTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/llmchooser-help-' . uniqid('', true);
		mkdir($this->dir, 0775, true);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/{,help/}*', GLOB_BRACE) ?: [] as $f) {
			is_file($f) && unlink($f);
		}
		@rmdir($this->dir . '/help');
		@rmdir($this->dir);
	}

	private function fakeBinary(string $helpBody = "-c,    --ctx-size N                     context size\n"): string
	{
		$bin = $this->dir . '/fake-server';
		file_put_contents($bin, "#!/bin/sh\nif [ \"\$1\" = \"--version\" ]; then echo 'version: 42 (abc)' >&2; exit 0; fi\ncat <<'EOF'\n{$helpBody}EOF\n");
		chmod($bin, 0755);
		return $bin;
	}

	private function help(): EngineHelp
	{
		file_put_contents($this->dir . '/bundled.txt', "--temp N                                temperature (default: 0.8)\n");
		return new EngineHelp($this->dir . '/help', $this->dir . '/bundled.txt');
	}

	public function test_binary_check_reasons(): void
	{
		$this->assertNull(BinaryCheck::problem(''));
		$this->assertSame('binary not found', BinaryCheck::problem($this->dir . '/nope'));
		$this->assertSame('path is a directory', BinaryCheck::problem($this->dir));
		file_put_contents($this->dir . '/plain', 'x');
		chmod($this->dir . '/plain', 0644);
		$this->assertSame('not executable', BinaryCheck::problem($this->dir . '/plain'));
		$this->assertNull(BinaryCheck::problem($this->fakeBinary()));
	}

	public function test_capture_stores_entries_and_version_per_engine(): void
	{
		$h = $this->help();
		$data = $h->capture('e1', $this->fakeBinary());
		$this->assertSame('version: 42 (abc)', $data['version']);
		$this->assertSame('context size', $data['entries']['--ctx-size']['text']);

		$again = $h->forEngine('e1');
		$this->assertSame('engine', $again['source']);
		$this->assertArrayHasKey('--ctx-size', $again['entries']);
	}

	public function test_without_a_capture_the_bundled_snapshot_is_used(): void
	{
		$r = $this->help()->forEngine('never-captured');
		$this->assertSame('bundled', $r['source']);
		$this->assertArrayHasKey('--temp', $r['entries']);
	}

	public function test_capture_failures_throw_readable_messages_and_keep_old_data(): void
	{
		$h = $this->help();
		$h->capture('e1', $this->fakeBinary());
		try {
			$h->capture('e1', $this->dir . '/missing');
			$this->fail('expected exception');
		} catch (RuntimeException $e) {
			$this->assertSame('binary not found', $e->getMessage());
		}
		$this->assertSame('engine', $h->forEngine('e1')['source']); // old capture untouched

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no options found');
		$h->capture('e2', $this->fakeBinary("just prose, no options\n"));
	}

	public function test_delete_removes_the_capture(): void
	{
		$h = $this->help();
		$h->capture('e1', $this->fakeBinary());
		$h->delete('e1');
		$this->assertSame('bundled', $h->forEngine('e1')['source']);
	}

	public function test_version_line_skips_backend_chatter(): void
	{
		$out = "Failed to create /var/www/.cache for shader cache (Permission denied)---disabling.\nggml_vulkan: Found 1 Vulkan devices\nversion: 0.4.0-dev (build 10830, commit 465e49b9c)\nbuilt with GNU 14.2.0\n";
		$this->assertSame('version: 0.4.0-dev (build 10830, commit 465e49b9c)', EngineHelp::versionLine($out));
		$this->assertSame('first line', EngineHelp::versionLine("\nfirst line\nsecond"));
		$this->assertSame('', EngineHelp::versionLine(''));
	}
}
