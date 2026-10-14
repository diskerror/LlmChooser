<?php

declare(strict_types=1);

namespace LlmChooser\Cli;

final class Format
{
	/**
	 * Human size, powers of two: GiB above 1 GiB, MiB above 1 MiB, else
	 * bytes. (Not decimal GB — the box's RAM numbers must be comparable
	 * with free/htop.)
	 */
	/** Bare GiB value (powers of two), one decimal, no unit — for table cells. */
	public static function gb(?int $bytes): string
	{
		return $bytes === null ? '-' : sprintf('%.1f', $bytes / 1024 ** 3);
	}

	public static function humanBytes(?int $bytes): string
	{
		if ($bytes === null) {
			return '-';
		}
		$gib = 1024 ** 3;
		$mib = 1024 ** 2;
		if ($bytes >= $gib) {
			return sprintf('%.1f GiB', $bytes / $gib);
		}
		if ($bytes >= $mib) {
			return sprintf('%.0f MiB', $bytes / $mib);
		}
		return $bytes . ' B';
	}

	/** Decimal GB, number only — for columns whose header carries the unit. */
	public static function gbNumber(int $bytes): string
	{
		return sprintf('%.2f', $bytes / 1e9);
	}

	/** Parameter count as "31B" / "12.5B" / "780M" (trailing zeros trimmed). */
	public static function paramsHuman(int $n): string
	{
		$trim = static fn(string $s): string => rtrim(rtrim($s, '0'), '.');
		return match (true) {
			$n >= 1_000_000_000 => $trim(sprintf('%.2f', $n / 1e9)) . 'B',
			$n >= 1_000_000     => $trim(sprintf('%.2f', $n / 1e6)) . 'M',
			$n >= 1_000         => $trim(sprintf('%.1f', $n / 1e3)) . 'K',
			default             => (string)$n,
		};
	}

	public static function duration(int $seconds): string
	{
		if ($seconds < 0) {
			return '-';
		}
		$d = intdiv($seconds, 86400);
		$h = intdiv($seconds % 86400, 3600);
		$m = intdiv($seconds % 3600, 60);
		$s = $seconds % 60;
		return $d > 0 ? sprintf('%dd %02dh', $d, $h) : ($h > 0 ? sprintf('%dh %02dm', $h, $m) : sprintf('%dm %02ds', $m, $s));
	}

	/** Last $n lines of a file ('' if missing). Reads at most the last 64 KB. */
	public static function tail(string $file, int $n): string
	{
		if (!is_file($file)) {
			return '';
		}
		$fh = fopen($file, 'rb');
		if ($fh === false) {
			return '';
		}
		$size = (int)filesize($file);
		fseek($fh, max(0, $size - 65536));
		$data = stream_get_contents($fh);
		fclose($fh);
		$lines = preg_split('/\r?\n/', rtrim((string)$data));
		return implode("\n", array_slice($lines ?: [], -$n));
	}
}
