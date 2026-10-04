<?php

declare(strict_types=1);

namespace LLMchooser\Cli;

use RuntimeException;

/**
 * A command line that cannot be acted on as written — missing argument, bad
 * option. Distinct from a failure while doing the work, because the fix is to
 * retype the command, not to debug the system.
 */
final class UsageException extends RuntimeException
{
}
