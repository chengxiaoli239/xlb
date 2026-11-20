<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpiredNodeAccessReconciler
{
    public function queueRevocations(int $userLimit = 25): int
    {
        $now = now()->utc()->toIso8601String();
        $userIds = DB::table('node_access_peers as peer')
            ->join('users as member', 'member.id', '=', 'peer.user_id')
            ->whereIn('peer.status', ['active', 'provisioning'])
            ->where(function ($query) use ($now): void {
                $query->where('member.status', '!=', 'active')
                    ->orWhereNotExists(function ($entitlements) use ($now): void {
                        $entitlements->selectRaw('1')->from('entitlements as e')
                            ->join('plans as p', 'p.id', '=', 'e.plan_id')
                            ->whereColumn('e.user_id', 'peer.user_id')
                            ->where('e.status', 'active')->where('e.starts_at', '<=', $now)
                            ->where('e.ends_at', '>', $now)
                            ->whereColumn('e.data_used_gb', '<', 'p.traffic_gb');
                    });
            })
            ->distinct()->limit(min(100, max(1, $userLimit)))->pluck('peer.user_id');

        $queued = 0;
        foreach ($userIds as $userId) {
            $queued += DB::transaction(function () use ($userId): int {
                $member = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
                if (! $member) {
                    return 0;
                }
                $now = now()->utc()->toIso8601String();
                $active = DB::table('entitlements as e')->join('plans as p', 'p.id', '=', 'e.plan_id')
                    ->where('e.user_id', $userId)->where('e.status', 'active')
                    ->where('e.starts_at', '<=', $now)->where('e.ends_at', '>', $now)
                    ->whereColumn('e.data_used_gb', '<', 'p.traffic_gb')->exists();
                if ($member->status === 'active' && $active) {
                    return 0;
                }

                $peers = DB::table('node_access_peers')->where('user_id', $userId)
                    ->whereIn('status', ['active', 'provisioning'])->lockForUpdate()->get(['id', 'generation']);
                $count = 0;
                foreach ($peers as $peer) {
                    $inserted = DB::table('node_access_revocation_outbox')->insertOrIgnore([
                        'id' => (string) Str::uuid(), 'peer_id' => $peer->id,
                        'refund_event_id' => null, 'reason' => 'entitlement_lost',
                        'generation' => (int) $peer->generation + 1,
                        'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => $now,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    if ($inserted !== 1) {
                        continue;
                    }
                    DB::table('node_access_peers')->where('id', $peer->id)->whereIn('status', ['active', 'provisioning'])
                        ->update(['status' => 'revocation_pending', 'generation' => (int) $peer->generation + 1, 'updated_at' => $now]);
                    $count++;
                }

                return $count;
            }, 3);
        }

        return $queued;
    }
}
