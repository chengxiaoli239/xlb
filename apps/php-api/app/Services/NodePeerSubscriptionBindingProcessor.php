<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NodePeerSubscriptionBindingProcessor
{
    public function __construct(
        private NodePeerSubscriptionBindingDriver $driver,
        private NodePeerRevocationRequestService $revocations,
    ) {}

    public function processDue(int $limit = 25): int
    {
        if (! config('node_access.subscription_binding_enabled')
            || $this->driver instanceof DisabledNodePeerSubscriptionBindingDriver) {
            return 0;
        }
        if ($this->driver instanceof SandboxNodePeerSubscriptionBindingDriver && ! app()->environment(['local', 'testing'])) {
            return 0;
        }

        $processed = 0;
        $limit = min(100, max(1, $limit));
        while ($processed < $limit) {
            $job = $this->claimNext();
            if (! $job) {
                break;
            }
            $processed++;

            try {
                $this->bind($job);
            } catch (Throwable) {
                $this->scheduleRetry($job);
            }
        }

        return $processed;
    }

    private function claimNext(): ?object
    {
        return DB::transaction(function (): ?object {
            $now = now()->utc();
            $nowText = $now->toIso8601String();
            $job = DB::table('node_access_subscription_binding_outbox')->where(function ($query) use ($nowText): void {
                $query->where(function ($due) use ($nowText): void {
                    $due->whereIn('status', ['pending', 'retry'])->where(function ($available) use ($nowText): void {
                        $available->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $nowText);
                    });
                })->orWhere(function ($stale) use ($nowText): void {
                    $stale->where('status', 'processing')->where('lease_expires_at', '<=', $nowText);
                });
            })->orderBy('created_at')->lockForUpdate()->first();

            if (! $job) {
                return null;
            }

            $lockToken = (string) Str::uuid();
            DB::table('node_access_subscription_binding_outbox')->where('id', $job->id)->update([
                'status' => 'processing',
                'attempts' => (int) $job->attempts + 1,
                'processing_at' => $nowText,
                'lease_expires_at' => $now->copy()->addSeconds((int) config('node_access.lease_seconds', 60))->toIso8601String(),
                'lock_token' => $lockToken,
                'updated_at' => $nowText,
            ]);

            return DB::table('node_access_subscription_binding_outbox')->where('id', $job->id)->first();
        }, 3);
    }

    private function bind(object $job): void
    {
        $subscription = DB::table('subscriptions')->where('id', $job->subscription_id)->first();
        $peer = DB::table('node_access_peers')->where('id', $job->peer_id)->first();
        $node = $peer ? DB::table('nodes')->where('id', $peer->node_id)->first() : null;
        if (! $subscription || ! $peer || ! $node) {
            $this->finishAsSuperseded($job);

            return;
        }
        if ($subscription->status !== 'active' || (int) $subscription->credential_generation !== (int) $job->generation
            || $peer->status !== 'active' || $subscription->member_device_id !== $peer->device_id
            || $subscription->node_access_peer_id !== $peer->id
            || (int) $subscription->node_access_peer_generation !== (int) $peer->generation
            || (int) $subscription->member_device_generation !== (int) $peer->device_binding_generation) {
            $this->finishAsSuperseded($job);

            return;
        }
        if (! $this->hasActiveEntitlement($subscription->user_id, $peer)) {
            $this->revokeForLostEntitlement($job, $subscription, $peer);

            return;
        }

        try {
            $token = Crypt::decryptString((string) $subscription->token_encrypted);
        } catch (DecryptException) {
            throw new RuntimeException('The subscription credential cannot be decrypted.');
        }
        if (! hash_equals((string) $subscription->token_hash, hash('sha256', $token))) {
            throw new RuntimeException('The subscription credential hash is invalid.');
        }

        $result = $this->driver->bind($node, $peer, $subscription, $job->id, $token);
        $acknowledgement = NodeAccessAgentAcknowledgement::subscriptionBinding(
            $result,
            $node,
            $peer,
            $job->id,
            (string) $subscription->id,
            (int) $job->generation,
        );

        DB::transaction(function () use ($job, $acknowledgement): void {
            $claimed = DB::table('node_access_subscription_binding_outbox')->where('id', $job->id)
                ->where('status', 'processing')->where('lock_token', $job->lock_token)->lockForUpdate()->first();
            if (! $claimed) {
                return;
            }

            $subscription = DB::table('subscriptions')->where('id', $job->subscription_id)->lockForUpdate()->first();
            $peer = DB::table('node_access_peers')->where('id', $job->peer_id)->lockForUpdate()->first();
            $entitlementStillActive = $subscription && $peer
                && $this->hasActiveEntitlement($subscription->user_id, $peer);
            $stillCurrent = $subscription && $peer && $subscription->status === 'active'
                && (int) $subscription->credential_generation === (int) $job->generation
                && $subscription->member_device_id === $peer->device_id
                && $peer->status === 'active' && $subscription->node_access_peer_id === $peer->id
                && (int) $subscription->node_access_peer_generation === (int) $peer->generation
                && (int) $subscription->member_device_generation === (int) $peer->device_binding_generation
                && $entitlementStillActive;
            $at = now()->utc()->toIso8601String();
            if (! $stillCurrent) {
                if ($peer && $peer->status === 'active') {
                    $this->revocations->request($peer, $entitlementStillActive ? 'subscription_revoked' : 'entitlement_lost');
                }
                DB::table('node_access_subscription_binding_outbox')->where('id', $job->id)->update([
                    'status' => 'superseded', 'acknowledgement_id' => $acknowledgement['acknowledgement_id'],
                    'last_error_code' => null, 'processing_at' => null, 'lease_expires_at' => null,
                    'lock_token' => null, 'confirmed_at' => $at, 'updated_at' => $at,
                ]);

                return;
            }

            DB::table('node_access_subscription_binding_outbox')->where('id', $job->id)->update([
                'status' => $acknowledgement['confirmation_status'],
                'acknowledgement_id' => $acknowledgement['acknowledgement_id'],
                'last_error_code' => null, 'processing_at' => null, 'lease_expires_at' => null,
                'lock_token' => null, 'confirmed_at' => $at, 'updated_at' => $at,
            ]);
        }, 3);
    }

    private function finishAsSuperseded(object $job): void
    {
        DB::table('node_access_subscription_binding_outbox')->where('id', $job->id)
            ->where('status', 'processing')->where('lock_token', $job->lock_token)->update([
                'status' => 'superseded', 'last_error_code' => null,
                'processing_at' => null, 'lease_expires_at' => null, 'lock_token' => null,
                'updated_at' => now()->utc()->toIso8601String(),
            ]);
    }

    private function revokeForLostEntitlement(object $job, object $subscription, object $peer): void
    {
        DB::transaction(function () use ($job, $subscription, $peer): void {
            $current = DB::table('subscriptions')->where('id', $subscription->id)->lockForUpdate()->first();
            $lockedPeer = DB::table('node_access_peers')->where('id', $peer->id)->lockForUpdate()->first();
            $at = now()->utc()->toIso8601String();
            if ($current && $current->status === 'active') {
                DB::table('subscriptions')->where('id', $current->id)->update([
                    'status' => 'revoked', 'token_encrypted' => null,
                ]);
            }
            if ($lockedPeer && $lockedPeer->status === 'active') {
                $this->revocations->request($lockedPeer, 'entitlement_lost');
            }
            DB::table('node_access_subscription_binding_outbox')->where('id', $job->id)
                ->where('status', 'processing')->where('lock_token', $job->lock_token)->update([
                    'status' => 'superseded', 'last_error_code' => 'entitlement_lost',
                    'processing_at' => null, 'lease_expires_at' => null, 'lock_token' => null,
                    'confirmed_at' => $at, 'updated_at' => $at,
                ]);
        }, 3);
    }

    private function hasActiveEntitlement(string $userId, object $peer): bool
    {
        if ($peer->user_id !== $userId || ! $peer->entitlement_id || ! $peer->expires_at) {
            return false;
        }

        try {
            if (! Carbon::parse($peer->expires_at)->isAfter(now())) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $now = now()->utc()->toIso8601String();

        return DB::table('entitlements as e')->join('plans as p', 'p.id', '=', 'e.plan_id')
            ->join('users as u', 'u.id', '=', 'e.user_id')->where('e.id', $peer->entitlement_id)->where('e.user_id', $userId)
            ->where('u.status', 'active')->where('e.status', 'active')
            ->where('e.starts_at', '<=', $now)->where('e.ends_at', '>', $now)
            ->whereColumn('e.data_used_gb', '<', 'p.traffic_gb')->exists();
    }

    private function scheduleRetry(object $job): void
    {
        $attempt = (int) $job->attempts;
        $delaySeconds = min(3600, 10 * (2 ** min(max($attempt - 1, 0), 8)));
        $at = now()->utc();
        DB::table('node_access_subscription_binding_outbox')->where('id', $job->id)
            ->where('status', 'processing')->where('lock_token', $job->lock_token)->update([
                'status' => 'retry', 'next_attempt_at' => $at->copy()->addSeconds($delaySeconds)->toIso8601String(),
                'processing_at' => null, 'lease_expires_at' => null, 'lock_token' => null,
                'last_error_code' => $this->driver instanceof DisabledNodePeerSubscriptionBindingDriver
                    ? 'driver_unavailable' : 'node_agent_error',
                'updated_at' => $at->toIso8601String(),
            ]);
    }
}
