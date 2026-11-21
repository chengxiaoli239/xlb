<?php

namespace App\Services;

use InvalidArgumentException;

final class NodeHealthProbeCoordinator
{
    public function __construct(
        private readonly NodeHealthProbeDriver $driver,
        private readonly NodeHealthCircuitBreaker $circuitBreaker,
        private readonly NodeHealthStateStore $stateStore,
    ) {}

    public function probe(string $nodeId): NodeHealthProbeAssessment
    {
        if (trim($nodeId) === '') {
            throw new InvalidArgumentException('Node ID is required for health probing.');
        }

        $result = $this->driver->probe($nodeId);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $storedState = $this->stateStore->get($nodeId);
            $currentState = $storedState ?? new NodeHealthState('healthy', 0, 0);
            $transition = $this->circuitBreaker->record($currentState, $result->succeeded, $result->observedAt);
            if ($transition->ignoredAsStale || $this->stateStore->compareAndSwap($nodeId, $storedState, $transition->state)) {
                return new NodeHealthProbeAssessment($result, $transition);
            }
        }

        throw new \RuntimeException('Node health state could not be updated after concurrent changes.');
    }
}
