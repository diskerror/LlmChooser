<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Model;

use LlmChooser\Cli\Format;
use LlmChooser\Model\GgufInfo;
use LlmChooser\Model\NgramTable;
use PHPUnit\Framework\TestCase;

final class NgramTableTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/ngram-table-' . bin2hex(random_bytes(4));
		mkdir($this->dir . '/model', 0777, true);
		mkdir($this->dir . '/shared');
	}

	protected function tearDown(): void
	{
		@unlink($this->dir . '/shared/ngrams.gguf');
		@rmdir($this->dir . '/shared');
		@rmdir($this->dir . '/model');
		@rmdir($this->dir);
	}

	private static function info(string $arch, array $extra = []): GgufInfo
	{
		return new GgufInfo(3, 0, ['general.architecture' => $arch] + $extra);
	}

	public function test_other_architectures_have_none(): void
	{
		$this->assertNull(NgramTable::of('/m/x.gguf', self::info('qwen3moe')));
		$this->assertNull(NgramTable::of('/m/x.gguf', null));
	}

	public function test_no_pointer_means_embedded_and_marked_with_asterisk(): void
	{
		$t = NgramTable::of('/m/x.gguf', self::info('qwen4exp'));
		$this->assertTrue($t->embedded);
		$this->assertSame('*', $t->sizeSuffix(Format::gbNumber(...)));
		$this->assertStringStartsWith('* ', $t->note());
	}

	public function test_separate_file_resolved_from_model_folder_and_sized(): void
	{
		file_put_contents($this->dir . '/shared/ngrams.gguf', str_repeat('x', 1234));
		$t = NgramTable::of($this->dir . '/model/trunk.gguf', self::info('qwen4exp', [NgramTable::FILE_KEY => '../shared/ngrams.gguf']));
		$this->assertFalse($t->embedded);
		$this->assertSame($this->dir . '/model/../shared/ngrams.gguf', $t->file_path);
		$this->assertSame(1234, $t->file_bytes);
		$this->assertSame(' + 0.00', $t->sizeSuffix(Format::gbNumber(...)));
		$this->assertStringStartsWith('+ ', $t->note());
	}

	public function test_estimate_suffix_only_for_separate_table_of_known_size(): void
	{
		file_put_contents($this->dir . '/shared/ngrams.gguf', str_repeat('x', 2048));
		$fmt = static fn(int $b): string => $b . ' B';
		$sep = NgramTable::of($this->dir . '/model/trunk.gguf', self::info('qwen4exp', [NgramTable::FILE_KEY => '../shared/ngrams.gguf']));
		$this->assertSame(' + 2048 B mapped', $sep->estimateSuffix($fmt));
		$this->assertSame('', NgramTable::of('/m/x.gguf', self::info('qwen4exp'))->estimateSuffix($fmt));
		$missing = NgramTable::of($this->dir . '/model/trunk.gguf', self::info('qwen4exp', [NgramTable::FILE_KEY => 'nope.gguf']));
		$this->assertSame('', $missing->estimateSuffix($fmt));
		$this->assertNull(NgramTable::forPath(''));
		$this->assertNull(NgramTable::forPath($this->dir . '/model/absent.gguf'));
	}

	public function test_missing_separate_file_is_flagged(): void
	{
		$t = NgramTable::of($this->dir . '/model/trunk.gguf', self::info('qwen4exp', [NgramTable::FILE_KEY => '../shared/nope.gguf']));
		$this->assertNull($t->file_bytes);
		$this->assertSame(' + n-grams (missing)', $t->sizeSuffix(Format::gbNumber(...)));
	}

	public function test_mmap_off_load_modes(): void
	{
		foreach (['none', 'mlock', 'dio'] as $m) {
			$this->assertTrue(NgramTable::mmapOff($m), $m);
		}
		foreach (['auto', 'mmap', 'mmap+mlock'] as $m) {
			$this->assertFalse(NgramTable::mmapOff($m), $m);
		}
	}
}
