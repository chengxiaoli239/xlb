<?php

namespace App\Services;

interface NodeHealthStateStore
{
    public function get(string $nodeId): ?NodeHealthState;

    public function put(string $nodeId, NodeHealthState $state): void;

    public function compareAndSwap(string $nodeId, ?NodeHealthState $expected, NodeHealthState $next): bool;
}
