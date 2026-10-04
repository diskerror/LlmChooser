<?php

declare(strict_types=1);

namespace LLMchooser\Tests\Config;

use LLMchooser\Engine\Engine;
use LLMchooser\Engine\EngineRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * JsonStore is exercised through EngineRepository, its concrete user.
 */
class JsonStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmchooser-jsonstore-tests-' . uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function test_broken_file_does_not_block_the_others(): void
    {
        file_put_contents($this->dir . '/good.json', '{"id":"good","port_range":{"min":5810,"max":5820}}');
        file_put_contents($this->dir . '/broken.json', '{"id":"broken", "name": "oops",,}');

        $repo = new EngineRepository($this->dir);
        $all  = $repo->loadAll();

        $this->assertCount(1, $all);
        $this->assertSame('good', $all['good']->id);
        $this->assertSame(5810, $all['good']->port_range->min);

        $broken = $repo->broken();
        $this->assertCount(1, $broken);
        $this->assertSame('broken', $broken[0]->id);
        $this->assertSame(
            'Config file has malformed JSON: ' . $this->dir . '/broken.json',
            $broken[0]->message()
        );
    }

    public function test_valid_json_that_is_not_an_object_is_broken(): void
    {
        file_put_contents($this->dir . '/scalar.json', '"just a string"');

        $repo = new EngineRepository($this->dir);
        $this->assertCount(0, $repo->loadAll());

        $broken = $repo->broken();
        $this->assertCount(1, $broken);
        // Same wording as a syntax error: there is no second message.
        $this->assertSame(
            'Config file has malformed JSON: ' . $this->dir . '/scalar.json',
            $broken[0]->message()
        );
    }

    public function test_message_names_the_path(): void
    {
        file_put_contents($this->dir . '/broken.json', '{"id":"broken",,}');

        $repo = new EngineRepository($this->dir);
        $repo->loadAll();

        // The path is the only actionable part; JsonException::getMessage()
        // is the constant "Syntax error" for every malformation.
        $this->assertSame(
            'Config file has malformed JSON: ' . $this->dir . '/broken.json',
            $repo->broken()[0]->message()
        );
    }

    public function test_broken_file_is_never_rewritten(): void
    {
        $bytes = '{"id":"broken", "name": "oops",,}';
        file_put_contents($this->dir . '/broken.json', $bytes);

        $repo = new EngineRepository($this->dir);
        $repo->loadAll();

        $this->assertSame($bytes, file_get_contents($this->dir . '/broken.json'));

        try {
            $repo->load('broken');
            $this->fail('load() of a malformed file must throw.');
        } catch (RuntimeException) {
            // expected
        }

        // Still untouched: reading never writes, not even on failure.
        $this->assertSame($bytes, file_get_contents($this->dir . '/broken.json'));
    }

    public function test_unknown_and_stale_keys_are_dropped_with_defaults(): void
    {
        file_put_contents(
            $this->dir . '/stale.json',
            '{"id":"stale","port_range_start":6000,"port_rnage_end":6100,"bogus":true}'
        );

        $repo = new EngineRepository($this->dir);
        $all = $repo->loadAll();

        $this->assertCount(1, $all);
        $this->assertSame([], $repo->broken());
        // The stale/misspelled keys are NOT honoured — defaults apply.
        $this->assertSame(5800, $all['stale']->port_range->min);
        $this->assertSame(5900, $all['stale']->port_range->max);
    }

    public function test_save_heals_the_file(): void
    {
        file_put_contents(
            $this->dir . '/stale.json',
            '{"id":"stale","port_range_start":6000,"port_rnage_end":6100,"bogus":true}'
        );

        $repo = new EngineRepository($this->dir);
        $repo->save($repo->loadAll()['stale']);

        $raw = file_get_contents($this->dir . '/stale.json');
        $this->assertStringContainsString('port_range', $raw);
        $this->assertStringNotContainsString('port_range_start', $raw);
        $this->assertStringNotContainsString('port_rnage_end', $raw);
        $this->assertStringNotContainsString('bogus', $raw);
    }

    public function test_identical_save_does_not_rewrite(): void
    {
        $repo = new EngineRepository($this->dir);
        $repo->save(new Engine(['id' => 'same']));

        clearstatcache();
        $before = filemtime($this->dir . '/same.json');
        sleep(1);

        // Re-saving exactly what came off disk must not touch the file.
        $repo->save($repo->load('same'));
        clearstatcache();

        $this->assertSame($before, filemtime($this->dir . '/same.json'));
    }

    public function test_no_tmp_files_left_behind(): void
    {
        $repo = new EngineRepository($this->dir);
        $repo->save(new Engine(['id' => 'a']));
        $repo->save($repo->load('a'));
        file_put_contents($this->dir . '/bad.json', '{oops');
        $repo->loadAll();

        $this->assertSame([], glob($this->dir . '/*.tmp*'));
    }

    public function test_broken_resets_between_calls(): void
    {
        file_put_contents($this->dir . '/broken.json', '{oops');

        $repo = new EngineRepository($this->dir);
        $repo->loadAll();
        $this->assertCount(1, $repo->broken());

        // Fix the file: a later loadAll() must not still report it.
        file_put_contents($this->dir . '/broken.json', '{"id":"broken"}');
        $all = $repo->loadAll();

        $this->assertSame([], $repo->broken());
        $this->assertCount(1, $all);
    }
}
