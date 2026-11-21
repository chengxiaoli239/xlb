<?php

namespace App\Services;

final readonly class NodeHealthProbeAssessment
{
    public function __construct(
        public NodeHealthProbeResult $result,
        public NodeHealthTransition $transition,
    ) {}
}
