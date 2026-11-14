<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NodeAccessRevocationProcessor
{
    public function __construct(private NodePeerRevocationDriver $driver) {}

    public function processDue(int $limit = 25): int
    {
        if (! config('node_access.enabled') || config('node_access.driver') === 'disabled'
            || $this->driver instanceof DisabledNodePeerRevocationDriver) {
            return 0;
        }
        if ($this->driver instanceof SandboxNodePeerRevocationDriver && ! app()->environment(['local', 'testing'])) {
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
                $this->revoke($job);
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
            $job = DB::table('node_access_revocation_outbox')->where(function ($query) use ($nowText): void {
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
            DB::table('node_access_revocation_outbox')->where('id', $job->id)->update([
                'status' => 'processing',
                'attempts' => (int) $job->attempts + 1,
                'processing_at' => $nowText,
                'lease_expires_at' => $now->copy()->addSeconds((int) config('node_access.lease_seconds', 60))->toIso8601String(),
                'lock_token' => $lockToken,
                'updated_at' => $nowText,
            ]);

            return DB::table('node_access_revocation_outbox')->where('id', $job->id)->first();
        }, 3);
    }

    private function revoke(object $job): void
    {
        $peer = DB::table('node_access_peers')->where('id', $job->peer_id)->first();
        $node = $peer ? DB::table('nodes')->where('id', $peer->node_id)->first() : null;
        if (! $peer || ! $node) {
            throw new RuntimeException('The node peer or node record is unavailable.');
        }

        $result = $this->driver->revoke($node, $peer, $job->id);
        $acknowledgementId = $result['acknowledgement_id'] ?? null;
        $uploadBytes = $result['upload_bytes'] ?? null;
        $downloadBytes = $result['download_bytes'] ?? null;
        $confirmationStatus = $result['confirmation_status'] ?? null;
        if (! is_string($acknowledgementId) || $acknowledgementId === '' || strlen($acknowledgementId) > 255
            || ! is_int($uploadBytes) || $uploadBytes < 0
            || ! is_int($downloadBytes) || $downloadBytes < 0
            || ! in_array($confirmationStatus, ['confirmed', 'confirmed_test'], true)) {
            throw new RuntimeException('The node revocation acknowledgement is invalid.');
        }
        if ($confirmationStatus === 'confirmed_test' && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Sandbox acknowledgement is not valid in this environment.');
        }

        DB::transaction(function () use ($job, $acknowledgementId, $uploadBytes, $downloadBytes, $confirmationStatus): void {
            $claimed = DB::table('node_access_revocation_outbox')->where('id', $job->id)->where('status', 'processing')
                ->where('lock_token', $job->lock_token)->lockForUpdate()->first();
            if (! $claimed) {
                return;
            }

            $at = now()->utc()->toIso8601String();
            $peer = DB::table('node_access_peers')->where('id', $job->peer_id)->lockForUpdate()->first();
            if (! $peer) {
                throw new RuntimeException('The node peer record is unavailable.');
            }
            $finalUploadBytes = max((int) $peer->upload_bytes, $uploadBytes);
            $finalDownloadBytes = max((int) $peer->download_bytes, $downloadBytes);
            DB::table('node_access_peers')->where('id', $job->peer_id)->update([
                'status' => 'revoked', 'upload_bytes' => $finalUploadBytes, 'download_bytes' => $finalDownloadBytes,
                'revoked_at' => $at, 'updated_at' => $at,
            ]);
            DB::table('node_access_revocation_outbox')->where('id', $job->id)->where('lock_token', $job->lock_token)->update([
                'status' => $confirmationStatus, 'acknowledgement_id' => $acknowledgementId,
                'final_upload_bytes' => $finalUploadBytes, 'final_download_bytes' => $finalDownloadBytes,
                'last_error_code' => null, 'processing_at' => null, 'lease_expires_at' => null, 'lock_token' => null,
                'confirmed_at' => $at, 'updated_at' => $at,
            ]);
        }, 3);
    }

    private function scheduleRetry(object $job): void
    {
        $attempt = (int) $job->attempts;
        $delaySeconds = min(3600, 10 * (2 ** min(max($attempt - 1, 0), 8)));
        $errorCode = $this->driver instanceof DisabledNodePeerRevocationDriver ? 'driver_unavailable' : 'provider_error';
        $at = now()->utc();

        DB::table('node_access_revocation_outbox')->where('id', $job->id)->where('status', 'processing')
            ->where('lock_token', $job->lock_token)->update([
                'status' => 'retry', 'next_attempt_at' => $at->copy()->addSeconds($delaySeconds)->toIso8601String(),
                'processing_at' => null, 'lease_expires_at' => null, 'lock_token' => null,
                'last_error_code' => $errorCode, 'updated_at' => $at->toIso8601String(),
            ]);
    }
}
