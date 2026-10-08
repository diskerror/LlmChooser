<?php

declare(strict_types=1);

namespace LlmChooser\Model;

/**
 * Hand-maintained table of known chat-template "families" and the
 * reasoning_effort values each one's template accepts without raising
 * a Jinja exception. Detection is a crude but reliable fingerprint
 * (a fixed substring known to appear in that family's template) — we
 * do not parse or execute Jinja. Add a new entry whenever a Dashboard
 * Test reveals a new template's accepted set; cite the model/path you
 * extracted it from in the comment, the same way qwen3_next was added.
 */
final class ChatTemplateFamily
{
	/**
	 * @return array<string, array{fingerprint: string, reasoning_efforts: list<string>, default: string}>
	 */
	private static function table(): array
	{
		return [
			// Extracted 2026-10-08 from apetersson/Qwen3.8-Flash-Next-GGUF
			// Q5_K_M-BF16-Ngrams/Qwen3.8-Flash-Next-Q5_K_M.gguf via gguf-py
			// on beelink (fixture: tests/Fixtures/chat_templates/qwen3-next.jinja).
			// Template raises on anything outside this set; its own default
			// (reasoning_effort|default('xhigh')) is xhigh.
			'qwen3_next' => [
				'fingerprint'       => "resolved_reasoning_effort not in ('xhigh', 'medium', 'low')",
				'reasoning_efforts' => ['xhigh', 'medium', 'low'],
				'default'           => 'xhigh',
			],
		];
	}

	/** Family key, or null if no known fingerprint matches. */
	public static function detect(string $chatTemplate): ?string
	{
		foreach (self::table() as $key => $def) {
			if ($chatTemplate !== '' && str_contains($chatTemplate, $def['fingerprint'])) {
				return $key;
			}
		}
		return null;
	}

	/** @return list<string> valid reasoning_effort values for this family, or [] if unknown. */
	public static function reasoningEfforts(string $familyKey): array
	{
		return self::table()[$familyKey]['reasoning_efforts'] ?? [];
	}

	public static function defaultReasoningEffort(string $familyKey): ?string
	{
		return self::table()[$familyKey]['default'] ?? null;
	}
}
