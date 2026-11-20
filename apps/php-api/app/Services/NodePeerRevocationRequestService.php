<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NodePeerRevocationRequestService
{
    /**
     * Queue one stable request for a peer. Call from a transaction while the peer is locked.
     */
    public function request(object $peer, string $reason, ?string $refundEventId = null): ?object
    {
        if ($peer->status === 'revoked') {
            return null;
        }

        $existing = DB::table('node_access_revocation_outbox')->where('peer_id', $peer->id)->first();
        if ($existing) {
            return $existing;
        }

        $at = now()->utc()->toIso8601String();
        $generation = (int) $peer->generation + 1;
        $requestId = (string) Str::uuid();
        DB::table('node_access_revocation_outbox')->insertOrIgnore([
            'id' => $requestId,
            'peer_id' => $peer->id,
            'refund_event_id' => $refundEventId,
            'reason' => $reason,
            'generation' => $generation,
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $job = DB::table('node_access_revocation_outbox')->where('peer_id', $peer->id)->first();
        if ($job) {
            DB::table('node_access_peers')->where('id', $peer->id)->where('status', '!=', 'revoked')->update([
                'status' => 'revocation_pending',
                'generation' => (int) $job->generation,
                'updated_at' => $at,
            ]);
        }

        return $job;
    }
}
