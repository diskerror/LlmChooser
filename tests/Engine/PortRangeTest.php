<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Engine;

use LlmChooser\Engine\PortRange;
use PHPUnit\Framework\TestCase;

class PortRangeTest extends TestCase
{
    public function test_basic_range(): void
    {
        $r = new PortRange(['min' => 5800, 'max' => 5900]);
        $this->assertSame(5800, $r->min);
        $this->assertSame(5900, $r->max);
        $this->assertSame(101, $r->portCount());

        // end() takes a reference, so it cannot be handed a function result.
        $ports = $r->ports();
        $this->assertSame(5800, $ports[0]);
        $this->assertSame(5900, end($ports));
    }

    public function test_out_of_order_is_swapped(): void
    {
        $r = new PortRange(['min' => 5900, 'max' => 5850]);
        $this->assertSame(5850, $r->min);
        $this->assertSame(5900, $r->max);
    }

    public function test_strings_are_coerced_to_int(): void
    {
        $r = new PortRange(['min' => '5900', 'max' => '5850']);
        $this->assertSame(5850, $r->min);
        $this->assertSame(5900, $r->max);
    }

    public function test_clamped_to_usable_port_space(): void
    {
        $r = new PortRange(['min' => 80, 'max' => 99999]);
        $this->assertSame(PortRange::MIN_PORT, $r->min);
        $this->assertSame(PortRange::MAX_PORT, $r->max);
    }

    public function test_clamping_can_collapse_the_range(): void
    {
        $low = new PortRange(['min' => 100, 'max' => 200]);
        $this->assertSame(1024, $low->min);
        $this->assertSame(1024, $low->max);

        $high = new PortRange(['min' => 70000, 'max' => 80000]);
        $this->assertSame(65535, $high->min);
        $this->assertSame(65535, $high->max);
    }

    public function test_partial_assign_keeps_the_other_end(): void
    {
        $r = new PortRange(['min' => 5800, 'max' => 5900]);
        $r->assign(['min' => 5810]);
        $this->assertSame(5810, $r->min);
        $this->assertSame(5900, $r->max);
    }

    public function test_contains_is_inherited_and_coerces(): void
    {
        $r = new PortRange(['min' => 5850, 'max' => 5900]);
        $this->assertTrue($r->contains(5860));
        $this->assertFalse($r->contains(5999));
        // contains() coerces to $_type, so a numeric string works.
        $this->assertTrue($r->contains('5860'));
    }

    public function test_json_round_trip(): void
    {
        $r = new PortRange(['min' => 5800, 'max' => 5900]);
        $this->assertSame('{"min":5800,"max":5900}', json_encode($r));
    }
}
