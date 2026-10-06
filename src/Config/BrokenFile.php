<?php

declare(strict_types=1);

namespace LlmChooser\Config;

/**
 * A config file that could not be parsed. Internal, programmer-built, so
 * the members are public: a wrong type here is a logic error.
 *
 * The wording is defined ONCE, here, because the identical string is shown
 * by the CLI, the web UI and the chat-completion error response.
 */
final class BrokenFile
{
	public function __construct(
		public readonly string $path,
		public readonly string $id,
	) {}

	/**
	 * Deliberately terse. PHP's JsonException message is the constant
	 * string "Syntax error" for every malformation — no line, no column —
	 * so there is no detail worth appending. The path is the only
	 * actionable part.
	 */
	public function message(): string
	{
		return 'Config file has malformed JSON: ' . $this->path;
	}
}
