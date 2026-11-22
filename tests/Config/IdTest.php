<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Config;

use LlmChooser\Config\Id;
use LlmChooser\Preset\PresetRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class IdTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function idCases(): array
    {
        return [
            'single letter'     => ['a', true],
            'dotted model id'   => ['qwen3.8-flash', true],
            'mixed allowed'     => ['A_b-c.d', true],
            'digit after dot'   => ['x.1', true],
            'empty'             => ['', false],
            'leading dot'       => ['.a', false],
            'trailing dot'      => ['a.', false],
            'double dot inside' => ['a..b', false],
            'dot dot'           => ['..', false],
            'parent traversal'  => ['../x', false],
            'slash'             => ['a/b', false],
            'space'             => ['a b', false],
            'trailing newline'  => ["abc\n", false],
            'non-ascii'         => ['é', false],
        ];
    }

    /** @dataProvider idCases */
    public function test_isValid(string $id, bool $expected): void
    {
        $this->assertSame($expected, Id::isValid($id));
    }

    public function test_preset_repository_rejects_trailing_newline(): void
    {
        $dir = sys_get_temp_dir() . '/llmchooser-id-tests-' . uniqid('', true);
        mkdir($dir, 0775, true);

        try {
            // The file a valid id "a" would map to exists, but the id "a\n"
            // must never reach it: the shared rule's D modifier rejects the
            // trailing newline, so load() throws instead of reading the file.
            file_put_contents($dir . '/a.json', '{"id":"a"}');
            $repo = new PresetRepository($dir);

            $this->expectException(RuntimeException::class);
            $repo->load("a\n");
        } finally {
            foreach (glob($dir . '/*') as $file) {
                unlink($file);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }
}
