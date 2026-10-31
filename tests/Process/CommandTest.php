<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Process;

use LlmChooser\Process\Command;
use PHPUnit\Framework\TestCase;

class CommandTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function quoteCases(): array
    {
        return [
            // Bare: plain words, paths, numbers.
            'word'              => ['llama-server', 'llama-server'],
            'path'              => ['/opt/llama.cpp/bin/llama-server', '/opt/llama.cpp/bin/llama-server'],
            'model file'        => ['/models/Qwen3-32B-Q4_K_M.gguf', '/models/Qwen3-32B-Q4_K_M.gguf'],
            'float'             => ['0.8', '0.8'],
            'tensor split'      => ['1,2', '1,2'],

            // Dash-prefixed words: bare when safe; when the value needs
            // quoting, only the value is quoted (see quoteArg).
            'long option'       => ['--ctx-size', '--ctx-size'],
            'short option'      => ['-ngl', '-ngl'],
            'negative number'   => ['-1', '-1'],
            'option=value'      => ['--flash-attn=auto', '--flash-attn=auto'],
            'option value space' => ['--foo=bar baz', "--foo='bar baz'"],
            'option value quote' => ["--foo=a'b", "--foo='a'\\''b'"],
            'option multi equals' => ['--foo=a=b c', "--foo='a=b c'"],
            'option no equals'   => ['--foo bar', "'--foo bar'"],
            'option unsafe name' => ['--fo$o=bar', "'--fo\$o=bar'"],

            // Quoted: characters a POSIX shell would act on.
            'space'             => ['My Model', "'My Model'"],
            'single quote'      => ["it's", "'it'\\''s'"],
            'dollar'            => ['$HOME', "'\$HOME'"],
            'backtick'          => ['`id`', "'`id`'"],
            'glob'              => ['*.gguf', "'*.gguf'"],
            'tilde'             => ['~/x', "'~/x'"],
            'empty'             => ['', "''"],
        ];
    }

    /** @dataProvider quoteCases */
    public function test_quoteArg(string $arg, string $expected): void
    {
        $this->assertSame($expected, Command::quoteArg($arg));
    }

    public function test_toString_quotes_only_where_needed(): void
    {
        $cmd = new Command(
            ['/opt/x/llama-server', '--host', '0.0.0.0', '--alias', 'My Model', '-1'],
            [],
        );
        $this->assertSame("/opt/x/llama-server --host 0.0.0.0 --alias 'My Model' -1", $cmd->toString());
    }
}
