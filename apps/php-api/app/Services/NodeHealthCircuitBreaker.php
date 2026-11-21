<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

final class NodeHealthCircuitBreaker
{
    public function __construct(
        private readonly int $failureThreshold = 3,
        private readonly int $recoveryThreshold = 3,
    ) {
        if ($failureThreshold < 1 || $recoveryThreshold < 1) {
            throw new InvalidArgumentException('Node health probe thresholds must be positive.');
        }
    }

    public function record(NodeHealthState $state, bool $probeSucceeded, DateTimeImmutable $observedAt): NodeHealthTransition
    {
        if ($state->lastProbeAt !== null && $observedAt <= $state->lastProbeAt) {
            return new NodeHealthTransition($state, false, false, true);
        }

        if ($state->status === 'healthy') {
            if ($probeSucceeded) {
                $next = new NodeHealthState('healthy', 0, 0, $observedAt);

                return new NodeHealthTransition($next, false, false, false);
            }

            $failureStreak = $state->failureStreak + 1;
            $opened = $failureStreak >= $this->failureThreshold;
            $next = new NodeHealthState($opened ? 'unhealthy' : 'healthy', $failureStreak, 0, $observedAt);

            return new NodeHealthTransition($next, $opened, false, false);
        }

        if (! $probeSucceeded) {
            $next = new NodeHealthState('unhealthy', max($state->failureStreak, $this->failureThreshold), 0, $observedAt);

            return new NodeHealthTransition($next, false, false, false);
        }

        $successStreak = $state->successStreak + 1;
        $recovered = $successStreak >= $this->recoveryThreshold;
        $next = $recovered
            ? new NodeHealthState('healthy', 0, 0, $observedAt)
            : new NodeHealthState('unhealthy', max($state->failureStreak, $this->failureThreshold), $successStreak, $observedAt);

        return new NodeHealthTransition($next, false, $recovered, false);
    }
}
