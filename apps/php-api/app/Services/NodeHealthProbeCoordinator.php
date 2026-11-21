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

        $currentState = $this->stateStore->get($nodeId) ?? new NodeHealthState('healthy', 0, 0);
        $result = $this->driver->probe($nodeId);
        $transition = $this->circuitBreaker->record($currentState, $result->succeeded, $result->observedAt);
        if (! $transition->ignoredAsStale) {
            $this->stateStore->put($nodeId, $transition->state);
        }

        return new NodeHealthProbeAssessment($result, $transition);
    }
}
