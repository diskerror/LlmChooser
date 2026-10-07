<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Cli;

use LlmChooser\Cli\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmchooser-format-' . uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function test_human_bytes(): void
    {
        $this->assertSame('1.4 GiB', Format::humanBytes(1_500_000_000));
        $this->assertSame('512 MiB', Format::humanBytes(512 * 1024 * 1024));
        $this->assertSame('511 B', Format::humanBytes(511));
        $this->assertSame('-', Format::humanBytes(null));
    }

    public function test_duration(): void
    {
        $this->assertSame('1m 05s', Format::duration(65));
        $this->assertSame('1h 01m', Format::duration(3700));
        $this->assertSame('1d 01h', Format::duration(90000));
        $this->assertSame('-', Format::duration(-1));
        $this->assertSame('0m 00s', Format::duration(0));
    }

    public function test_tail(): void
    {
        $f = $this->dir . '/t.log';
        file_put_contents($f, "one\ntwo\nthree\nfour\nfive\n");
        $this->assertSame("four\nfive", Format::tail($f, 2));
        // Asking for more lines than exist returns all of them.
        $this->assertSame("one\ntwo\nthree\nfour\nfive", Format::tail($f, 50));
    }

    public function test_tail_missing_file(): void
    {
        $this->assertSame('', Format::tail($this->dir . '/nope.log', 5));
    }
}
