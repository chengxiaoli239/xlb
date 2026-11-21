<?php

namespace App\Services;

use InvalidArgumentException;

class NodeHealthProbeBatchRunner
{
    public function __construct(private readonly NodeHealthProbeCoordinator $coordinator) {}

    /**
     * @param  iterable<string>  $nodeIds
     * @return array<string, NodeHealthProbeAssessment>
     */
    public function run(iterable $nodeIds): array
    {
        $assessments = [];
        foreach ($nodeIds as $nodeId) {
            if (! is_string($nodeId) || trim($nodeId) === '') {
                throw new InvalidArgumentException('Node IDs must be non-empty strings.');
            }

            $nodeId = trim($nodeId);
            if (array_key_exists($nodeId, $assessments)) {
                continue;
            }

            $assessments[$nodeId] = $this->coordinator->probe($nodeId);
        }

        return $assessments;
    }
}
