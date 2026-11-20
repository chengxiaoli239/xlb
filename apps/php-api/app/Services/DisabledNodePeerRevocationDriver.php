<?php

namespace App\Services;

use RuntimeException;

class DisabledNodePeerRevocationDriver implements NodePeerRevocationDriver
{
    public function revoke(object $node, object $peer, string $requestId, int $generation): array
    {
        throw new RuntimeException('No node revocation driver is configured.');
    }
}
