<?php

namespace Tests\Feature\Console\Commands;

use App\Services\NodeHealthProbeDriver;
use App\Services\NodeHealthProbeResult;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
