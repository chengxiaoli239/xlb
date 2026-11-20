<?php

namespace App\Services;

class HttpNodePeerRevocationDriver implements NodePeerRevocationDriver
{
    public function __construct(private NodeAccessAgentHttpClient $client) {}

    public function revoke(object $node, object $peer, string $requestId, int $generation): array
    {
        return $this->client->post($node, 'revoke', [
            'node_id' => (string) $node->id,
            'peer_id' => (string) $peer->id,
            'request_id' => $requestId,
            'generation' => $generation,
        ]);
    }
}
