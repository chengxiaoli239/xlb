<?php

namespace App\Services;

use RuntimeException;

class SandboxNodePeerProvisioningDriver implements NodePeerProvisioningDriver
{
    public function provision(object $node, object $peer, string $requestId, int $generation, string $credential): array
    {
        if (! (bool) $node->is_test || ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Sandbox provisioning only accepts local test nodes.');
        }

        return [
            'node_id' => (string) $node->id,
            'peer_id' => (string) $peer->id,
            'request_id' => $requestId,
            'generation' => $generation,
            'acknowledgement_id' => 'sandbox-'.$requestId,
            'profile' => json_encode([
                'kind' => 'sandbox-only', 'connectable' => false,
                'peer_id' => (string) $peer->id, 'request_id' => $requestId, 'generation' => $generation,
            ], JSON_THROW_ON_ERROR),
            'confirmation_status' => 'confirmed_test',
        ];
    }
}
