<?php

declare(strict_types=1);

namespace LLMchooser\Cli;

/** Writes to two streams so tests can capture output. */
final class Output
{
	/** @param resource $out @param resource $err */
	public function __construct(private $out = STDOUT, private $err = STDERR) {}

	public function line(string $s = ''): void
	{
		fwrite($this->out, $s . "\n");
	}

	public function error(string $s): void
	{
		fwrite($this->err, $s . "\n");
	}

	/**
	 * Left-aligned text table.
	 *
	 * @param list<string>       $headers
	 * @param list<list<string>> $rows
	 */
	public function table(array $headers, array $rows): void
	{
		$w = array_map('strlen', $headers);
		foreach ($rows as $r) {
			foreach ($r as $i => $c) {
				$w[$i] = max($w[$i] ?? 0, strlen($c));
			}
		}
		$fmt = fn(array $r) => rtrim(implode('  ', array_map(fn($c, $i) => str_pad($c, $w[$i]), $r, array_keys($r))));
		$this->line($fmt($headers));
		$this->line($fmt(array_map(fn($x) => str_repeat('-', $x), $w)));
		foreach ($rows as $r) {
			$this->line($fmt($r));
		}
	}
}
