<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;

use function LlmChooser\Config\ensureDir;
use function LlmChooser\Config\writeFileAtomic;

class FilesTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/llmchooser-files-' . uniqid('', true);
		mkdir($this->root);
	}

	protected function tearDown(): void
	{
		@chmod($this->root . '/ro', 0700);
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	public function test_ensure_dir_creates_parents_private(): void
	{
		$old = umask(0);
		try {
			ensureDir($this->root . '/a/b');
		}
		finally {
			umask($old);
		}
		$this->assertDirectoryExists($this->root . '/a/b');
		$this->assertSame(0750, fileperms($this->root . '/a/b') & 0777);
		ensureDir($this->root . '/a/b'); // existing: no error
	}

	public function test_write_replaces_and_leaves_no_temp_file(): void
	{
		$f = $this->root . '/x.json';
		writeFileAtomic($f, 'one');
		writeFileAtomic($f, 'two');
		$this->assertSame('two', file_get_contents($f));
		$this->assertSame(['x.json'], array_values(array_diff(scandir($this->root), ['.', '..'])));
	}

	public function test_write_into_unwritable_dir_throws(): void
	{
		if (function_exists('posix_getuid') && posix_getuid() === 0) {
			$this->markTestSkipped('root ignores directory permissions');
		}
		mkdir($this->root . '/ro', 0500);
		$this->expectException(RuntimeException::class);
		writeFileAtomic($this->root . '/ro/x.json', 'data');
	}
}
