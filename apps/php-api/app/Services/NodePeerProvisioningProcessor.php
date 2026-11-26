<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NodePeerProvisioningProcessor
{
    public function __construct(
        private NodePeerProvisioningDriver $driver,
        private NodePeerRevocationRequestService $revocations,
    ) {}

    public function processDue(int $limit = 25): int
    {
        if (! config('node_access.provisioning_enabled')
            || $this->driver instanceof DisabledNodePeerProvisioningDriver) {
            return 0;
        }
        if ($this->driver instanceof SandboxNodePeerProvisioningDriver && ! app()->environment(['local', 'testing'])) {
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

            $logContext = [
                'node_operation_id' => (string) $job->id,
                'node_peer_id' => (string) $job->peer_id,
            ];
            if (is_string($job->request_correlation_id) && $job->request_correlation_id !== '') {
                $logContext['request_id'] = $job->request_correlation_id;
            }
            Log::shareContext($logContext);

            try {
                Log::info('Processing node peer provisioning outbox item.');
                $this->provision($job);
                Log::info('Node peer provisioning outbox item processed.');
            } catch (Throwable $exception) {
                $this->scheduleRetry($job);
                Log::warning('Node peer provisioning attempt failed.', [
                    'exception_class' => $exception::class,
                ]);
            } finally {
                Log::withoutContext(array_keys($logContext));
            }
        }

        return $processed;
    }

    private function claimNext(): ?object
    {
        return DB::transaction(function (): ?object {
            $now = now()->utc();
            $nowText = $now->toIso8601String();
            $job = DB::table('node_access_provisioning_outbox')->where(function ($query) use ($nowText): void {
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
            DB::table('node_access_provisioning_outbox')->where('id', $job->id)->update([
                'status' => 'processing',
                'attempts' => (int) $job->attempts + 1,
                'processing_at' => $nowText,
                'lease_expires_at' => $now->copy()->addSeconds((int) config('node_access.lease_seconds', 60))->toIso8601String(),
                'lock_token' => $lockToken,
                'updated_at' => $nowText,
            ]);

            return DB::table('node_access_provisioning_outbox')->where('id', $job->id)->first();
        }, 3);
    }

    private function provision(object $job): void
    {
        $peer = DB::table('node_access_peers')->where('id', $job->peer_id)->first();
        $node = $peer ? DB::table('nodes')->where('id', $peer->node_id)->first() : null;
        if (! $peer || ! $node) {
            throw new RuntimeException('The node peer or node record is unavailable.');
        }
        if ($peer->status !== 'provisioning' || (int) $peer->generation !== (int) $job->generation) {
            $this->cancel($job, 'superseded');

            return;
        }
        if (! $this->hasActiveEntitlement($peer)) {
            $this->queueRevocation($job, 'entitlement_lost');

            return;
        }

        $credential = Crypt::decryptString((string) $peer->credential_ciphertext);
        $result = $this->driver->provision($node, $peer, $job->id, (int) $job->generation, $credential);
        $acknowledgement = NodeAccessAgentAcknowledgement::provision(
            $result,
            $node,
            $peer,
            $job->id,
            (int) $job->generation,
        );

        DB::transaction(function () use ($job, $acknowledgement): void {
            $claimed = DB::table('node_access_provisioning_outbox')->where('id', $job->id)->where('status', 'processing')
                ->where('lock_token', $job->lock_token)->lockForUpdate()->first();
            if (! $claimed) {
                return;
            }

            $at = now()->utc()->toIso8601String();
            $peer = DB::table('node_access_peers')->where('id', $job->peer_id)->lockForUpdate()->first();
            if (! $peer) {
                throw new RuntimeException('The node peer record is unavailable.');
            }
            if ($peer->status !== 'provisioning' || (int) $peer->generation !== (int) $job->generation
                || ! $this->hasActiveEntitlement($peer)) {
                if ($peer->status === 'provisioning') {
                    $this->queueRevocationForPeer($peer, 'access_lost_during_provision');
                }
                DB::table('node_access_provisioning_outbox')->where('id', $job->id)->where('lock_token', $job->lock_token)->update([
                    'status' => 'superseded', 'acknowledgement_id' => $acknowledgement['acknowledgement_id'],
                    'last_error_code' => null, 'processing_at' => null, 'lease_expires_at' => null, 'lock_token' => null,
                    'confirmed_at' => $at, 'updated_at' => $at,
                ]);

                return;
            }

            DB::table('node_access_peers')->where('id', $peer->id)->update([
                'status' => 'active', 'profile_ciphertext' => Crypt::encryptString($acknowledgement['profile']),
                'profile_format' => $acknowledgement['profile_format'],
                'profile_renderer_id' => $acknowledgement['profile_renderer_id'],
                'profile_renderer_version' => $acknowledgement['profile_renderer_version'],
                'profile_sha256' => $acknowledgement['profile_sha256'],
                'bridge_endpoint' => $acknowledgement['bridge_endpoint'] ?? null,
                'bridge_server_ca_ciphertext' => isset($acknowledgement['bridge_server_ca_pem'])
                    ? Crypt::encryptString($acknowledgement['bridge_server_ca_pem']) : null,
                'provision_acknowledgement_id' => $acknowledgement['acknowledgement_id'],
                'provision_confirmation_status' => $acknowledgement['confirmation_status'], 'updated_at' => $at,
            ]);
            DB::table('node_access_provisioning_outbox')->where('id', $job->id)->where('lock_token', $job->lock_token)->update([
                'status' => $acknowledgement['confirmation_status'],
                'acknowledgement_id' => $acknowledgement['acknowledgement_id'], 'last_error_code' => null,
                'processing_at' => null, 'lease_expires_at' => null, 'lock_token' => null,
                'confirmed_at' => $at, 'updated_at' => $at,
            ]);
        }, 3);
    }

    private function hasActiveEntitlement(object $peer): bool
    {
        if (! $peer->entitlement_id || ! $peer->expires_at) {
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

        return DB::table('member_devices as d')->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.id', $peer->device_id)->where('d.user_id', $peer->user_id)
            ->where('d.status', 'registered')->whereNull('d.remove_requested_at')->where('u.status', 'active')
            ->whereExists(function ($query) use ($peer, $now): void {
                $query->selectRaw('1')->from('entitlements as e')->join('plans as p', 'p.id', '=', 'e.plan_id')
                    ->where('e.id', $peer->entitlement_id)->where('e.user_id', $peer->user_id)->where('e.status', 'active')
                    ->where('e.starts_at', '<=', $now)->where('e.ends_at', '>', $now)
                    ->whereColumn('e.data_used_gb', '<', 'p.traffic_gb');
            })->exists();
    }

    private function queueRevocation(object $job, string $reason): void
    {
        DB::transaction(function () use ($job, $reason): void {
            $peer = DB::table('node_access_peers')->where('id', $job->peer_id)->lockForUpdate()->first();
            if ($peer && $peer->status === 'provisioning') {
                $this->queueRevocationForPeer($peer, $reason, now()->utc()->toIso8601String());
            }
            $this->cancel($job, 'access_lost');
        }, 3);
    }

    private function queueRevocationForPeer(object $peer, string $reason): void
    {
        $this->revocations->request($peer, $reason);
    }

    private function cancel(object $job, string $status): void
    {
        $at = now()->utc()->toIso8601String();
        DB::table('node_access_provisioning_outbox')->where('id', $job->id)->where('status', 'processing')
            ->where('lock_token', $job->lock_token)->update([
                'status' => $status, 'processing_at' => null, 'lease_expires_at' => null,
                'lock_token' => null, 'updated_at' => $at,
            ]);
    }

    private function scheduleRetry(object $job): void
    {
        $attempt = (int) $job->attempts;
        $delaySeconds = min(3600, 10 * (2 ** min(max($attempt - 1, 0), 8)));
        $at = now()->utc();
        DB::table('node_access_provisioning_outbox')->where('id', $job->id)->where('status', 'processing')
            ->where('lock_token', $job->lock_token)->update([
                'status' => 'retry', 'next_attempt_at' => $at->copy()->addSeconds($delaySeconds)->toIso8601String(),
                'processing_at' => null, 'lease_expires_at' => null, 'lock_token' => null,
                'last_error_code' => 'node_agent_error', 'updated_at' => $at->toIso8601String(),
            ]);
    }
}
