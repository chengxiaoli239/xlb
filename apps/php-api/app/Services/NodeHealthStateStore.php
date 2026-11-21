<?php

namespace App\Services;

interface NodeHealthStateStore
{
    public function get(string $nodeId): ?NodeHealthState;

    public function put(string $nodeId, NodeHealthState $state): void;
}
