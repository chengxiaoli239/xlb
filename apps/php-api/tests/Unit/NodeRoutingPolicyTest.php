<?php

namespace Tests\Unit;

use App\Services\NodeHealthState;
use App\Services\NodeRoutingPolicy;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NodeRoutingPolicyTest extends TestCase
{
    public function test_stale_unhealthy_unavailable_and_seventy_percent_loaded_nodes_are_excluded(): void
    {
        $now = new DateTimeImmutable('2026-11-21T10:00:00Z');
        $nodes = [
            $this->node('healthy', lastHealth: '2026-11-21T09:59:45Z'),
            $this->node('stale', lastHealth: '2026-11-21T09:59:29Z'),
            $this->node('unhealthy', health: 'unhealthy'),
            $this->node('unavailable', status: 'retired'),
            $this->node('loaded', active: 70),
        ];

        $ranked = (new NodeRoutingPolicy)->rankCandidates($nodes, 'member-device-1', $now, ['healthy']);

        $this->assertSame(['healthy'], array_map(static fn ($candidate): string => $candidate->nodeId, $ranked));
    }

    public function test_weighted_rendezvous_order_is_stable_and_quality_changes_effective_weight(): void
    {
        $now = new DateTimeImmutable('2026-11-21T10:00:00Z');
        $nodes = [
            $this->node('fast', weight: 100, latency: 30, loss: 0.1, cpu: 20),
            $this->node('slow', weight: 100, latency: 180, loss: 2.0, cpu: 65),
        ];
        $policy = new NodeRoutingPolicy;

        $first = $policy->rankCandidates($nodes, 'stable-session-key', $now, ['healthy']);
        $second = $policy->rankCandidates($nodes, 'stable-session-key', $now, ['healthy']);

        $this->assertSame(array_map(static fn ($candidate): string => $candidate->nodeId, $first), array_map(static fn ($candidate): string => $candidate->nodeId, $second));
        $effectiveWeights = [];
        foreach ($first as $candidate) {
            $effectiveWeights[$candidate->nodeId] = $candidate->effectiveWeight;
        }
        $this->assertGreaterThan($effectiveWeights['slow'], $effectiveWeights['fast']);
    }

    public function test_invalid_health_window_and_load_threshold_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NodeRoutingPolicy)->rankCandidates([$this->node('node-1')], 'session', new DateTimeImmutable('2026-11-21T10:00:00Z'), ['healthy'], 30, 1.2);
    }

    public function test_open_circuit_and_missing_or_stale_probe_evidence_are_excluded(): void
    {
        $now = new DateTimeImmutable('2026-11-21T10:00:00Z');
        $nodes = [
            $this->node('open'),
            $this->node('unobserved'),
            $this->node('stale-probe'),
            $this->node('healthy-probe'),
        ];
        $states = [
            'open' => new NodeHealthState('unhealthy', 3, 0, $now->modify('-1 second')),
            'unobserved' => new NodeHealthState('healthy', 0, 0),
            'stale-probe' => new NodeHealthState('healthy', 0, 0, $now->modify('-31 seconds')),
            'healthy-probe' => new NodeHealthState('healthy', 0, 0, $now->modify('-15 seconds')),
        ];

        $ranked = (new NodeRoutingPolicy)->rankCandidates($nodes, 'device-1', $now, ['healthy'], 30, 0.7, $states);

        $this->assertSame(['healthy-probe'], array_map(static fn ($candidate): string => $candidate->nodeId, $ranked));
    }

    public function test_invalid_circuit_state_map_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NodeRoutingPolicy)->rankCandidates(
            [$this->node('node-1')],
            'device-1',
            new DateTimeImmutable('2026-11-21T10:00:00Z'),
            ['healthy'],
            30,
            0.7,
            ['node-1' => 'healthy'],
        );
    }

    public function test_weighted_rendezvous_distribution_tracks_configured_one_to_three_weights(): void
    {
        $now = new DateTimeImmutable('2026-11-21T10:00:00Z');
        $nodes = [
            $this->node('one', weight: 1, latency: 0, loss: 0, cpu: 0),
            $this->node('three', weight: 3, latency: 0, loss: 0, cpu: 0),
        ];
        $policy = new NodeRoutingPolicy;
        $counts = ['one' => 0, 'three' => 0];
        for ($index = 0; $index < 4096; $index++) {
            $winner = $policy->rankCandidates($nodes, 'affinity-'.$index, $now, ['healthy'])[0];
            $counts[$winner->nodeId]++;
        }

        $this->assertEqualsWithDelta(0.75, $counts['three'] / 4096, 0.02);
    }

    /** @return array{id:string,status:string,health_status:string,last_health_check_at:string,max_sessions:int,active_sessions:int,weight:int,latency_ms:int,packet_loss_pct:float,cpu_pct:float} */
    private function node(
        string $id,
        string $status = 'available',
        string $health = 'healthy',
        string $lastHealth = '2026-11-21T09:59:50Z',
        int $max = 100,
        int $active = 10,
        int $weight = 100,
        int $latency = 50,
        float $loss = 0.5,
        float $cpu = 35,
    ): array {
        return [
            'id' => $id,
            'status' => $status,
            'health_status' => $health,
            'last_health_check_at' => $lastHealth,
            'max_sessions' => $max,
            'active_sessions' => $active,
            'weight' => $weight,
            'latency_ms' => $latency,
            'packet_loss_pct' => $loss,
            'cpu_pct' => $cpu,
        ];
    }
}
