<?php

declare(strict_types=1);

namespace LLMchooser\Process;

use LLMchooser\Memory\AdmissionResult;
use RuntimeException;

class AdmissionException extends RuntimeException
{
	public function __construct(public readonly AdmissionResult $result)
	{
		parent::__construct("Not enough memory to start:\n" . $result->message());
	}
}
