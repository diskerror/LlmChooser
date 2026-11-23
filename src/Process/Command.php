<?php

declare(strict_types=1);

namespace LlmChooser\Process;

final readonly class Command
{
	/**
	 * @param list<string>          $argv argv[0] is the binary
	 * @param array<string, string> $env  extra environment variables
	 */
	public function __construct(
		public array $argv,
		public array $env,
	) {}

	/** For logs and display only — never executed through a shell. */
	public function toString(): string
	{
		return implode(' ', array_map(self::quoteArg(...), $this->argv));
	}

	/**
	 * Quote only what needs it: a leading "--opt=" stays bare, and the rest is
	 * single-quoted unless it is plain (letters, digits, _ @ % + = : , . - /).
	 * So --foo=bar baz -> --foo='bar baz'.
	 */
	public static function quoteArg(string $arg): string
	{
		preg_match('/\A(-[\w-]*=)?(.*)\z/s', $arg, $m);
		$value = $m[2];

		return $m[1] . (preg_match('/\A[\w@%+=:,.\/-]+\z/', $value) ? $value : escapeshellarg($value));
	}
}
