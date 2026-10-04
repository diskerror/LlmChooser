<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Cli;

use LLMchooser\Cli\CommandRunner;

/**
 * Records every command instead of running it, so the systemd feature is
 * fully tested without ever invoking systemctl or needing root.
 */
final class FakeRunner extends CommandRunner
{
    /** @var list<list<string>> */
    public array $calls = [];
    public bool $root = true;
    public bool $hasSystemctl = true;
    /** @var list<string> argv[1] values that should return exit 1 */
    public array $fail = [];

    /** @param list<string> $argv */
    public function run(array $argv): array
    {
        $this->calls[] = $argv;
        $rc = in_array($argv[1] ?? '', $this->fail, true) ? 1 : 0;
        return [$rc, $rc === 1 ? 'fake failure' : ''];
    }

    public function exists(string $program): bool
    {
        return $this->hasSystemctl;
    }

    public function isRoot(): bool
    {
        return $this->root;
    }
}
