<?php

declare(strict_types=1);

namespace LlmChooser\Tests\Process;

use LlmChooser\Engine\Engine;
use LlmChooser\Engine\EngineRepository;
use LlmChooser\Memory\Device;
use LlmChooser\Memory\FixedTopologyProbe;
use LlmChooser\Memory\MemoryService;
use LlmChooser\Memory\MemoryTopology;
use LlmChooser\Preset\Preset;
use LlmChooser\Preset\PresetRepository;
use LlmChooser\Process\AdmissionException;
use LlmChooser\Process\Instance;
use LlmChooser\Process\ProcessControl;
use LlmChooser\Process\ProcessManager;
use LlmChooser\Process\RuntimeState;
use LlmChooser\Tests\Fixtures\FakeServer;
use LlmChooser\Tests\Fixtures\ScriptedTopologyProbe;
use LlmChooser\Tests\Model\GgufFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Starts REAL processes (the fake llama-server). Every started process is
 * stopped in tearDown(), even when the test fails midway.
 */
class ProcessManagerTest extends TestCase
{
    private string $dir;
    private PresetRepository $presets;
    private EngineRepository $engines;
    private RuntimeState $state;
    private ProcessManager $pm;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llmchooser-pm-' . uniqid('', true);

        $bin = FakeServer::makeBinary($this->dir . '/bin');
        GgufFixture::model($this->dir . '/models/m.gguf', 'llama', 5000);

        $this->engines = new EngineRepository($this->dir . '/engines');
        $this->engines->save(new Engine([
            'id' => 'e',
            'host' => '127.0.0.1',
            'binary_path_default' => $bin,
            'port_range' => ['min' => 5850, 'max' => 5869],
        ]));

        $this->presets = new PresetRepository($this->dir . '/presets');
        $this->presets->save(new Preset([
            'id' => 'p1',
            'engine_id' => 'e',
            'model_path' => $this->dir . '/models/m.gguf',
        ]));

        $this->state = new RuntimeState($this->dir . '/state.json');
        $this->pm = $this->manager(100);
    }

    private function manager(float $availableGb): ProcessManager
    {
        $g = 1024 ** 3;
        $topo = new MemoryTopology([
            new Device('cpu', Device::KIND_RAM, 100 * $g, (int)($availableGb * $g)),
            new Device('gpu0', Device::KIND_VRAM, $g / 2, (int)($availableGb * $g / 2), 'cpu'),
        ]);
        return new ProcessManager(
            $this->presets,
            $this->engines,
            $this->state,
            new MemoryService(new FixedTopologyProbe($topo)),
            $this->dir . '/logs',
            stopTimeoutSeconds: 2,
        );
    }

    protected function tearDown(): void
    {
        // setUp() may have failed partway, so nothing here may assume the
        // services exist — but if a process is running it MUST be stopped.
        if (isset($this->state, $this->pm)) {
            foreach ($this->state->all() as $id => $instance) {
                $this->pm->stop((string)$id);
            }
        }
        putenv('FAKE_LOAD_MS');
        putenv('FAKE_EXIT_AFTER_MS');
        putenv('FAKE_IGNORE_TERM');
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
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->rmrf($path . '/' . $e);
            }
        }
        rmdir($path);
    }

    public function test_start_wait_stop(): void
    {
        $i = $this->pm->start('p1');
        $this->assertSame(Instance::LOADING, $i->status);
        $this->assertGreaterThan(0, $i->pid);
        $this->assertSame(5850, $i->port);
        $this->assertFileExists($i->log_file);
        $this->assertStringStartsWith('# ', (string)file_get_contents($i->log_file));

        $ready = $this->pm->waitUntilReady('p1', 10);
        $this->assertSame(Instance::READY, $ready->status);

        $pid = $i->pid;
        $this->pm->stop('p1');

        $this->assertNull($this->state->get('p1'));
        // Give the OS a beat to tear the process down.
        usleep(300000);
        $this->assertFalse((new ProcessControl())->isAlive($pid));
    }

    public function test_start_twice_is_noop(): void
    {
        $a = $this->pm->start('p1');
        $b = $this->pm->start('p1');
        $this->assertSame($a->pid, $b->pid);
    }

    public function test_crash_becomes_error(): void
    {
        putenv('FAKE_EXIT_AFTER_MS=200');
        $this->pm->start('p1');

        $i = $this->pm->waitUntilReady('p1', 10);
        $this->assertSame(Instance::ERROR, $i->status);
        $this->assertStringContainsString('fake crash', $i->message);
    }

    public function test_sigkill_fallback(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('no pcntl');
        }
        putenv('FAKE_IGNORE_TERM=1');
        $i = $this->pm->start('p1');
        $this->pm->waitUntilReady('p1', 10);

        $t0  = microtime(true);
        $this->pm->stop('p1');
        // TERM is ignored, so stop() must wait out its 2 s timeout, then KILL.
        $elapsed = microtime(true) - $t0;
        $this->assertGreaterThanOrEqual(1.5, $elapsed);
        $this->assertLessThan(10, $elapsed);
        usleep(200000);
        $this->assertFalse((new ProcessControl())->isAlive($i->pid));
    }

    public function test_reconcile_marks_dead_process(): void
    {
        $i = $this->pm->start('p1');
        $this->pm->waitUntilReady('p1', 10);

        // Kill it behind the manager's back, as an OOM kill or a reboot would.
        (new ProcessControl())->signal($i->pid, 'KILL');
        usleep(300000);

        $this->pm->reconcile();
        $this->assertSame(Instance::ERROR, $this->state->get('p1')->status);
    }

    public function test_pid_reuse_protection(): void
    {
        // This very PHPUnit process recorded as a "server" on a port it does
        // not serve. A PID-only check would report it alive; the --port check
        // in isOurs() must reject it, so reconcile errors it and stop() must
        // NOT signal it. If this test survives to its end, we did not kill
        // ourselves.
        $mypid = getmypid();
        $this->state->update(static function ($c) use ($mypid): void {
            $c['ghost'] = new Instance([
                'preset_id' => 'ghost',
                'pid'       => $mypid,
                'port'      => 5869,
                'status'    => Instance::READY,
            ]);
        });

        $this->pm->reconcile();
        $this->assertSame(Instance::ERROR, $this->state->get('ghost')->status);

        $this->pm->stop('ghost');
        $this->assertTrue((new ProcessControl())->isAlive($mypid), 'test process must still be alive');
    }

    public function test_admission_refused_and_forced(): void
    {
        $tiny = $this->manager(0.001); // 1 MB available
        try {
            $tiny->start('p1');
            $this->fail('expected AdmissionException');
        } catch (AdmissionException $e) {
            $this->assertStringContainsString('Not enough memory', $e->getMessage());
        }

        // force skips admission but still records the allocation.
        $i = $tiny->start('p1', force: true);
        $this->assertGreaterThan(0, $i->pid);
        $this->assertArrayHasKey('weights', $i->allocation['breakdown']);
    }

    public function test_port_busy(): void
    {
        $busy = stream_socket_server('tcp://127.0.0.1:5850', $errno, $errstr);
        $this->assertNotFalse($busy, 'could not occupy 5850 for the test');

        try {
            $this->presets->save(new Preset([
                'id' => 'p1',
                'engine_id' => 'e',
                'model_path' => $this->dir . '/models/m.gguf',
                'assigned_port' => 5850,
            ]));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('5850');
            $this->pm->start('p1');
        } finally {
            fclose($busy);
        }
    }

    public function test_start_refuses_disabled_preset(): void
    {
        $this->presets->setEnabled('p1', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/disabled/i');
        $this->pm->start('p1');
    }

    /** A ProcessControl with a canned process list that records signals. */
    private function stubProc(array $list, array &$signals): ProcessControl
    {
        return new class($list, $signals) extends ProcessControl {
            public function __construct(private array $list, private array &$signals) {}
            public function listProcesses(): array { return $this->list; }
            public function isAlive(int $pid): bool { return false; }
            public function signal(int $pid, string $signal): void { $this->signals[] = "$signal:$pid"; }
        };
    }

    public function test_orphans_only_lists_our_binaries_and_untracked_pids(): void
    {
        $bin = $this->engines->load('e')->binary_path_default;
        $signals = [];
        $proc = $this->stubProc([
            ['pid' => 111, 'rss' => 1024, 'args' => "$bin --port 5851 --model /m/big.gguf"],
            ['pid' => 222, 'rss' => 1024, 'args' => "/usr/bin/php $bin --port 5852 --model /m/b.gguf"],
            ['pid' => 333, 'rss' => 1024, 'args' => '/other/llama-server --port 9999'],
            ['pid' => 444, 'rss' => 1024, 'args' => "$bin --port 5853"],
        ], $signals);
        // 444 is tracked in state, so it is not an orphan.
        $this->state->update(function ($c): void {
            $c['t'] = new Instance(['preset_id' => 't', 'pid' => 444, 'port' => 5853, 'status' => Instance::READY]);
        });
        $pm = $this->managerWith($proc);

        $o = $pm->orphans();
        $this->assertSame([111, 222], array_column($o, 'pid'));
        $this->assertSame('5851', $o[0]['port']);
        $this->assertSame('big.gguf', $o[0]['model']);

        $this->assertSame(1, $pm->killOrphans(222));
        $this->assertSame(['TERM:222'], $signals);
        $signals = [];
        $this->assertSame(2, $pm->killOrphans());
        $this->assertSame(['TERM:111', 'TERM:222'], $signals);
        // A pid that is not an orphan is never signalled.
        $signals = [];
        $this->assertSame(0, $pm->killOrphans(444));
        $this->assertSame([], $signals);
    }

    private function managerWith(ProcessControl $proc): ProcessManager
    {
        $g = 1024 ** 3;
        $topo = new MemoryTopology([
            new Device('cpu', Device::KIND_RAM, 100 * $g, 100 * $g),
            new Device('gpu0', Device::KIND_VRAM, $g / 2, 50 * $g, 'cpu'),
        ]);
        return new ProcessManager(
            $this->presets, $this->engines, $this->state,
            new MemoryService(new FixedTopologyProbe($topo)),
            $this->dir . '/logs',
            proc: $proc,
            stopTimeoutSeconds: 2,
        );
    }

    private function topo(float $availableGb): MemoryTopology
    {
        $g = 1024 ** 3;
        return new MemoryTopology([
            new Device('cpu', Device::KIND_RAM, 100 * $g, (int)($availableGb * $g)),
            new Device('gpu0', Device::KIND_VRAM, $g / 2, (int)($availableGb * $g / 2), 'cpu'),
        ]);
    }

    private function scriptedManager(array $topos, int $releaseTimeout): ProcessManager
    {
        return new ProcessManager(
            $this->presets, $this->engines, $this->state,
            new MemoryService(new ScriptedTopologyProbe($topos)),
            $this->dir . '/logs',
            stopTimeoutSeconds: 2,
            releaseTimeoutSeconds: $releaseTimeout,
        );
    }

    public function test_restart_waits_for_driver_release(): void
    {
        // The first two admission checks still see the dying instance's
        // memory as held; the third sees it released and the restart fits.
        $this->pm->start('p1');
        $this->pm->waitUntilReady('p1', 10);

        $pm = $this->scriptedManager([$this->topo(0.001), $this->topo(0.001), $this->topo(100)], 5);

        $t0 = microtime(true);
        $i = $pm->restart('p1');
        $elapsed = microtime(true) - $t0;

        $this->assertGreaterThan(0, $i->pid);
        $this->assertSame(Instance::LOADING, $i->status);
        $this->assertGreaterThanOrEqual(1.9, $elapsed, 'must wait through two refusals');
        $this->assertLessThan(8, $elapsed);
    }

    public function test_restart_gives_up_after_release_timeout(): void
    {
        $this->pm->start('p1');
        $this->pm->waitUntilReady('p1', 10);

        $pm = $this->scriptedManager([$this->topo(0.001)], 2);

        $t0 = microtime(true);
        try {
            $pm->restart('p1');
            $this->fail('expected AdmissionException');
        } catch (AdmissionException $e) {
            $this->assertStringContainsString('Not enough memory', $e->getMessage());
        }
        $this->assertGreaterThanOrEqual(1.9, microtime(true) - $t0, 'must use the release window');
        $this->assertLessThan(6, microtime(true) - $t0);
        $this->assertNull($this->state->get('p1'), 'old instance stays stopped');
    }

    public function test_start_evicting_waits_for_release_before_next_victim(): void
    {
        // p2 does not fit while p1 runs. After evicting p1 the first check
        // is still stale; the retry inside the release window succeeds, so
        // exactly one victim is evicted.
        $this->pm->start('p1');
        $this->pm->waitUntilReady('p1', 10);
        $this->presets->save(new Preset([
            'id' => 'p2',
            'engine_id' => 'e',
            'model_path' => $this->dir . '/models/m.gguf',
        ]));

        $pm = $this->scriptedManager([$this->topo(0.001), $this->topo(0.001), $this->topo(100)], 5);

        $t0 = microtime(true);
        $evicted = $pm->startEvicting('p2', $started);
        $elapsed = microtime(true) - $t0;

        $this->assertSame(['p1'], $evicted);
        $this->assertNotNull($started);
        $this->assertGreaterThan(0, $started->pid);
        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'must wait after eviction before retrying');
    }
}
