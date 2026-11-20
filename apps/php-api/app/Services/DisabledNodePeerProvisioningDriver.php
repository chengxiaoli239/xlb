<?php

namespace App\Services;

use RuntimeException;

class DisabledNodePeerProvisioningDriver implements NodePeerProvisioningDriver
{
    public function provision(object $node, object $peer, string $requestId, int $generation, string $credential): array
    {
        throw new RuntimeException('Node peer provisioning is unavailable.');
    }
}
