<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Preset;

use LLMchooser\Preset\PresetArgs;
use PHPUnit\Framework\TestCase;

class PresetArgsTest extends TestCase
{
    public function test_defaults(): void
    {
        $args = new PresetArgs([]);
        $this->assertSame(0, $args->ctx_size);
        $this->assertSame(0.8, $args->temp);
        $this->assertSame('auto', $args->flash_attn);
        $this->assertSame('auto', $args->n_gpu_layers);
        $this->assertSame(2048, $args->batch_size);
        $this->assertSame(-1, $args->seed);
        $this->assertTrue($args->no_context_shift);
        $this->assertTrue($args->jinja);
        $this->assertSame('none', $args->spec_type);
        $this->assertSame(16, $args->spec_draft_n_max);
    }

    public function test_override_changes_only_given_fields(): void
    {
        $args = new PresetArgs(['temp' => 0.2]);
        $this->assertSame(0.2, $args->temp);
        $this->assertSame(0.95, $args->top_p); // unchanged default
        $this->assertSame(20, $args->top_k);   // unchanged default
    }

    public function test_string_numeric_coerces_to_int(): void
    {
        $args = new PresetArgs(['ctx_size' => '4096']);
        $this->assertSame(4096, $args->ctx_size);
    }
}
