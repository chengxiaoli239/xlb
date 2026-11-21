<?php

namespace App\Services;

use InvalidArgumentException;

final class InMemoryNodeHealthStateStore implements NodeHealthStateStore
{
    /** @var array<string, NodeHealthState> */
    private array $states = [];

    public function get(string $nodeId): ?NodeHealthState
    {
        if (trim($nodeId) === '') {
            throw new InvalidArgumentException('Node ID is required to read health state.');
        }

        return $this->states[$nodeId] ?? null;
    }

    public function put(string $nodeId, NodeHealthState $state): void
    {
        if (trim($nodeId) === '') {
            throw new InvalidArgumentException('Node ID is required to store health state.');
        }

        $this->states[$nodeId] = $state;
    }
}
