<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NodePeerUsageAccountingService
{
    public function __construct(
        private NodePeerRevocationRequestService $revocations,
        private TrafficQuotaReservationService $trafficQuota,
    ) {}

    /**
     * Apply a monotonic node snapshot and reconcile it with final reservation receipts.
     *
     * @return array{changed:bool,delta_bytes:int,quota_exhausted:bool}
     */
    public function applySnapshot(string $peerId, int $uploadBytes, int $downloadBytes): array
    {
        if ($uploadBytes < 0 || $downloadBytes < 0 || $uploadBytes > PHP_INT_MAX - $downloadBytes) {
            throw new RuntimeException('Node usage counters are outside the supported range.');
        }

        return DB::transaction(function () use ($peerId, $uploadBytes, $downloadBytes): array {
            $peer = DB::table('node_access_peers')->where('id', $peerId)->lockForUpdate()->first();
            if (! $peer) {
                throw new RuntimeException('Node usage peer is missing.');
            }

            $result = $this->trafficQuota->recordUsageSnapshotWithinTransaction($peer, $uploadBytes, $downloadBytes);
            if ($result['quota_exhausted'] && $peer->entitlement_id !== null) {
                $peers = DB::table('node_access_peers')->where('entitlement_id', $peer->entitlement_id)
                    ->whereIn('status', ['active', 'provisioning'])->orderBy('id')->lockForUpdate()->get();
                foreach ($peers as $entitledPeer) {
                    $this->revocations->request($entitledPeer, 'traffic_limit_reached');
                }
            }

            return $result;
        }, 10);
    }
}
