<?php

namespace App\Services;

use RuntimeException;

class DisabledNodePeerSubscriptionBindingDriver implements NodePeerSubscriptionBindingDriver
{
    public function bind(object $node, object $peer, object $subscription, string $requestId, string $token): array
    {
        throw new RuntimeException('Node peer subscription binding is unavailable.');
    }
}
