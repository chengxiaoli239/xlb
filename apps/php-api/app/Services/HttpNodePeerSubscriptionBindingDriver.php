<?php

namespace App\Services;

class HttpNodePeerSubscriptionBindingDriver implements NodePeerSubscriptionBindingDriver
{
    public function __construct(private NodeAccessAgentHttpClient $client) {}

    public function bind(object $node, object $peer, object $subscription, string $requestId, string $token): array
    {
        return $this->client->post($node, 'bind-subscription', [
            'node_id' => (string) $node->id,
            'peer_id' => (string) $peer->id,
            'request_id' => $requestId,
            'subscription_id' => (string) $subscription->id,
            'subscription_token' => $token,
            'subscription_generation' => (int) $subscription->credential_generation,
            'peer_generation' => (int) $peer->generation,
            'device_generation' => (int) $peer->device_binding_generation,
        ]);
    }
}
