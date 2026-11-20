<?php

namespace App\Services;

interface NodePeerSubscriptionBindingDriver
{
    /** @return array<string, mixed> */
    public function bind(object $node, object $peer, object $subscription, string $requestId, string $token): array;
}
