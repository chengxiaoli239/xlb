<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class NodeHealthRouteStateReader
{
    /**
     * @param  array<int, string>  $nodeIds
     * @return array<string, true>
     */
    public function freshHealthyNodeIds(array $nodeIds, DateTimeImmutable $asOf, int $ttlSeconds): array
    {
        $nodeIds = array_values(array_unique(array_filter(
            $nodeIds,
            static fn (mixed $nodeId): bool => is_string($nodeId) && trim($nodeId) !== '',
        )));
        if ($nodeIds === []) {
            return [];
        }

        $cutoff = $asOf->modify('-'.max(1, $ttlSeconds).' seconds');
        $states = DB::table('node_health_probe_states')
            ->whereIn('node_id', $nodeIds)
            ->where('status', 'healthy')
            ->whereNotNull('last_probe_at')
            ->get(['node_id', 'last_probe_at']);

        $freshHealthyNodeIds = [];
        foreach ($states as $state) {
            try {
                $lastProbeAt = new DateTimeImmutable($state->last_probe_at);
            } catch (\Throwable) {
                continue;
            }

            if ($lastProbeAt >= $cutoff && $lastProbeAt <= $asOf) {
                $freshHealthyNodeIds[$state->node_id] = true;
            }
        }

        return $freshHealthyNodeIds;
    }
}
