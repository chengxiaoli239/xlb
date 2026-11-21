<?php

namespace Tests\Unit;

use App\Services\NodeHealthCircuitBreaker;
use App\Services\NodeHealthProbeCoordinator;
use App\Services\NodeHealthProbeDriver;
use App\Services\NodeHealthProbeResult;
use App\Services\NodeHealthState;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NodeHealthProbeCoordinatorTest extends TestCase
{
    public function test_scripted_probe_failures_feed_the_circuit_breaker_and_open_it_at_threshold(): void
    {
        $observedAt = new DateTimeImmutable('2026-11-21T10:00:01Z');
        $driver = new class([new NodeHealthProbeResult(false, $observedAt, failureCode: 'timeout'), new NodeHealthProbeResult(false, $observedAt->modify('+15 seconds'), failureCode: 'timeout'), new NodeHealthProbeResult(false, $observedAt->modify('+30 seconds'), failureCode: 'handshake_failed')]) implements NodeHealthProbeDriver
        {
            /** @var list<NodeHealthProbeResult> */
            private array $results;

            /** @var list<string> */
            public array $probedNodeIds = [];

            /**
             * @param  list<NodeHealthProbeResult>  $results
             */
            public function __construct(array $results)
            {
                $this->results = $results;
            }

            public function probe(string $nodeId): NodeHealthProbeResult
            {
                $this->probedNodeIds[] = $nodeId;

                return array_shift($this->results) ?? throw new InvalidArgumentException('No scripted probe result remains.');
            }
        };
        $coordinator = new NodeHealthProbeCoordinator($driver, new NodeHealthCircuitBreaker);
        $state = new NodeHealthState('healthy', 0, 0);

        $first = $coordinator->probe('node-1', $state);
        $second = $coordinator->probe('node-1', $first->transition->state);
        $third = $coordinator->probe('node-1', $second->transition->state);

        $this->assertSame(['node-1', 'node-1', 'node-1'], $driver->probedNodeIds);
        $this->assertSame('timeout', $first->result->failureCode);
        $this->assertSame('healthy', $second->transition->state->status);
        $this->assertSame('unhealthy', $third->transition->state->status);
        $this->assertTrue($third->transition->openedCircuit);
        $this->assertEquals($observedAt->modify('+30 seconds'), $third->transition->state->lastProbeAt);
    }

    public function test_successful_probe_preserves_latency_and_observation_time(): void
    {
        $observedAt = new DateTimeImmutable('2026-11-21T10:00:15Z');
        $driver = new class($observedAt) implements NodeHealthProbeDriver
        {
            public function __construct(private readonly DateTimeImmutable $observedAt) {}

            public function probe(string $nodeId): NodeHealthProbeResult
            {
                return new NodeHealthProbeResult(true, $this->observedAt, latencyMs: 42, packetLossPct: 0.5);
            }
        };

        $assessment = (new NodeHealthProbeCoordinator($driver, new NodeHealthCircuitBreaker))
            ->probe('node-1', new NodeHealthState('healthy', 0, 0));

        $this->assertSame(42, $assessment->result->latencyMs);
        $this->assertSame(0.5, $assessment->result->packetLossPct);
        $this->assertSame($observedAt, $assessment->transition->state->lastProbeAt);
    }

    public function test_probe_sequence_requires_recovery_streak_after_open_circuit(): void
    {
        $start = new DateTimeImmutable('2026-11-21T10:00:01Z');
        $results = [];
        foreach ([false, false, false, true, true, false, true, true, true] as $index => $succeeded) {
            $observedAt = $start->modify('+'.($index * 15).' seconds');
            $results[] = $succeeded
                ? new NodeHealthProbeResult(true, $observedAt, latencyMs: 35)
                : new NodeHealthProbeResult(false, $observedAt, failureCode: 'timeout');
        }
        $driver = new class($results) implements NodeHealthProbeDriver
        {
            /** @var list<NodeHealthProbeResult> */
            private array $results;

            /**
             * @param  list<NodeHealthProbeResult>  $results
             */
            public function __construct(array $results)
            {
                $this->results = $results;
            }

            public function probe(string $nodeId): NodeHealthProbeResult
            {
                return array_shift($this->results) ?? throw new InvalidArgumentException('No scripted probe result remains.');
            }
        };
        $coordinator = new NodeHealthProbeCoordinator($driver, new NodeHealthCircuitBreaker);
        $state = new NodeHealthState('healthy', 0, 0);
        $transitions = [];
        foreach ($results as $_) {
            $assessment = $coordinator->probe('node-1', $state);
            $state = $assessment->transition->state;
            $transitions[] = $assessment->transition;
        }

        $this->assertSame('unhealthy', $transitions[2]->state->status);
        $this->assertSame(2, $transitions[4]->state->successStreak);
        $this->assertSame('unhealthy', $transitions[5]->state->status);
        $this->assertSame(0, $transitions[5]->state->successStreak);
        $this->assertSame('unhealthy', $transitions[7]->state->status);
        $this->assertSame('healthy', $transitions[8]->state->status);
        $this->assertTrue($transitions[8]->recoveredCircuit);
    }

    public function test_late_probe_result_does_not_overwrite_newer_circuit_state(): void
    {
        $observedAt = new DateTimeImmutable('2026-11-21T10:00:09Z');
        $driver = new class($observedAt) implements NodeHealthProbeDriver
        {
            public function __construct(private readonly DateTimeImmutable $observedAt) {}

            public function probe(string $nodeId): NodeHealthProbeResult
            {
                return new NodeHealthProbeResult(false, $this->observedAt, failureCode: 'timeout');
            }
        };
        $state = new NodeHealthState('unhealthy', 3, 1, $observedAt->modify('+1 second'));

        $assessment = (new NodeHealthProbeCoordinator($driver, new NodeHealthCircuitBreaker))->probe('node-1', $state);

        $this->assertTrue($assessment->transition->ignoredAsStale);
        $this->assertSame($state, $assessment->transition->state);
    }

    public function test_probe_result_rejects_invalid_metrics_and_outcomes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NodeHealthProbeResult(true, new DateTimeImmutable('2026-11-21T10:00:00Z'), latencyMs: -1);
    }
}
