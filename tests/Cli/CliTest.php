<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Cli;

use LlmChooser\App;
use LlmChooser\Cli\Cli;
use LlmChooser\Cli\Output;
use LlmChooser\Cli\StartupManager;
use LlmChooser\Engine\Engine;
use LlmChooser\Engine\EngineRepository;
use LlmChooser\Memory\Device;
use LlmChooser\Memory\FixedTopologyProbe;
use LlmChooser\Memory\MemoryTopology;
use LlmChooser\Preset\Preset;
use LlmChooser\Preset\PresetRepository;
use LlmChooser\Process\Instance;
use LlmChooser\Process\ProcessControl;
use LlmChooser\Tests\Fixtures\FakeServer;
use LlmChooser\Tests\Model\GgufFixture;
use PHPUnit\Framework\TestCase;

/**
 * Drives the real Cli against a complete temp project root, starting the
 * fake llama-server where a live process is needed (ports 5870-5879).
 *
 * Each test gets a fresh root, so documented sequences ("after 6") are
 * performed inside one test method rather than relying on test order.
 */
class CliTest extends TestCase
{
    private string $dir;
    private App $app;
    /** @var resource */
    private $o;
    /** @var resource */
    private $e;
    private FakeRunner $runner;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmchooser-cli-' . uniqid('', true);
        mkdir($this->dir . '/config/engines', 0775, true);
        mkdir($this->dir . '/storage/presets', 0775, true);
        mkdir($this->dir . '/units', 0775, true);

        $bin = FakeServer::makeBinary($this->dir . '/bin');
        GgufFixture::model($this->dir . '/models/m.gguf', 'llama', 3000);

        $engines = new EngineRepository($this->dir . '/config/engines');
        $engines->save(new Engine([
            'id' => 'e',
            'name' => 'E',
            'host' => '127.0.0.1',
            'binary_path_default' => $bin,
            'port_range' => ['min' => 5870, 'max' => 5879],
        ]));

        $presets = new PresetRepository($this->dir . '/storage/presets');
        foreach (['p1', 'p2'] as $id) {
            $presets->save(new Preset([
                'id' => $id,
                'engine_id' => 'e',
                'model_path' => $this->dir . '/models/m.gguf',
            ]));
        }

        file_put_contents($this->dir . '/config/model_roots.json', json_encode([
            'roots' => [$this->dir . '/models'],
            'cache_file' => 'storage/model_cache.json',
        ]));

        $this->app = $this->makeApp(100);

        $this->o = fopen('php://memory', 'w+');
        $this->e = fopen('php://memory', 'w+');

        $this->runner = new FakeRunner();
    }

    private function makeApp(float $availableGb): App
    {
        $g = 1024 ** 3;
        $topo = new MemoryTopology([
            new Device('cpu', Device::KIND_RAM, 100 * $g, (int)($availableGb * $g)),
        ]);
        return new App($this->dir, new FixedTopologyProbe($topo));
    }

    private function cli(?App $app = null): Cli
    {
        $app ??= $this->app;
        return new Cli($app, new Output($this->o, $this->e), new StartupManager(
            $app,
            new Output($this->o, $this->e),
            $this->runner,
            unitDir: $this->dir . '/units',
            phpBinary: '/usr/bin/php',
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            foreach ($this->app->state->all() as $id => $instance) {
                $this->app->processes->stop((string)$id);
            }
        }
        $this->rmrf($this->dir);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $x) {
            if ($x !== '.' && $x !== '..') {
                $this->rmrf($path . '/' . $x);
            }
        }
        rmdir($path);
    }

    private function out(): string
    {
        rewind($this->o);
        return (string)stream_get_contents($this->o);
    }

    private function err(): string
    {
        rewind($this->e);
        return (string)stream_get_contents($this->e);
    }

    /** @return array{0:int,1:string,2:string} [exit, stdout, stderr] */
    private function cliRun(array $args, ?App $app = null): array
    {
        $rc = $this->cli($app)->run($args);
        return [$rc, $this->out(), $this->err()];
    }

    /** @return list<string> the table row for $id, if any */
    private function rowFor(string $stdout, string $id): ?string
    {
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, $id . ' ') || str_starts_with($line, $id . "\t")) {
                return $line;
            }
        }
        return null;
    }

    public function test_no_args_prints_usage(): void
    {
        [$rc, $out] = $this->cliRun([]);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('Usage: llmchooser', $out);
    }

    public function test_unknown_command(): void
    {
        [$rc, , $err] = $this->cliRun(['bogus']);
        $this->assertSame(2, $rc);
        $this->assertStringContainsString('Unknown command: bogus', $err);
    }

    public function test_missing_id(): void
    {
        [$rc, , $err] = $this->cliRun(['start']);
        $this->assertSame(2, $rc);
        $this->assertStringContainsString('Missing preset id', $err);
    }

    public function test_unknown_option_rejected(): void
    {
        [$rc, , $err] = $this->cliRun(['list', '--nope']);
        $this->assertSame(2, $rc);
        $this->assertStringContainsString('Unknown option: --nope', $err);
    }

    public function test_list(): void
    {
        [$rc, $out] = $this->cliRun(['list']);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('p1', $out);
        $this->assertStringContainsString('p2', $out);
        $this->assertStringContainsString('m.gguf', $out);
    }

    public function test_models(): void
    {
        [$rc, $out] = $this->cliRun(['models']);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('m', $out);
        $this->assertStringContainsString('llama', $out);
    }

    public function test_start_wait_health_stop(): void
    {
        [$rc, $out] = $this->cliRun(['start', 'p1', '--wait']);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('Started p1', $out);
        $this->assertStringContainsString('p1: ready', $out);

        [$rc2, $out2] = $this->cliRun(['health']);
        $this->assertSame(0, $rc2);
        $this->assertStringContainsString('ready', (string)$this->rowFor($out2, 'p1'));
        $this->assertStringContainsString('stopped', (string)$this->rowFor($out2, 'p2'));

        // Detailed view of the live instance.
        [$rc3, $out3] = $this->cliRun(['health', 'p1', '--lines=3']);
        $this->assertSame(0, $rc3);
        $this->assertStringContainsString('status: ready', $out3);
        $this->assertStringContainsString('--- last 3 log lines ---', $out3);
        $this->assertStringContainsString('fake-llama-server args:', $out3);

        [$rc4, $out4] = $this->cliRun(['stop', 'p1']);
        $this->assertSame(0, $rc4);
        $this->assertStringContainsString('Stopped p1', $out4);
        $this->assertNull($this->app->state->get('p1'));
    }

    public function test_detailed_health_of_missing_instance(): void
    {
        [$rc, , $err] = $this->cliRun(['health', 'nope']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('No instance for preset nope', $err);
    }

    public function test_admission_refused_and_forced(): void
    {
        $tiny = $this->makeApp(0.001);
        [$rc, , $err] = $this->cliRun(['start', 'p1'], $tiny);
        $this->assertSame(3, $rc);
        $this->assertStringContainsString('Not enough memory', $err);
        $this->assertStringContainsString('--force', $err);

        [$rc2, $out2] = $this->cliRun(['start', 'p1', '--force', '--wait'], $tiny);
        $this->assertSame(0, $rc2);
        $this->assertStringContainsString('p1: ready', $out2);
    }

    public function test_set_startup_writes_unit_and_calls_systemctl(): void
    {
        [$rc, $out] = $this->cliRun(['set-startup', 'p1']);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('Installed and enabled llmchooser-p1.service', $out);

        $unit = $this->dir . '/units/llmchooser-p1.service';
        $this->assertFileExists($unit);
        $text = (string)file_get_contents($unit);
        $this->assertStringContainsString(
            "ExecStart=/usr/bin/php {$this->dir}/bin/llmchooser start p1 --foreground",
            $text
        );
        $this->assertStringContainsString('User=llama', $text);

        $this->assertSame([
            ['systemctl', 'daemon-reload'],
            ['systemctl', 'enable', 'llmchooser-p1.service'],
        ], $this->runner->calls);
    }

    public function test_set_startup_not_root(): void
    {
        $this->runner->root = false;
        [$rc, , $err] = $this->cliRun(['set-startup', 'p1']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('sudo', $err);
        $this->assertFileDoesNotExist($this->dir . '/units/llmchooser-p1.service');
    }

    public function test_set_startup_without_systemd(): void
    {
        $this->runner->hasSystemctl = false;
        [$rc, , $err] = $this->cliRun(['set-startup', 'p1']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('systemd not found', $err);
    }

    public function test_set_startup_unknown_preset(): void
    {
        [$rc, , $err] = $this->cliRun(['set-startup', 'ghost']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('Preset not found', $err);
        $this->assertSame([], $this->runner->calls, 'must not touch systemctl');
    }

    public function test_remove_startup(): void
    {
        [$rc] = $this->cliRun(['set-startup', 'p1']);
        $this->assertSame(0, $rc);
        $this->assertFileExists($this->dir . '/units/llmchooser-p1.service');

        $this->runner->calls = [];
        [$rc2, $out2] = $this->cliRun(['remove-startup', 'p1']);
        $this->assertSame(0, $rc2);
        $this->assertStringContainsString('Removed llmchooser-p1.service', $out2);
        $this->assertFileDoesNotExist($this->dir . '/units/llmchooser-p1.service');

        $this->assertContains(['systemctl', 'disable', '--now', 'llmchooser-p1.service'], $this->runner->calls);
        $this->assertContains(['systemctl', 'daemon-reload'], $this->runner->calls);
    }

    public function test_remove_startup_not_installed(): void
    {
        [$rc, $out] = $this->cliRun(['remove-startup', 'p2']);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('Not installed', $out);
    }

    public function test_broken_preset_is_reported(): void
    {
        file_put_contents($this->dir . '/storage/presets/bad.json', '{"id":"bad",,}');

        // list: still succeeds and still shows the readable presets.
        [$rc, $out, $err] = $this->cliRun(['list']);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('p1', $out);
        $this->assertStringContainsString('p2', $out);
        $this->assertStringContainsString('ERROR: Config file has malformed JSON', $err);
        $this->assertStringContainsString('bad.json', $err);

        // health: fails so a scripted check notices.
        [$rc2, , $err2] = $this->cliRun(['health']);
        $this->assertSame(1, $rc2);
        $this->assertStringContainsString('bad.json', $err2);
    }

    public function test_instance_without_allocation_does_not_crash(): void
    {
        // A hand-written or pre-existing state row may have no allocation.
        $this->app->state->update(static function ($c): void {
            $c['manual'] = new Instance(['preset_id' => 'manual', 'pid' => 1, 'port' => 5879, 'status' => Instance::READY]);
        });

        [$rc, $out] = $this->cliRun(['health', 'manual']);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('estimate: -', $out);

        [$rc2, $out2] = $this->cliRun(['health']);
        $this->assertStringContainsString('manual', $out2);
    }

    // ---- Phase 8: enable / disable ----

    public function test_disable_a_stopped_preset(): void
    {
        [$rc, $out] = $this->cliRun(['disable', 'p1']);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('Disabled p1', $out);
        $this->assertFalse($this->app->presets->load('p1')->enabled);
    }

    public function test_enable_a_disabled_preset(): void
    {
        $this->cliRun(['disable', 'p1']);
        [$rc, $out] = $this->cliRun(['enable', 'p1']);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('Enabled p1', $out);
        $this->assertTrue($this->app->presets->load('p1')->enabled);
    }

    public function test_disable_a_running_preset_stops_it_first(): void
    {
        [$rc] = $this->cliRun(['start', 'p1', '--wait']);
        $this->assertSame(0, $rc);

        [$rc2, $out2] = $this->cliRun(['disable', 'p1']);
        $this->assertSame(0, $rc2);
        $this->assertStringContainsString('Stopped p1 (was running)', $out2);
        $this->assertStringContainsString('Disabled p1', $out2);
        $this->assertFalse($this->app->presets->load('p1')->enabled);

        $i = $this->app->processes->refresh()['p1'] ?? null;
        $this->assertTrue($i === null || $i->status === Instance::STOPPED);
    }

    public function test_disable_unknown_preset_fails(): void
    {
        [$rc, , $err] = $this->cliRun(['disable', 'ghost']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('Preset not found', $err);
    }

    public function test_list_shows_enabled_column(): void
    {
        [$rc, $out] = $this->cliRun(['list']);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('ENABLED', $out);
        $this->assertMatchesRegularExpression('/p1\s+\S*\s+yes/', $out);
    }

    public function test_list_enabled_filters(): void
    {
        $this->cliRun(['disable', 'p1']);
        rewind($this->o);
        ftruncate($this->o, 0); // start the list assertion from a clean stream
        [$rc, $out] = $this->cliRun(['list', '--enabled']);
        $this->assertSame(0, $rc);
        $this->assertStringNotContainsString('p1', $out);
        $this->assertStringContainsString('p2', $out);
    }

    public function test_set_startup_refuses_disabled_preset(): void
    {
        $this->cliRun(['disable', 'p1']);
        rewind($this->e);
        ftruncate($this->e, 0);
        [$rc, , $err] = $this->cliRun(['set-startup', 'p1']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('disabled', $err);
        $this->assertSame([], $this->runner->calls, 'must not touch systemctl');
        $this->assertFileDoesNotExist($this->dir . '/units/llmchooser-p1.service');
    }

    // ---- Phase 9: help-drift ----

    /** @return array{0:int,1:string,2:string} */
    private function driftRun(string $capturedPath, array $args): array
    {
        $cli = new Cli($this->app, new Output($this->o, $this->e), null, $capturedPath);
        $rc  = $cli->run($args);
        return [$rc, $this->out(), $this->err()];
    }

    private function fakeHelpBinary(string $output): string
    {
        $bin = $this->dir . '/fake-llama-help.sh';
        file_put_contents($bin, "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($output) . "\n");
        chmod($bin, 0755);
        return $bin;
    }

    public function test_help_drift_no_change(): void
    {
        $cap = $this->dir . '/captured.txt';
        file_put_contents($cap, 'flags: --ctx-size --parallel --seed');
        $bin = $this->fakeHelpBinary('usage: --ctx-size ... --parallel ... --seed');
        [$rc, $out] = $this->driftRun($cap, ['help-drift', $bin]);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('No drift', $out);
    }

    public function test_help_drift_detects_added_flag(): void
    {
        $cap = $this->dir . '/captured.txt';
        file_put_contents($cap, '--ctx-size');
        $bin = $this->fakeHelpBinary('--ctx-size --new-flag');
        [$rc, $out] = $this->driftRun($cap, ['help-drift', $bin]);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('+ added:   --new-flag', $out);
    }

    public function test_help_drift_detects_removed_flag(): void
    {
        $cap = $this->dir . '/captured.txt';
        file_put_contents($cap, '--ctx-size --old-flag');
        $bin = $this->fakeHelpBinary('--ctx-size');
        [$rc, $out] = $this->driftRun($cap, ['help-drift', $bin]);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('- removed: --old-flag', $out);
    }

    public function test_help_drift_missing_binary_path(): void
    {
        [$rc, , $err] = $this->driftRun($this->dir . '/captured-missing-anyway.txt', ['help-drift']);
        $this->assertSame(2, $rc);
        $this->assertStringContainsString('Missing path to llama-server binary', $err);
    }

    public function test_help_drift_non_executable_path(): void
    {
        $notABin = $this->dir . '/plain.txt';
        file_put_contents($notABin, 'hello');
        [$rc, , $err] = $this->driftRun($this->dir . '/captured.txt', ['help-drift', $notABin]);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('Not executable', $err);
    }
}
