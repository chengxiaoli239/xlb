<?php

namespace Tests\Unit;

use App\Services\NodeHealthCircuitBreaker;
use App\Services\NodeHealthState;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NodeHealthCircuitBreakerTest extends TestCase
{
    public function test_three_consecutive_failures_open_the_circuit(): void
    {
        $breaker = new NodeHealthCircuitBreaker;
        $state = new NodeHealthState('healthy', 0, 0);

        $first = $breaker->record($state, false, $this->time(1));
        $second = $breaker->record($first->state, false, $this->time(2));
        $third = $breaker->record($second->state, false, $this->time(3));

        $this->assertSame('healthy', $first->state->status);
        $this->assertSame(1, $first->state->failureStreak);
        $this->assertSame('healthy', $second->state->status);
        $this->assertSame('unhealthy', $third->state->status);
        $this->assertTrue($third->openedCircuit);
    }

    public function test_circuit_requires_three_consecutive_successes_to_recover(): void
    {
        $breaker = new NodeHealthCircuitBreaker;
        $state = new NodeHealthState('unhealthy', 3, 0);

        $first = $breaker->record($state, true, $this->time(1));
        $interrupted = $breaker->record($first->state, false, $this->time(2));
        $second = $breaker->record($interrupted->state, true, $this->time(3));
        $third = $breaker->record($second->state, true, $this->time(4));
        $fourth = $breaker->record($third->state, true, $this->time(5));

        $this->assertSame('unhealthy', $first->state->status);
        $this->assertSame(1, $first->state->successStreak);
        $this->assertSame(0, $interrupted->state->successStreak);
        $this->assertSame('unhealthy', $third->state->status);
        $this->assertSame('healthy', $fourth->state->status);
        $this->assertTrue($fourth->recoveredCircuit);
    }

    public function test_out_of_order_probe_results_are_ignored(): void
    {
        $breaker = new NodeHealthCircuitBreaker;
        $state = new NodeHealthState('healthy', 1, 0, $this->time(10));
        $transition = $breaker->record($state, false, $this->time(9));

        $this->assertTrue($transition->ignoredAsStale);
        $this->assertSame($state, $transition->state);
    }

    public function test_invalid_thresholds_and_state_are_rejected(): void
    {
        $breaker = new NodeHealthCircuitBreaker;
        $this->expectException(InvalidArgumentException::class);
        $breaker->record(new NodeHealthState('degraded', 0, 0), false, $this->time(1));
    }

    private function time(int $second): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-21T10:00:'.str_pad((string) $second, 2, '0', STR_PAD_LEFT).'Z');
    }
}
