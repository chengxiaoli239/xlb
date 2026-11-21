<?php

namespace App\Services;

final readonly class NodeHealthTransition
{
    public function __construct(
        public NodeHealthState $state,
        public bool $openedCircuit,
        public bool $recoveredCircuit,
        public bool $ignoredAsStale,
    ) {}
}
