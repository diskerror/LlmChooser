<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Model;

use LlmChooser\Model\ChatTemplateFamily;
use PHPUnit\Framework\TestCase;

/**
 * Phase 13: fingerprint detection + enum table. The fixture is a real
 * chat template extracted from a real GGUF (see Fixtures/chat_templates/README.md).
 */
class ChatTemplateFamilyTest extends TestCase
{
	private static function fixtureTemplate(): string
	{
		$path = __DIR__ . '/../Fixtures/chat_templates/qwen3-next.jinja';
		$raw  = @file_get_contents($path);
		if ($raw === false) {
			self::fail('missing fixture ' . $path);
		}
		return $raw;
	}

	public function test_detect_real_fixture(): void
	{
		$this->assertSame('qwen3_next', ChatTemplateFamily::detect(self::fixtureTemplate()));
	}

	public function test_detect_unrelated_and_empty(): void
	{
		$this->assertNull(ChatTemplateFamily::detect('Hello {{ message }} world'));
		$this->assertNull(ChatTemplateFamily::detect(''));
	}

	public function test_reasoning_efforts_for_qwen3_next(): void
	{
		$this->assertSame(['xhigh', 'medium', 'low'], ChatTemplateFamily::reasoningEfforts('qwen3_next'));
	}

	public function test_reasoning_efforts_unknown_family(): void
	{
		$this->assertSame([], ChatTemplateFamily::reasoningEfforts('nonsense'));
	}

	public function test_default_reasoning_effort(): void
	{
		$this->assertSame('xhigh', ChatTemplateFamily::defaultReasoningEffort('qwen3_next'));
		$this->assertNull(ChatTemplateFamily::defaultReasoningEffort('nonsense'));
	}
}
