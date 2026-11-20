<?php

namespace App\Services;

class SandboxNodePeerSubscriptionBindingDriver implements NodePeerSubscriptionBindingDriver
{
    public function bind(object $node, object $peer, object $subscription, string $requestId, string $token): array
    {
        return [
            'node_id' => (string) $node->id,
            'peer_id' => (string) $peer->id,
            'request_id' => $requestId,
            'subscription_id' => (string) $subscription->id,
            'generation' => (int) $subscription->credential_generation,
            'acknowledgement_id' => 'test-'.substr(hash('sha256', $requestId), 0, 40),
            'confirmation_status' => 'confirmed_test',
        ];
    }
}
