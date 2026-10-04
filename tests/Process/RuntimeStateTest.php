<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Process;

use LLMchooser\Process\Instance;
use LLMchooser\Process\RuntimeState;
use PHPUnit\Framework\TestCase;

class RuntimeStateTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        $this->dir  = sys_get_temp_dir() . '/llmchooser-state-' . uniqid('', true);
        $this->file = $this->dir . '/runtime_state.json';
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

    public function test_empty_and_missing_file(): void
    {
        $state = new RuntimeState($this->file);
        $this->assertCount(0, $state->all());
        $this->assertNull($state->get('x'));

        // A file that exists but is empty is the same as no file.
        $state->update(static function (): void {});
        $this->assertCount(0, (new RuntimeState($this->file))->all());
    }

    public function test_update_persists_for_a_new_object(): void
    {
        (new RuntimeState($this->file))->update(static function ($c): void {
            $c['a'] = new Instance(['preset_id' => 'a', 'pid' => 123, 'port' => 5850]);
        });

        $reloaded = (new RuntimeState($this->file))->get('a');
        $this->assertNotNull($reloaded);
        $this->assertSame(123, $reloaded->pid);
        $this->assertSame(5850, $reloaded->port);
        $this->assertSame(Instance::LOADING, $reloaded->status);
    }

    public function test_unset_removes(): void
    {
        $state = new RuntimeState($this->file);
        $state->update(static function ($c): void {
            $c['a'] = new Instance(['preset_id' => 'a']);
        });
        $this->assertCount(1, $state->all());

        $state->update(static function ($c): void {
            unset($c['a']);
        });
        $this->assertCount(0, (new RuntimeState($this->file))->all());
    }

    public function test_allocation_round_trips(): void
    {
        $allocation = ['per_device' => ['cpu' => 5], 'breakdown' => ['weights' => 5]];
        (new RuntimeState($this->file))->update(static function ($c) use ($allocation): void {
            $c['a'] = new Instance(['preset_id' => 'a', 'allocation' => $allocation]);
        });

        $this->assertSame($allocation, (new RuntimeState($this->file))->get('a')->allocation);
    }
}
