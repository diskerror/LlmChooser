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

	/** Seconds since $pid started, or null. */
	public function elapsedSeconds(int $pid): ?int
	{
		$s = $pid > 0 ? trim((string)shell_exec('ps -o etime= -p ' . $pid . ' 2>/dev/null')) : '';
		// [[dd-]hh:]mm:ss — etimes (plain seconds) is Linux-only.
		if (!preg_match('/^(?:(?:(\d+)-)?(\d+):)?(\d+):(\d+)$/', $s, $m)) {
			return null;
		}
		return (((int)$m[1] * 24 + (int)$m[2]) * 60 + (int)$m[3]) * 60 + (int)$m[4];
	}

	/** Real path of the file $pid writes stdout to, or null (not a file, not readable). */
	public function stdoutPath(int $pid): ?string
	{
		if ($pid <= 0) {
			return null;
		}
		$link = @readlink('/proc/' . $pid . '/fd/1');
		if ($link === false) {
			// macOS: lsof prints the name field as "n<path>".
			$out  = (string)shell_exec('lsof -a -p ' . $pid . ' -d 1 -Fn 2>/dev/null');
			$link = preg_match('/^n(\/.+)$/m', $out, $m) ? $m[1] : false;
		}
		return $link !== false && is_file($link) ? (realpath($link) ?: null) : null;
	}

	/**
	 * Every process on the host as [pid, rss bytes, command line]. Used to
	 * find servers that are running but no longer tracked in state.
	 *
	 * @return list<array{pid:int,rss:int,args:string}>
	 */
	public function listProcesses(): array
	{
		$out = [];
		foreach (explode("\n", (string)shell_exec('ps -axwwo pid=,rss=,args= 2>/dev/null')) as $line) {
			if (preg_match('/^\s*(\d+)\s+(\d+)\s+(.+)$/', $line, $m)) {
				$out[] = ['pid' => (int)$m[1], 'rss' => (int)$m[2] * 1024, 'args' => $m[3]];
			}
		}
		return $out;
	}
}
