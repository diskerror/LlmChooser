<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Process;

use LlmChooser\Engine\Engine;
use LlmChooser\Preset\Preset;
use LlmChooser\Process\CommandBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CommandBuilderTest extends TestCase
{
    private CommandBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CommandBuilder();
    }

    private function engine(array $data = []): Engine
    {
        return new Engine(array_merge([
            'id' => 'e',
            'host' => '127.0.0.1',
            'binary_path_default' => '/opt/x/llama-server',
        ], $data));
    }

    /** @param list<string> $argv */
    private function assertPair(array $argv, string $flag, string $value): void
    {
        $i = array_search($flag, $argv, true);
        $this->assertNotFalse($i, $flag . ' missing from argv');
        $this->assertSame($value, $argv[$i + 1], "value of {$flag}");
    }

    public function test_default_build(): void
    {
        $cmd = $this->builder->build(new Preset(['id' => 'p1', 'model_path' => '/m.gguf']), $this->engine(), 5850);
        $argv = $cmd->argv;

        $this->assertSame([
            '/opt/x/llama-server', '--host', '127.0.0.1', '--port', '5850', '--model', '/m.gguf', '--ctx-size', '0',
        ], array_slice($argv, 0, 9));

        $this->assertContains('--temp', $argv);
        $this->assertPair($argv, '--temp', '0.8');
        $this->assertPair($argv, '--alias', 'p1');
        $this->assertContains('--no-context-shift', $argv);
        $this->assertContains('--jinja', $argv);

        $this->assertNotContains('--tensor-split', $argv);
        $this->assertNotContains('--spec-type', $argv);
        $this->assertNotContains('--reasoning-effort', $argv);
        $this->assertNotContains('--mmproj', $argv);

        $this->assertSame('/opt/x', $cmd->env['LD_LIBRARY_PATH']);
    }

    public function test_preset_binary_overrides_engine(): void
    {
        $cmd = $this->builder->build(
            new Preset(['id' => 'p1', 'model_path' => '/m.gguf', 'binary_path' => '/custom/llama-server']),
            $this->engine(),
            5850
        );
        $this->assertSame('/custom/llama-server', $cmd->argv[0]);
        $this->assertSame('/custom', $cmd->env['LD_LIBRARY_PATH']);
    }

    public function test_spec_type_emits_draft_max_next_to_it(): void
    {
        $cmd = $this->builder->build(
            new Preset(['id' => 'p1', 'model_path' => '/m.gguf', 'args' => ['spec_type' => 'draft-mtp', 'spec_draft_n_max' => 2]]),
            $this->engine(),
            5850
        );
        $argv = $cmd->argv;
        $i = array_search('--spec-type', $argv, true);
        $this->assertNotFalse($i, '--spec-type missing from argv');
        $this->assertSame('draft-mtp', $argv[$i + 1]);
        $this->assertSame('--spec-draft-n-max', $argv[$i + 2]);
        $this->assertSame('2', $argv[$i + 3]);
    }

    public function test_metrics_always_on_and_never_doubled(): void
    {
        $plain = $this->builder->build(new Preset(['id' => 'p1', 'model_path' => '/m.gguf']), $this->engine(), 5850);
        $this->assertSame(1, count(array_keys($plain->argv, '--metrics', true)));
        $dup = $this->builder->build(
            new Preset(['id' => 'p1', 'model_path' => '/m.gguf', 'args' => ['extra_flags' => '--metrics']]),
            $this->engine(),
            5850
        );
        $this->assertSame(1, count(array_keys($dup->argv, '--metrics', true)));
        $eng = new Engine(['id' => 'e', 'binary_path_default' => '/bin/llama-server', 'extra_flags' => '--metrics']);
        $viaEngine = $this->builder->build(new Preset(['id' => 'p1', 'model_path' => '/m.gguf']), $eng, 5850);
        $this->assertSame(1, count(array_keys($viaEngine->argv, '--metrics', true)));
    }

    public function test_engine_extra_flags_come_before_preset_extra_flags(): void
    {
        $eng = new Engine(['id' => 'e', 'binary_path_default' => '/bin/llama-server', 'extra_flags' => '--verbose --fit off']);
        $cmd = $this->builder->build(
            new Preset(['id' => 'p1', 'model_path' => '/m.gguf', 'args' => ['extra_flags' => '--fit on']]),
            $eng,
            5850
        );
        $this->assertSame(['--verbose', '--fit', 'off', '--fit', 'on'], array_slice($cmd->argv, -5));
    }

    public function test_engine_extra_flags_cannot_set_host_or_port(): void
    {
        $eng = new Engine(['id' => 'e', 'binary_path_default' => '/bin/llama-server', 'extra_flags' => '--port 1']);
        $this->expectException(\RuntimeException::class);
        $this->builder->build(new Preset(['id' => 'p1', 'model_path' => '/m.gguf']), $eng, 5850);
    }

    public function test_legacy_engine_json_with_metrics_key_still_loads(): void
    {
        $eng = new Engine(['id' => 'e', 'binary_path_default' => '/bin/llama-server', 'metrics' => false]);
        $cmd = $this->builder->build(new Preset(['id' => 'p1', 'model_path' => '/m.gguf']), $eng, 5850);
        $this->assertContains('--metrics', $cmd->argv);
    }

    public function test_extra_flags_appended_last(): void
    {
        $cmd = $this->builder->build(
            new Preset(['id' => 'p1', 'model_path' => '/m.gguf', 'args' => ['extra_flags' => '--metrics --fit off --lazy-mode on']]),
            $this->engine(),
            5850
        );
        $this->assertSame(
            ['--metrics', '--fit', 'off', '--lazy-mode', 'on'],
            array_slice($cmd->argv, -5)
        );
    }

    public function test_extra_flags_may_not_set_host_or_port(): void
    {
        $this->expectException(RuntimeException::class);
        $this->builder->build(
            new Preset(['id' => 'p1', 'model_path' => '/m.gguf', 'args' => ['extra_flags' => '--port 1']]),
            $this->engine(),
            5850
        );
    }

    public function test_split_flags(): void
    {
        $this->assertSame(
            ['--a', '1', '--b', 'x y', '--c', 'z w'],
            CommandBuilder::splitFlags('--a 1 --b "x y" --c \'z w\'')
        );
        $this->assertSame([], CommandBuilder::splitFlags('   '));
        $this->assertSame([], CommandBuilder::splitFlags(''));
        // An empty quoted value is still a value: it must not vanish.
        $this->assertSame(['--b', ''], CommandBuilder::splitFlags('--b ""'));
    }

    public function test_missing_model_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->builder->build(new Preset(['id' => 'p1']), $this->engine(), 5850);
    }

    public function test_number_formatting(): void
    {
        $cmd = $this->builder->build(
            new Preset(['id' => 'p1', 'model_path' => '/m.gguf', 'args' => ['repeat_penalty' => 1.0, 'temp' => 0.05]]),
            $this->engine(),
            5850
        );
        $this->assertPair($cmd->argv, '--repeat-penalty', '1');
        $this->assertPair($cmd->argv, '--temp', '0.05');

        $this->assertSame('0', CommandBuilder::num(0.0));
        $this->assertSame('0', CommandBuilder::num(-0.0));
        $this->assertSame('0.5', CommandBuilder::num(0.5));
        // No exponent form for large or tiny values.
        $this->assertStringNotContainsString('e', CommandBuilder::num(12345.678));
    }
}
