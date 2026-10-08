<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Model;

use LlmChooser\Model\GgufInfo;
use LlmChooser\Model\GgufReader as R;
use PHPUnit\Framework\TestCase;

/**
 * Phase 12 helpers: table/drawer getters built on the GgufFixture writer.
 */
class GgufInfoTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/llmchooser-ggufinfo-' . uniqid('', true);
		mkdir($this->dir, 0775, true);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		if (is_dir($this->dir)) {
			rmdir($this->dir);
		}
	}

	private function info(array $kv, array $tensors = []): GgufInfo
	{
		$p = $this->dir . '/m.gguf';
		GgufFixture::write($p, $kv, count($tensors), 100, 3, $tensors);
		return R::read($p);
	}

	public function test_size_label_present(): void
	{
		$i = $this->info([['general.size_label', R::T_STRING, '30B-A3B']]);
		$this->assertSame('30B-A3B', $i->sizeLabel());
	}

	public function test_size_label_absent_is_empty(): void
	{
		$i = $this->info([['general.architecture', R::T_STRING, 'llama']]);
		$this->assertSame('', $i->sizeLabel());
	}

	public function test_file_type_name_known_code(): void
	{
		// Codes from llama.cpp gguf-py LlamaFileType: 17 = MOSTLY_Q5_K_M,
		// 30 = MOSTLY_IQ4_XS, 1 = MOSTLY_F16.
		foreach ([17 => 'Q5_K_M', 30 => 'IQ4_XS', 1 => 'F16', 0 => 'F32'] as $code => $name) {
			$i = $this->info([['general.file_type', R::T_UINT32, $code]]);
			$this->assertSame($name, $i->fileTypeName());
		}
	}

	public function test_file_type_name_unknown_code_falls_back_to_raw(): void
	{
		$i = $this->info([['general.file_type', R::T_UINT32, 99]]);
		$this->assertSame('99', $i->fileTypeName());
	}

	public function test_has_rope_scaling_true_and_false(): void
	{
		$yes = $this->info([
			['general.architecture', R::T_STRING, 'llama'],
			['llama.rope.scaling.type', R::T_STRING, 'yarn'],
		]);
		$no = $this->info([['general.architecture', R::T_STRING, 'llama']]);
		$this->assertTrue($yes->hasRopeScaling());
		$this->assertFalse($no->hasRopeScaling());
	}

	public function test_expert_count_moe_vs_dense(): void
	{
		$moe = $this->info([
			['general.architecture', R::T_STRING, 'qwen3moe'],
			['qwen3moe.expert_count', R::T_UINT32, 128],
			['qwen3moe.expert_used_count', R::T_UINT32, 8],
		]);
		$dense = $this->info([['general.architecture', R::T_STRING, 'llama']]);
		$this->assertSame(128, $moe->expertCount());
		$this->assertSame(8, $moe->expertUsedCount());
		$this->assertSame(0, $dense->expertCount());
		$this->assertSame(0, $dense->expertUsedCount());
	}

	public function test_has_chat_template_true_and_false(): void
	{
		$yes = $this->info([['tokenizer.chat_template', R::T_STRING, "line1\nline2"]]);
		$no  = $this->info([['general.architecture', R::T_STRING, 'llama']]);
		$this->assertTrue($yes->hasChatTemplate());
		$this->assertFalse($no->hasChatTemplate());
	}

	public function test_chat_template_first_line(): void
	{
		$multi = $this->info([['tokenizer.chat_template', R::T_STRING, "{% if message %}\nrest"]]);
		$short = $this->info([['tokenizer.chat_template', R::T_STRING, 'no-newline-template']]);
		$this->assertSame('{% if message %}', $multi->chatTemplateFirstLine());
		$this->assertSame('no-newline-template', $short->chatTemplateFirstLine());
	}

	public function test_chat_template_round_trip(): void
	{
		$with = $this->info([['tokenizer.chat_template', R::T_STRING, "a\nb\nc"]]);
		$this->assertSame("a\nb\nc", $with->chatTemplate());
		$without = $this->info([['general.architecture', R::T_STRING, 'llama']]);
		$this->assertSame('', $without->chatTemplate());

		// the real extracted fixture must survive the GGUF write/read round trip verbatim
		$fixture = (string)file_get_contents(__DIR__ . '/../Fixtures/chat_templates/qwen3-next.jinja');
		$info    = $this->info([['tokenizer.chat_template', R::T_STRING, $fixture]]);
		$this->assertSame($fixture, $info->chatTemplate());
	}

	public function test_vocab_size_fallback(): void
	{
		// The reader skips string arrays (tokenizer.ggml.tokens is one),
		// so the arch vocab_size metadata is the path that works today;
		// the tokens branch is a forward-compatible shortcut only.
		$fallback = $this->info([
			['general.architecture', R::T_STRING, 'llama'],
			['llama.vocab_size', R::T_UINT32, 128000],
		]);
		$this->assertSame(128000, $fallback->vocabSize());
		$none = $this->info([['general.architecture', R::T_STRING, 'llama']]);
		$this->assertSame(0, $none->vocabSize());
	}

	public function test_approx_param_count_sums_tensor_elements(): void
	{
		$i = $this->info(
			[['general.architecture', R::T_STRING, 'llama']],
			[
				['blk.0.attn_q.weight', [4096, 4096], 1], // 16,777,216
				['blk.0.attn_norm.bias', [4096], 1],      // 4,096
				['token_embd.weight', [2, 3, 4], 1],      // 24
			],
		);
		$this->assertSame(16777216 + 4096 + 24, $i->approxParamCount());
		$this->assertSame(3, count($i->tensors));
	}

	public function test_tensor_round_trip_through_cache_array(): void
	{
		$i = $this->info(
			[['general.architecture', R::T_STRING, 'llama']],
			[['w', [8, 16], 1]],
		);
		$j = GgufInfo::fromArray($i->toArray());
		$this->assertSame($i->tensors, $j->tensors);
		$this->assertSame($i->approxParamCount(), $j->approxParamCount());
		// old cache entries without the key must not crash
		$old = GgufInfo::fromArray(['version' => 3, 'tensor_count' => 0, 'metadata' => []]);
		$this->assertSame([], $old->tensors);
	}
}
