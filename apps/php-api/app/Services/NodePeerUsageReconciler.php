<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class NodePeerUsageReconciler
{
    public function __construct(private NodePeerUsageDriver $driver, private NodePeerUsageAccountingService $accounting) {}

    public function reconcile(int $limit = 25): int
    {
        if (! config('node_access.usage_reconciliation_enabled')
            || $this->driver instanceof DisabledNodePeerUsageDriver) {
            return 0;
        }

        $peers = DB::table('node_access_peers as peer')->join('nodes as node', 'node.id', '=', 'peer.node_id')
            ->where('peer.status', 'active')->where('node.is_test', false)
            ->whereNotNull('peer.provision_confirmation_status')->where('peer.provision_confirmation_status', 'confirmed')
            ->orderBy('peer.last_observed_at')->limit(min(100, max(1, $limit)))
            ->get(['peer.*', 'node.id as node_record_id']);
        $processed = 0;
        foreach ($peers as $peer) {
            $node = DB::table('nodes')->where('id', $peer->node_record_id)->first();
            if (! $node) {
                continue;
            }
            try {
                $result = $this->driver->usage($node, $peer);
                $snapshot = NodeAccessAgentAcknowledgement::usage($result, $node, $peer);
                $this->accounting->applySnapshot($peer->id, $snapshot['upload_bytes'], $snapshot['download_bytes']);
            } catch (Throwable) {
                continue;
            }
            $processed++;
        }

        return $processed;
    }
}
