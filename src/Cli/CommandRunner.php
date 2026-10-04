<?php

declare(strict_types=1);

namespace LLMchooser\Cli;

/** Runs a system command. Replaced by a fake in tests. */
class CommandRunner
{
	/**
	 * @param list<string> $argv
	 * @return array{0:int,1:string} [exit code, combined output]
	 */
	public function run(array $argv): array
	{
		$p = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
		if ($p === false) {
			return [127, 'failed to run ' . $argv[0]];
		}
		$out = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		return [proc_close($p), (string)$out];
	}

	public function exists(string $program): bool
	{
		[$rc] = $this->run(['sh', '-c', 'command -v ' . escapeshellarg($program) . ' >/dev/null']);
		return $rc === 0;
	}

	public function isRoot(): bool
	{
		[$rc, $out] = $this->run(['id', '-u']);
		return $rc === 0 && trim($out) === '0';
	}
}
