<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Process;

use LlmChooser\Process\ActivityProbe;
use PHPUnit\Framework\TestCase;

class ActivityProbeTest extends TestCase
{
    public function test_gauges_parse_prometheus_text(): void
    {
        $g = ActivityProbe::gauges(
            "# HELP llamacpp:n_tokens_max High watermark\n# TYPE llamacpp:n_tokens_max counter\n"
            . "llamacpp:n_tokens_max 63421\nllamacpp:predicted_tokens_seconds 7.25\n"
            . "llamacpp:spec_decode_num_accepted_tokens_per_pos_total{position=\"0\"} 5\n"
        );
        $this->assertSame(63421.0, $g['llamacpp:n_tokens_max']);
        $this->assertSame(7.25, $g['llamacpp:predicted_tokens_seconds']);
        $this->assertCount(2, $g); // labelled samples are skipped
    }

    public function test_unreachable_when_nothing_listens(): void
    {
        $a = (new ActivityProbe(0.3))->probe('127.0.0.1', 1);
        $this->assertSame(ActivityProbe::UNREACHABLE, $a['phase']);
    }
}
