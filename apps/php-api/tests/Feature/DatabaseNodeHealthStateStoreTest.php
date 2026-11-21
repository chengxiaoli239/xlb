<?php

namespace Tests\Feature;

use App\Services\DatabaseNodeHealthStateStore;
use App\Services\NodeHealthCircuitBreaker;
use App\Services\NodeHealthProbeCoordinator;
use App\Services\NodeHealthProbeDriver;
use App\Services\NodeHealthProbeResult;
use App\Services\NodeHealthState;
use App\Services\NodeRoutingPolicy;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseNodeHealthStateStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_streak_survives_coordinator_recreation_and_opens_circuit(): void
    {
        $start = new DateTimeImmutable('2026-11-22T10:00:00Z');
        foreach ([0, 15, 30] as $index => $seconds) {
            $coordinator = $this->coordinator(false, $start->modify("+{$seconds} seconds"));
            $assessment = $coordinator->probe('node-1');

            $this->assertSame($index === 2 ? 'unhealthy' : 'healthy', $assessment->transition->state->status);
            $this->assertSame($index + 1, $assessment->transition->state->failureStreak);
        }

        $state = (new DatabaseNodeHealthStateStore)->get('node-1');
        $this->assertSame(3, $state?->failureStreak);
        $this->assertSame([], (new NodeRoutingPolicy)->rankCandidates(
            [$this->node()],
            'installation-1',
            $start->modify('+30 seconds'),
            ['healthy'],
            30,
            0.7,
            ['node-1' => $state],
        ));
    }

    public function test_recovery_streak_survives_coordinator_recreation_and_restores_candidate(): void
    {
        $start = new DateTimeImmutable('2026-11-22T10:00:00Z');
        foreach ([false, false, false, true, true, true] as $index => $succeeded) {
            $coordinator = $this->coordinator($succeeded, $start->modify('+'.($index * 15).' seconds'));
            $assessment = $coordinator->probe('node-1');
        }

        $this->assertTrue($assessment->transition->recoveredCircuit);
        $state = (new DatabaseNodeHealthStateStore)->get('node-1');
        $this->assertSame('healthy', $state?->status);
        $this->assertSame(['node-1'], array_map(
            static fn ($candidate): string => $candidate->nodeId,
            (new NodeRoutingPolicy)->rankCandidates(
                [$this->node('2026-11-22T10:01:15Z')],
                'installation-1',
                $start->modify('+75 seconds'),
                ['healthy'],
                30,
                0.7,
                ['node-1' => $state],
            ),
        ));
    }

    public function test_compare_and_swap_rejects_a_result_based_on_an_old_snapshot(): void
    {
        $store = new DatabaseNodeHealthStateStore;
        $observedAt = new DateTimeImmutable('2026-11-22T10:00:00Z');
        $old = new NodeHealthState('healthy', 1, 0, $observedAt);
        $new = new NodeHealthState('unhealthy', 3, 0, $observedAt->modify('+15 seconds'));
        $store->put('node-1', $old);
        $store->put('node-1', $new);

        $this->assertFalse($store->compareAndSwap(
            'node-1',
            $old,
            new NodeHealthState('healthy', 0, 0, $observedAt->modify('-15 seconds')),
        ));
        $this->assertEquals($new, $store->get('node-1'));
    }

    public function test_stale_persisted_probe_state_is_excluded_from_routing(): void
    {
        $observedAt = new DateTimeImmutable('2026-11-22T10:00:00Z');
        (new DatabaseNodeHealthStateStore)->put('node-1', new NodeHealthState('healthy', 0, 0, $observedAt));

        $ranked = (new NodeRoutingPolicy)->rankCandidates(
            [$this->node()],
            'installation-1',
            $observedAt->modify('+31 seconds'),
            ['healthy'],
            30,
            0.7,
            ['node-1' => (new DatabaseNodeHealthStateStore)->get('node-1')],
        );

        $this->assertSame([], $ranked);
    }

    private function coordinator(bool $succeeded, DateTimeImmutable $observedAt): NodeHealthProbeCoordinator
    {
        $driver = new class($succeeded, $observedAt) implements NodeHealthProbeDriver
        {
            public function __construct(private readonly bool $succeeded, private readonly DateTimeImmutable $observedAt) {}

            public function probe(string $nodeId): NodeHealthProbeResult
            {
                return $this->succeeded
                    ? new NodeHealthProbeResult(true, $this->observedAt, latencyMs: 30)
                    : new NodeHealthProbeResult(false, $this->observedAt, failureCode: 'timeout');
            }
        };

        return new NodeHealthProbeCoordinator($driver, new NodeHealthCircuitBreaker, new DatabaseNodeHealthStateStore);
    }

    /** @return array{id:string,status:string,health_status:string,last_health_check_at:string,max_sessions:int,active_sessions:int,weight:int,latency_ms:int,packet_loss_pct:float,cpu_pct:float} */
    private function node(string $lastHealthCheckAt = '2026-11-22T10:00:00Z'): array
    {
        return [
            'id' => 'node-1',
            'status' => 'available',
            'health_status' => 'healthy',
            'last_health_check_at' => $lastHealthCheckAt,
            'max_sessions' => 100,
            'active_sessions' => 1,
            'weight' => 100,
            'latency_ms' => 30,
            'packet_loss_pct' => 0,
            'cpu_pct' => 10,
        ];
    }
}
