<?php

namespace App\Services;

use RuntimeException;

class DisabledNodePeerUsageDriver implements NodePeerUsageDriver
{
    public function usage(object $node, object $peer): array
    {
        throw new RuntimeException('Node peer usage reporting is unavailable.');
    }
}
