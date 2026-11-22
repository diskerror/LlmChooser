<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Memory;

use LlmChooser\Cli\Format;
use LlmChooser\Memory\AdmissionResult;
use PHPUnit\Framework\TestCase;

class AdmissionResultTest extends TestCase
{
	public function test_message_uses_binary_units(): void
	{
		$gib = 1024 ** 3;
		$r   = new AdmissionResult(false, ['cpu' => ['need' => 40 * $gib, 'already' => 2 * $gib, 'available' => 30 * $gib]]);
		$this->assertSame('FAIL cpu: need 40.0 GiB + already running 2.0 GiB vs available 30.0 GiB', $r->message());
	}

	public function test_gb_number_is_binary(): void
	{
		$this->assertSame('1.00', Format::gbNumber(1024 ** 3));
	}
}
