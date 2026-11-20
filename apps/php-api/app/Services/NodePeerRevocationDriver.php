<?php

namespace App\Services;

interface NodePeerRevocationDriver
{
    /**
     * Remove the peer idempotently; requestId and generation stay stable across retries.
     *
     * @return array{
     *     node_id: string,
     *     peer_id: string,
     *     request_id: string,
     *     generation: int,
     *     acknowledgement_id: string,
     *     upload_bytes: int,
     *     download_bytes: int,
     *     confirmation_status: 'confirmed'|'confirmed_test'
     * }
     */
    public function revoke(object $node, object $peer, string $requestId, int $generation): array;
}
