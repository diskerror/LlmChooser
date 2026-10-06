<?php

declare(strict_types=1);

namespace LlmChooser\Process;

final class Command
{
	/**
	 * @param list<string>          $argv argv[0] is the binary
	 * @param array<string, string> $env  extra environment variables
	 */
	public function __construct(
		public readonly array $argv,
		public readonly array $env,
	) {}

	/** For logs and display only — never executed through a shell. */
	public function toString(): string
	{
		return implode(' ', array_map('escapeshellarg', $this->argv));
	}
}
