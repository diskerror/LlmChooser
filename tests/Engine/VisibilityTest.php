<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Engine;

use LLMchooser\Engine\Engine;
use LLMchooser\Model\ModelEntry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * The two halves of the Typed visibility rule, proved by behaviour:
 *
 *   outside data  -> coerced and sanitised (never rejected)
 *   internal data -> a wrong type is a logic error (TypeError)
 *
 * NOTE: this file declares strict_types=1, and declare binds at the CALL
 * SITE. Without it, the ModelEntry case below would silently coerce '123'
 * to 123 and the test would fail for the wrong reason.
 */
class VisibilityTest extends TestCase
{
    public function test_outside_data_is_coerced_not_rejected(): void
    {
        $engine = new Engine(['port_range' => ['min' => '5810']]);
        $this->assertSame(5810, $engine->port_range->min);
        $this->assertSame(5900, $engine->port_range->max);
    }

    public function test_unknown_keys_are_ignored_by_assign(): void
    {
        // Intended policy for config files: an extra key is soft, Typed drops
        // it. No exception.
        $engine = new Engine(['nope' => 1]);
        $this->assertSame('', $engine->id);
    }

    public function test_writing_an_unknown_member_throws(): void
    {
        $engine = new Engine();
        $this->expectException(InvalidArgumentException::class);
        $engine->nope = 1;
    }

    public function test_internal_data_of_the_wrong_type_is_a_logic_error(): void
    {
        // ModelEntry is internal, programmer-built, public readonly int.
        $this->expectException(TypeError::class);
        new ModelEntry('/p/x.gguf', 'x', '123', ['/p/x.gguf'], [], null, null);
    }
}
