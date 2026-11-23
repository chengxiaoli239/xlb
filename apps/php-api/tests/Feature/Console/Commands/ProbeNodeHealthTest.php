<?php

namespace Tests\Feature\Console\Commands;

use App\Services\NodeHealthProbeDriver;
use App\Services\NodeHealthProbeResult;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProbeNodeHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_command_is_disabled_without_an_explicitly_configured_driver(): void
    {
        config(['node_health_probe.enabled' => false]);

        $this->artisan('nodes:probe-health')
            ->expectsOutputToContain('节点健康探测未启用')
            ->assertExitCode(0);

        $this->assertDatabaseCount('node_health_probe_states', 0);
    }

    public function test_enabled_probe_command_fails_closed_when_no_driver_is_bound(): void
    {
        config(['node_health_probe.enabled' => true]);

        $this->artisan('nodes:probe-health')
            ->expectsOutputToContain('未配置探测驱动')
            ->assertExitCode(1);

        $this->assertDatabaseCount('node_health_probe_states', 0);
    }

    public function test_disabled_probe_is_not_registered_with_the_scheduler(): void
    {
        $this->assertFalse(config('node_health_probe.enabled'));
        $scheduledProbes = array_filter(
            Schedule::events(),
            static fn ($event): bool => str_contains((string) $event->command, 'nodes:probe-health'),
        );

        $this->assertSame([], array_values($scheduledProbes));
    }

    public function test_enabled_probe_with_http_driver_is_registered_with_the_scheduler(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'schedule:list'], base_path(), [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'file',
            'NODE_HEALTH_PROBE_ENABLED' => 'true',
            'NODE_HEALTH_PROBE_TRANSPORT' => 'http',
            'NODE_HEALTH_PROBE_ENDPOINTS' => '[]',
        ]);

        $exitCode = $process->run();

        $this->assertSame(0, $exitCode, $process->getErrorOutput());
        $this->assertStringContainsString('php artisan nodes:probe-health', $process->getOutput());
    }

    public function test_repeated_local_probe_runs_persist_circuit_state_without_changing_node_acceptance(): void
    {
        $this->seed();
        config(['node_health_probe.enabled' => true]);

        $start = new DateTimeImmutable('2026-11-22T10:00:00Z');
        $driver = new class($start) implements NodeHealthProbeDriver
        {
            public int $calls = 0;

            public function __construct(private readonly DateTimeImmutable $start) {}

            public function probe(string $nodeId): NodeHealthProbeResult
            {
                $observedAt = $this->start->modify('+'.($this->calls * 15).' seconds');
                $this->calls++;

                return new NodeHealthProbeResult(false, $observedAt, failureCode: 'synthetic_timeout');
            }
        };
        $this->app->instance(NodeHealthProbeDriver::class, $driver);
        $existingNodeHealthCheck = DB::table('nodes')->where('id', 'node-test-1')->value('last_health_check_at');

        foreach ([1, 2, 3] as $_) {
            $this->artisan('nodes:probe-health')
                ->expectsOutputToContain('不构成节点连接或会员隧道验收')
                ->assertExitCode(0);
        }

        $this->assertSame(3, $driver->calls);
        $this->assertDatabaseHas('node_health_probe_states', [
            'node_id' => 'node-test-1',
            'status' => 'unhealthy',
            'failure_streak' => 3,
        ]);
        $this->assertDatabaseHas('nodes', [
            'id' => 'node-test-1',
            'health_status' => 'verified_test',
        ]);
        $this->assertSame(
            $existingNodeHealthCheck,
            DB::table('nodes')->where('id', 'node-test-1')->value('last_health_check_at'),
        );
    }

    public function test_probe_batch_limit_and_explicit_node_filter_bound_driver_targets(): void
    {
        $this->seed();
        $secondNode = (array) DB::table('nodes')->where('id', 'node-test-1')->first();
        $secondNode['id'] = 'node-test-2';
        $secondNode['name'] = '演示节点 02';
        DB::table('nodes')->insert($secondNode);

        config(['node_health_probe.enabled' => true, 'node_health_probe.batch_size' => 1]);
        $driver = new class implements NodeHealthProbeDriver
        {
            /** @var list<string> */
            public array $nodeIds = [];

            public function probe(string $nodeId): NodeHealthProbeResult
            {
                $this->nodeIds[] = $nodeId;

                return new NodeHealthProbeResult(true, new DateTimeImmutable('2026-11-24T00:00:00Z'), latencyMs: 25);
            }
        };
        $this->app->instance(NodeHealthProbeDriver::class, $driver);

        $this->artisan('nodes:probe-health')
            ->expectsOutputToContain('不构成节点连接或会员隧道验收')
            ->assertExitCode(0);

        $this->assertSame(['node-test-1'], $driver->nodeIds);
        $this->assertDatabaseHas('node_health_probe_states', ['node_id' => 'node-test-1']);
        $this->assertDatabaseMissing('node_health_probe_states', ['node_id' => 'node-test-2']);

        $this->artisan('nodes:probe-health', ['--node' => ['node-test-2']])
            ->expectsOutputToContain('不构成节点连接或会员隧道验收')
            ->assertExitCode(0);

        $this->assertSame(['node-test-1', 'node-test-2'], $driver->nodeIds);
        $this->assertDatabaseHas('node_health_probe_states', ['node_id' => 'node-test-2']);
    }
}
