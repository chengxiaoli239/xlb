<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class TrafficQuotaReservationService
{
    private const BYTES_PER_GIB = 1073741824;

    /**
     * Reserve bytes from the single entitlement budget shared by every node and peer.
     *
     * @return array{id:string,request_id:string,entitlement_id:string,node_id:string,peer_id:string,generation:int,grant_bytes:int,status:string,lease_expires_at:string}
     */
    public function reserve(
        string $entitlementId,
        string $nodeId,
        string $peerId,
        int $generation,
        string $requestId,
        int $grantBytes,
        int $leaseSeconds = 30,
    ): array {
        if ($entitlementId === '' || $nodeId === '' || $peerId === '' || $generation < 1
            || ! preg_match('/\A[a-zA-Z0-9-]{1,36}\z/D', $requestId) || $grantBytes < 1
            || $leaseSeconds < 5 || $leaseSeconds > 120) {
            throw new RuntimeException('Traffic reservation request is invalid.');
        }

        return DB::transaction(function () use ($entitlementId, $nodeId, $peerId, $generation, $requestId, $grantBytes, $leaseSeconds): array {
            $existing = DB::table('node_traffic_reservations')->where('request_id', $requestId)->lockForUpdate()->first();
            if ($existing) {
                return $this->existingReservationResponse(
                    $existing, $entitlementId, $nodeId, $peerId, $generation, $grantBytes,
                );
            }

            $peer = DB::table('node_access_peers')->where('id', $peerId)->lockForUpdate()->first();
            // Recheck after the peer lock so concurrent retries reuse the row created by the first request.
            $existing = DB::table('node_traffic_reservations')->where('request_id', $requestId)->lockForUpdate()->first();
            if ($existing) {
                return $this->existingReservationResponse(
                    $existing, $entitlementId, $nodeId, $peerId, $generation, $grantBytes,
                );
            }

            if (! $peer || $peer->node_id !== $nodeId || $peer->entitlement_id !== $entitlementId
                || (int) $peer->generation !== $generation || $peer->status !== 'active'
                || $peer->provision_confirmation_status !== 'confirmed' || ! $this->isFuture($peer->expires_at)) {
                throw new RuntimeException('Traffic reservation peer is not active for this entitlement.');
            }

            $entitlement = $this->activeEntitlement($entitlementId);
            if (! $entitlement || $entitlement->user_id !== $peer->user_id) {
                throw new RuntimeException('Traffic reservation entitlement is not active.');
            }

            $node = DB::table('nodes')->where('id', $nodeId)->lockForUpdate()
                ->first(['is_test', 'status', 'health_status']);
            if (! $node || (bool) $node->is_test || $node->status !== 'available' || $node->health_status !== 'healthy') {
                throw new RuntimeException('Traffic reservation node is not eligible.');
            }

            $this->ensureBudget($entitlement);
            $at = now()->utc();
            $reserved = DB::table('entitlement_traffic_budgets')->where('entitlement_id', $entitlementId)
                ->whereRaw('used_bytes <= quota_bytes')
                ->whereRaw('reserved_bytes <= quota_bytes - used_bytes')
                ->whereRaw('? <= quota_bytes - used_bytes - reserved_bytes', [$grantBytes])
                ->increment('reserved_bytes', $grantBytes, ['updated_at' => $at->toIso8601String()]);
            if ($reserved !== 1) {
                throw new TrafficQuotaExceeded('The shared entitlement traffic quota is fully used or reserved.');
            }

            $reservationId = (string) Str::uuid();
            $expiresAt = $at->copy()->addSeconds($leaseSeconds)->toIso8601String();
            DB::table('node_traffic_reservations')->insert([
                'id' => $reservationId, 'request_id' => $requestId, 'entitlement_id' => $entitlementId,
                'node_id' => $nodeId, 'peer_id' => $peerId, 'peer_generation' => $generation,
                'grant_bytes' => $grantBytes, 'settled_upload_bytes' => 0, 'settled_download_bytes' => 0,
                'status' => 'reserved', 'lease_expires_at' => $expiresAt, 'acknowledgement_id' => null,
                'final_at' => null, 'created_at' => $at->toIso8601String(), 'updated_at' => $at->toIso8601String(),
            ]);

            return $this->reservationResponse(DB::table('node_traffic_reservations')->where('id', $reservationId)->first());
        }, 10);
    }

    /**
     * Apply a node-authenticated cumulative lease receipt. Unused bytes are released only on final=true.
     *
     * @param  array<string, mixed>  $receipt
     * @return array{id:string,request_id:string,used_bytes:int,reserved_bytes:int,status:string}
     */
    public function settle(array $receipt): array
    {
        $this->validateReceipt($receipt);

        return DB::transaction(function () use ($receipt): array {
            $candidate = DB::table('node_traffic_reservations')->where('request_id', $receipt['request_id'])->first();
            if (! $candidate) {
                throw new RuntimeException('Traffic reservation does not exist.');
            }
            $peer = DB::table('node_access_peers')->where('id', $candidate->peer_id)->lockForUpdate()->first();
            $reservation = DB::table('node_traffic_reservations')->where('id', $candidate->id)->lockForUpdate()->first();
            if (! $peer || ! $reservation || $receipt['node_id'] !== $reservation->node_id
                || $receipt['peer_id'] !== $reservation->peer_id || $receipt['generation'] !== (int) $reservation->peer_generation) {
                throw new RuntimeException('Traffic receipt identity does not match its reservation.');
            }

            if ($reservation->status === 'settled') {
                if ($receipt['final'] === true && $receipt['acknowledgement_id'] === $reservation->acknowledgement_id
                    && $receipt['upload_bytes'] === (int) $reservation->settled_upload_bytes
                    && $receipt['download_bytes'] === (int) $reservation->settled_download_bytes) {
                    return $this->settlementResponse($reservation);
                }
                throw new TrafficQuotaReservationConflict('A settled reservation cannot accept another receipt.');
            }

            $previousUpload = (int) $reservation->settled_upload_bytes;
            $previousDownload = (int) $reservation->settled_download_bytes;
            $newUpload = $receipt['upload_bytes'];
            $newDownload = $receipt['download_bytes'];
            if ($newUpload < $previousUpload || $newDownload < $previousDownload
                || $newUpload > PHP_INT_MAX - $newDownload
                || $newUpload + $newDownload > (int) $reservation->grant_bytes) {
                throw new RuntimeException('Traffic receipt counters are invalid or exceed the reservation.');
            }
            $previousTotal = $previousUpload + $previousDownload;
            $newTotal = $newUpload + $newDownload;
            $settledDelta = $newTotal - $previousTotal;
            $at = now()->utc()->toIso8601String();
            DB::table('node_traffic_reservations')->where('id', $reservation->id)->update([
                'settled_upload_bytes' => $newUpload, 'settled_download_bytes' => $newDownload,
                'acknowledgement_id' => $receipt['acknowledgement_id'], 'status' => $receipt['final'] ? 'settled' : 'reserved',
                'final_at' => $receipt['final'] ? $at : null, 'updated_at' => $at,
            ]);

            $releasedBytes = $settledDelta;
            if ($receipt['final']) {
                $releasedBytes += (int) $reservation->grant_bytes - $newTotal;
            }
            if ($releasedBytes > 0) {
                $released = DB::table('entitlement_traffic_budgets')->where('entitlement_id', $reservation->entitlement_id)
                    ->where('reserved_bytes', '>=', $releasedBytes)
                    ->decrement('reserved_bytes', $releasedBytes, ['updated_at' => $at]);
                if ($released !== 1) {
                    throw new RuntimeException('Traffic reservation accounting is inconsistent.');
                }
            }

            $this->accountSettledUsage($peer, $reservation->entitlement_id, $at);

            return $this->settlementResponse(DB::table('node_traffic_reservations')->where('id', $reservation->id)->first());
        }, 10);
    }

    /** @return array{changed:bool,delta_bytes:int,quota_exhausted:bool} */
    public function applyUsageSnapshot(string $peerId, int $uploadBytes, int $downloadBytes): array
    {
        return DB::transaction(function () use ($peerId, $uploadBytes, $downloadBytes): array {
            $peer = DB::table('node_access_peers')->where('id', $peerId)->lockForUpdate()->first();
            if (! $peer) {
                throw new RuntimeException('Node peer is unavailable.');
            }

            return $this->recordUsageSnapshotWithinTransaction($peer, $uploadBytes, $downloadBytes);
        }, 10);
    }

    /** @return array{changed:bool,delta_bytes:int,quota_exhausted:bool} */
    public function recordUsageSnapshotWithinTransaction(object $peer, int $uploadBytes, int $downloadBytes): array
    {
        if ($uploadBytes < 0 || $downloadBytes < 0 || $uploadBytes > PHP_INT_MAX - $downloadBytes) {
            throw new RuntimeException('Node usage counters are outside the supported range.');
        }
        if ($uploadBytes < (int) $peer->upload_bytes || $downloadBytes < (int) $peer->download_bytes) {
            throw new RuntimeException('Node usage snapshot is missing or moved backwards.');
        }
        $uploadChange = $uploadBytes - (int) $peer->upload_bytes;
        $downloadChange = $downloadBytes - (int) $peer->download_bytes;
        if ($uploadChange > PHP_INT_MAX - $downloadChange) {
            throw new RuntimeException('Node usage snapshot delta exceeds the supported byte counter range.');
        }
        $snapshotDelta = $uploadChange + $downloadChange;
        if ($peer->entitlement_id === null) {
            $this->updatePeerSnapshot($peer->id, $uploadBytes, $downloadBytes);

            return ['changed' => $snapshotDelta > 0, 'delta_bytes' => $snapshotDelta, 'quota_exhausted' => false];
        }

        $entitlement = $this->activeEntitlement((string) $peer->entitlement_id, false);
        if (! $entitlement) {
            $this->updatePeerSnapshot($peer->id, $uploadBytes, $downloadBytes);

            return ['changed' => $snapshotDelta > 0, 'delta_bytes' => $snapshotDelta, 'quota_exhausted' => false];
        }

        $budget = $this->ensureBudget($entitlement);
        $settled = $this->settledPeerTotals($peer->id);
        $newAccountedUpload = max($uploadBytes, $settled['upload_bytes']);
        $newAccountedDownload = max($downloadBytes, $settled['download_bytes']);
        $oldAccountedUpload = (int) $peer->quota_accounted_upload_bytes;
        $oldAccountedDownload = (int) $peer->quota_accounted_download_bytes;
        if ($newAccountedUpload < $oldAccountedUpload || $newAccountedDownload < $oldAccountedDownload) {
            throw new RuntimeException('Peer accounted traffic watermark moved backwards.');
        }
        $uploadDelta = $newAccountedUpload - $oldAccountedUpload;
        $downloadDelta = $newAccountedDownload - $oldAccountedDownload;
        if ($uploadDelta > PHP_INT_MAX - $downloadDelta) {
            throw new RuntimeException('Node usage snapshot delta exceeds the supported byte counter range.');
        }
        $deltaBytes = $uploadDelta + $downloadDelta;
        $at = now()->utc()->toIso8601String();
        if ($deltaBytes > 0) {
            $this->addSettledUsage($entitlement, $budget, $deltaBytes, $at);
        }
        DB::table('node_access_peers')->where('id', $peer->id)->update([
            'upload_bytes' => $uploadBytes, 'download_bytes' => $downloadBytes,
            'quota_accounted_upload_bytes' => $newAccountedUpload,
            'quota_accounted_download_bytes' => $newAccountedDownload,
            'last_observed_at' => $at, 'updated_at' => $at,
        ]);
        $updatedBudget = DB::table('entitlement_traffic_budgets')->where('entitlement_id', $entitlement->id)->first();

        return [
            'changed' => $deltaBytes > 0, 'delta_bytes' => $deltaBytes,
            'quota_exhausted' => (int) $updatedBudget->used_bytes >= (int) $updatedBudget->quota_bytes,
        ];
    }

    private function accountSettledUsage(object $peer, string $entitlementId, string $at): void
    {
        $entitlement = $this->activeEntitlement($entitlementId, false);
        if (! $entitlement) {
            throw new RuntimeException('Traffic receipt entitlement is unavailable.');
        }
        $budget = $this->ensureBudget($entitlement);
        $settled = $this->settledPeerTotals($peer->id);
        $newAccountedUpload = max((int) $peer->upload_bytes, $settled['upload_bytes']);
        $newAccountedDownload = max((int) $peer->download_bytes, $settled['download_bytes']);
        $oldAccountedUpload = (int) $peer->quota_accounted_upload_bytes;
        $oldAccountedDownload = (int) $peer->quota_accounted_download_bytes;
        if ($newAccountedUpload < $oldAccountedUpload || $newAccountedDownload < $oldAccountedDownload) {
            throw new RuntimeException('Settled peer traffic watermark moved backwards.');
        }
        $uploadDelta = $newAccountedUpload - $oldAccountedUpload;
        $downloadDelta = $newAccountedDownload - $oldAccountedDownload;
        if ($uploadDelta > PHP_INT_MAX - $downloadDelta) {
            throw new RuntimeException('Settled traffic delta exceeds the supported byte counter range.');
        }
        $deltaBytes = $uploadDelta + $downloadDelta;
        if ($deltaBytes > 0) {
            $this->addSettledUsage($entitlement, $budget, $deltaBytes, $at);
        }
        DB::table('node_access_peers')->where('id', $peer->id)->update([
            'quota_accounted_upload_bytes' => $newAccountedUpload,
            'quota_accounted_download_bytes' => $newAccountedDownload,
            'updated_at' => $at,
        ]);
    }

    private function addSettledUsage(object $entitlement, object $budget, int $deltaBytes, string $at): void
    {
        if ($deltaBytes > PHP_INT_MAX - (int) $budget->used_bytes) {
            throw new RuntimeException('Entitlement traffic counter exceeds the supported range.');
        }
        $usedBytes = (int) $budget->used_bytes + $deltaBytes;
        DB::table('entitlement_traffic_budgets')->where('entitlement_id', $entitlement->id)->update([
            'used_bytes' => $usedBytes, 'updated_at' => $at,
        ]);
        DB::table('entitlements')->where('id', $entitlement->id)->update([
            'data_used_gb' => (float) $entitlement->data_used_gb + ($deltaBytes / self::BYTES_PER_GIB),
        ]);
    }

    private function ensureBudget(object $entitlement): object
    {
        $quotaBytes = $this->gibibytesToBytes((float) $entitlement->traffic_gb, false);
        $usedBytes = $this->gibibytesToBytes((float) $entitlement->data_used_gb, true);
        $at = now()->utc()->toIso8601String();
        DB::table('entitlement_traffic_budgets')->insertOrIgnore([
            'entitlement_id' => $entitlement->id, 'quota_bytes' => $quotaBytes,
            'used_bytes' => $usedBytes, 'reserved_bytes' => 0,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $budget = DB::table('entitlement_traffic_budgets')->where('entitlement_id', $entitlement->id)->lockForUpdate()->first();
        if (! $budget) {
            throw new RuntimeException('Entitlement traffic budget could not be initialized.');
        }

        return $budget;
    }

    /** @return array{id:string,request_id:string,entitlement_id:string,node_id:string,peer_id:string,generation:int,grant_bytes:int,status:string,lease_expires_at:string} */
    private function existingReservationResponse(
        object $reservation,
        string $entitlementId,
        string $nodeId,
        string $peerId,
        int $generation,
        int $grantBytes,
    ): array {
        if ($reservation->entitlement_id !== $entitlementId || $reservation->node_id !== $nodeId
            || $reservation->peer_id !== $peerId || (int) $reservation->peer_generation !== $generation
            || (int) $reservation->grant_bytes !== $grantBytes) {
            throw new TrafficQuotaReservationConflict('Reservation request ID was reused with different fields.');
        }

        return $this->reservationResponse($reservation);
    }

    private function activeEntitlement(string $entitlementId, bool $requireActive = true): ?object
    {
        $entitlement = DB::table('entitlements as e')->join('plans as p', 'p.id', '=', 'e.plan_id')
            ->where('e.id', $entitlementId)->lockForUpdate()
            ->first(['e.id', 'e.user_id', 'e.status', 'e.starts_at', 'e.ends_at', 'e.data_used_gb', 'p.traffic_gb']);
        if (! $entitlement || ($requireActive && ($entitlement->status !== 'active'
            || ! $this->isAtOrBeforeNow($entitlement->starts_at) || ! $this->isFuture($entitlement->ends_at)))) {
            return null;
        }

        return $entitlement;
    }

    /** @return array{upload_bytes:int,download_bytes:int} */
    private function settledPeerTotals(string $peerId): array
    {
        $totals = DB::table('node_traffic_reservations')->where('peer_id', $peerId)
            ->selectRaw('COALESCE(SUM(settled_upload_bytes), 0) as upload_bytes, COALESCE(SUM(settled_download_bytes), 0) as download_bytes')
            ->first();
        $uploadBytes = (int) $totals->upload_bytes;
        $downloadBytes = (int) $totals->download_bytes;
        if ($uploadBytes > PHP_INT_MAX - $downloadBytes) {
            throw new RuntimeException('Settled peer traffic exceeds the supported counter range.');
        }

        return ['upload_bytes' => $uploadBytes, 'download_bytes' => $downloadBytes];
    }

    private function updatePeerSnapshot(string $peerId, int $uploadBytes, int $downloadBytes): void
    {
        $at = now()->utc()->toIso8601String();
        DB::table('node_access_peers')->where('id', $peerId)->update([
            'upload_bytes' => $uploadBytes, 'download_bytes' => $downloadBytes,
            'quota_accounted_upload_bytes' => $uploadBytes, 'quota_accounted_download_bytes' => $downloadBytes,
            'last_observed_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** @param array<string, mixed> $receipt */
    private function validateReceipt(array $receipt): void
    {
        foreach (['node_id', 'peer_id', 'request_id', 'acknowledgement_id'] as $field) {
            if (! is_string($receipt[$field] ?? null) || $receipt[$field] === '') {
                throw new RuntimeException('Traffic receipt is missing a required identity field.');
            }
        }
        if (! is_int($receipt['generation'] ?? null) || $receipt['generation'] < 1
            || ! is_int($receipt['upload_bytes'] ?? null) || $receipt['upload_bytes'] < 0
            || ! is_int($receipt['download_bytes'] ?? null) || $receipt['download_bytes'] < 0
            || ! is_string($receipt['request_id'] ?? null)
            || ! preg_match('/\A[a-zA-Z0-9-]{1,36}\z/D', $receipt['request_id'])
            || ! is_bool($receipt['final'] ?? null)
            || ! in_array($receipt['confirmation_status'] ?? null, ['confirmed', 'confirmed_test'], true)
            || (($receipt['confirmation_status'] ?? null) === 'confirmed_test' && ! app()->environment(['local', 'testing']))) {
            throw new RuntimeException('Traffic receipt fields are invalid.');
        }
    }

    private function gibibytesToBytes(float $gibibytes, bool $round): int
    {
        if (! is_finite($gibibytes) || $gibibytes < 0 || $gibibytes > PHP_INT_MAX / self::BYTES_PER_GIB) {
            throw new RuntimeException('Entitlement traffic value is outside the supported byte range.');
        }
        $bytes = $gibibytes * self::BYTES_PER_GIB;

        return (int) ($round ? round($bytes) : floor($bytes));
    }

    private function isFuture(string $value): bool
    {
        try {
            return Carbon::parse($value)->isAfter(now());
        } catch (\Throwable) {
            return false;
        }
    }

    private function isAtOrBeforeNow(string $value): bool
    {
        try {
            $date = Carbon::parse($value);

            return $date->isBefore(now()) || $date->equalTo(now());
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{id:string,request_id:string,entitlement_id:string,node_id:string,peer_id:string,generation:int,grant_bytes:int,status:string,lease_expires_at:string} */
    private function reservationResponse(object $reservation): array
    {
        return [
            'id' => (string) $reservation->id, 'request_id' => (string) $reservation->request_id,
            'entitlement_id' => (string) $reservation->entitlement_id,
            'node_id' => (string) $reservation->node_id, 'peer_id' => (string) $reservation->peer_id,
            'generation' => (int) $reservation->peer_generation, 'grant_bytes' => (int) $reservation->grant_bytes,
            'status' => (string) $reservation->status, 'lease_expires_at' => (string) $reservation->lease_expires_at,
        ];
    }

    /** @return array{id:string,request_id:string,used_bytes:int,reserved_bytes:int,status:string} */
    private function settlementResponse(object $reservation): array
    {
        $budget = DB::table('entitlement_traffic_budgets')->where('entitlement_id', $reservation->entitlement_id)->first();

        return [
            'id' => (string) $reservation->id, 'request_id' => (string) $reservation->request_id,
            'used_bytes' => (int) $budget->used_bytes, 'reserved_bytes' => (int) $budget->reserved_bytes,
            'status' => (string) $reservation->status,
        ];
    }
}
