<?php

namespace App\Services;

use InvalidArgumentException;

final class NodeHealthProbeCoordinator
{
    public function __construct(
        private readonly NodeHealthProbeDriver $driver,
        private readonly NodeHealthCircuitBreaker $circuitBreaker,
    ) {}

    public function probe(string $nodeId, NodeHealthState $currentState): NodeHealthProbeAssessment
    {
        if (trim($nodeId) === '') {
            throw new InvalidArgumentException('Node ID is required for health probing.');
        }

        $result = $this->driver->probe($nodeId);
        $transition = $this->circuitBreaker->record($currentState, $result->succeeded, $result->observedAt);

        return new NodeHealthProbeAssessment($result, $transition);
    }
}
