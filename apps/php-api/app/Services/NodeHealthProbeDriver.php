<?php

namespace App\Services;

interface NodeHealthProbeDriver
{
    public function probe(string $nodeId): NodeHealthProbeResult;
}
