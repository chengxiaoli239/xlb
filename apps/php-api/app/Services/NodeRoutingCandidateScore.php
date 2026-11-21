<?php

namespace App\Services;

final readonly class NodeRoutingCandidateScore
{
    public function __construct(
        public string $nodeId,
        public float $effectiveWeight,
        public float $score,
    ) {}
}
