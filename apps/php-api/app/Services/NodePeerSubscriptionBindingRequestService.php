<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NodePeerSubscriptionBindingRequestService
{
    public function request(object $subscription, object $peer): object
    {
        $generation = (int) $subscription->credential_generation;
        $existing = DB::table('node_access_subscription_binding_outbox')
            ->where('subscription_id', $subscription->id)->where('generation', $generation)->first();
        if ($existing) {
            return $existing;
        }

        $now = now()->utc()->toIso8601String();
        DB::table('node_access_subscription_binding_outbox')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'subscription_id' => $subscription->id,
            'peer_id' => $peer->id,
            'generation' => $generation,
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('node_access_subscription_binding_outbox')
            ->where('subscription_id', $subscription->id)->where('generation', $generation)->firstOrFail();
    }
}
