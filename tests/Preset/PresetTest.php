<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Preset;

use LLMchooser\Preset\Preset;
use LLMchooser\Preset\PresetArgs;
use PHPUnit\Framework\TestCase;

class PresetTest extends TestCase
{
    public function test_nested_typedclass_assignment_preserves_defaults(): void
    {
        $preset = new Preset(['id' => 'qwen-coder', 'args' => ['temp' => 0.1]]);
        $this->assertSame('qwen-coder', $preset->id);
        $this->assertInstanceOf(PresetArgs::class, $preset->args);
        $this->assertSame(0.1, $preset->args->temp);
        $this->assertSame(0.95, $preset->args->top_p); // sibling default preserved
    }

    public function test_toarray_recurses_through_nested_typedclass(): void
    {
        $preset = new Preset(['id' => 'qwen-coder', 'args' => ['temp' => 0.1]]);
        $array = $preset->toArray();
        $this->assertIsArray($array);
        $this->assertArrayHasKey('args', $array);
        $this->assertIsArray($array['args']); // array, not a PresetArgs object
        $this->assertSame(0.1, $array['args']['temp']);
        $this->assertSame(0, $array['args']['ctx_size']);
    }
}
