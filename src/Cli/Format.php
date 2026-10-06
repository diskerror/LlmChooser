<?php

declare(strict_types=1);

namespace LlmChooser\Cli;

final class Format
{
	public static function gb(?int $bytes): string
	{
		return $bytes === null ? '-' : sprintf('%.1f GB', $bytes / 1e9);
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
