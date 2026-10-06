<?php

declare(strict_types=1);

namespace LlmChooser\Process;

/** Thin wrapper around `kill` and `ps` so it works without the posix extension. */
class ProcessControl
{
	public function isAlive(int $pid): bool
	{
		if ($pid <= 0) {
			return false;
		}
		exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
		if ($rc !== 0) {
			return false;
		}
		// A zombie (exited, not yet reaped) counts as dead.
		$stat = trim((string)shell_exec('ps -o stat= -p ' . $pid . ' 2>/dev/null'));
		return $stat !== '' && !str_starts_with($stat, 'Z');
	}

	/** Full command line of $pid, or null if it does not exist. */
	public function commandLine(int $pid): ?string
	{
		if ($pid <= 0) {
			return null;
		}
		$out = trim((string)shell_exec('ps -o args= -p ' . $pid . ' 2>/dev/null'));
		return $out === '' ? null : $out;
	}

	/** $signal is 'TERM' or 'KILL'. */
	public function signal(int $pid, string $signal): void
	{
		if ($pid > 0 && in_array($signal, ['TERM', 'KILL'], true)) {
			exec('kill -' . $signal . ' ' . $pid . ' 2>/dev/null');
		}
	}

	/** Resident memory in bytes, or null. */
	public function rssBytes(int $pid): ?int
	{
		$kb = trim((string)shell_exec('ps -o rss= -p ' . $pid . ' 2>/dev/null'));
		return ctype_digit($kb) ? (int)$kb * 1024 : null;
	}
}
