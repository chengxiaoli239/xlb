<?php

namespace App\Services;

interface NodePeerRevocationDriver
{
    /**
     * Remove the peer idempotently; requestId stays stable across retries.
     *
     * @return array{
     *     acknowledgement_id: string,
     *     upload_bytes: int,
     *     download_bytes: int,
     *     confirmation_status: 'confirmed'|'confirmed_test'
     * }
     */
    public function revoke(object $node, object $peer, string $requestId): array;
}
