<?php

namespace App\Services;

use RuntimeException;

class SandboxNodePeerRevocationDriver implements NodePeerRevocationDriver
{
    public function revoke(object $node, object $peer, string $requestId): array
    {
        if (! (bool) $node->is_test) {
            throw new RuntimeException('Sandbox revocation only accepts test nodes.');
        }

        return [
            'acknowledgement_id' => 'sandbox-'.$requestId,
            'upload_bytes' => (int) $peer->upload_bytes,
            'download_bytes' => (int) $peer->download_bytes,
            'confirmation_status' => 'confirmed_test',
        ];
    }
}
