<?php

namespace App\Services;

class HttpNodePeerProvisioningDriver implements NodePeerProvisioningDriver
{
    public function __construct(private NodeAccessAgentHttpClient $client) {}

    public function provision(object $node, object $peer, string $requestId, int $generation, string $credential): array
    {
        return $this->client->post($node, 'ensure', [
            'node_id' => (string) $node->id,
            'peer_id' => (string) $peer->id,
            'entitlement_id' => (string) $peer->entitlement_id,
            'request_id' => $requestId,
            'generation' => $generation,
            'device_id' => (string) $peer->device_id,
            'device_name' => (string) $peer->device_name,
            'device_public_key' => (string) $peer->public_key,
            'device_key_fingerprint' => (string) $peer->device_key_fingerprint,
            'device_binding_generation' => (int) $peer->device_binding_generation,
            'peer_generation' => $generation,
            'device_generation' => (int) $peer->device_binding_generation,
            'expires_at' => (string) $peer->expires_at,
            'credential' => $credential,
        ]);
    }
}
