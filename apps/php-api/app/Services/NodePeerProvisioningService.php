<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NodePeerProvisioningService
{
    public function __construct(
        private NodePeerProvisioningDriver $driver,
        private SubscriptionRouteService $routes,
    ) {}

    /**
     * Call only after the authenticated device has completed its challenge signature.
     *
     * @return array{status:string,message:string,peer_id?:string,node_id?:string,peer_generation?:int,device_binding_generation?:int,bridge_endpoint?:string,bridge_server_ca_pem?:string,peer_credential?:string,profile?:string}
     */
    public function ensureForProvenDevice(string $userId, string $deviceId, string $proofFingerprint): array
    {
        if (! config('node_access.provisioning_enabled')
            || $this->driver instanceof DisabledNodePeerProvisioningDriver) {
            return $this->unavailable();
        }

        $device = DB::table('member_devices')->where('id', $deviceId)->where('user_id', $userId)
            ->where('status', 'registered')->whereNull('remove_requested_at')->first();
        if (! $device || ! $device->public_key || ! $device->public_key_fingerprint
            || ! hash_equals((string) $device->public_key_fingerprint, $proofFingerprint)
            || ! $this->activeEntitlement($userId)) {
            return $this->unavailable();
        }

        $existing = DB::table('node_access_peers')->where('device_id', $deviceId)
            ->whereIn('status', ['provisioning', 'active', 'revocation_pending'])->orderByDesc('created_at')->first();
        if ($existing) {
            return $this->present($existing, $userId, $proofFingerprint);
        }

        $route = $this->routes->resolve($userId, 'production');
        if (! $route) {
            return $this->unavailable();
        }

        $candidateIds = array_map(static fn (object $node): string => (string) $node->id, $route['nodes']);
        if ($candidateIds === []) {
            return $this->unavailable();
        }

        $reserved = DB::transaction(function () use ($userId, $deviceId, $device, $proofFingerprint, $candidateIds): ?object {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $memberDevice = DB::table('member_devices')->where('id', $deviceId)->where('user_id', $userId)
                ->where('status', 'registered')->whereNull('remove_requested_at')->lockForUpdate()->first();
            $entitlement = $this->activeEntitlement($userId);
            if (! $memberDevice || ! $entitlement
                || ! hash_equals((string) $memberDevice->public_key_fingerprint, (string) $device->public_key_fingerprint)
                || ! hash_equals((string) $memberDevice->public_key_fingerprint, $proofFingerprint)) {
                return null;
            }

            $existing = DB::table('node_access_peers')->where('device_id', $deviceId)
                ->whereIn('status', ['provisioning', 'active', 'revocation_pending'])->orderByDesc('created_at')->first();
            if ($existing) {
                return $existing;
            }

            $at = now()->utc()->toIso8601String();
            $node = DB::table('nodes')->whereIn('id', $candidateIds)->where('is_test', false)
                ->where('status', 'available')->where('health_status', 'healthy')
                ->whereNotNull('agent_endpoint')->where('agent_endpoint', '!=', '')
                ->whereRaw('active_sessions < max_sessions')->orderBy('active_sessions')->lockForUpdate()->first();
            if (! $node || ! $this->freshHealthCheck($node->last_health_check_at)) {
                return null;
            }

            $peerId = (string) Str::uuid();
            $requestId = (string) Str::uuid();
            $credential = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            DB::table('node_access_peers')->insert([
                'id' => $peerId, 'user_id' => $userId, 'node_id' => $node->id,
                'device_id' => $deviceId, 'entitlement_id' => $entitlement->id, 'device_name' => $memberDevice->name,
                'public_key' => $memberDevice->public_key, 'device_key_fingerprint' => $memberDevice->public_key_fingerprint,
                'generation' => 1, 'device_binding_generation' => (int) $memberDevice->binding_generation,
                'credential_ciphertext' => Crypt::encryptString($credential),
                'profile_ciphertext' => null, 'provision_request_id' => $requestId,
                'provision_acknowledgement_id' => null, 'provision_confirmation_status' => null,
                'expires_at' => $entitlement->ends_at, 'status' => 'provisioning',
                'upload_bytes' => 0, 'download_bytes' => 0, 'last_observed_at' => null,
                'revoked_at' => null, 'created_at' => $at, 'updated_at' => $at,
            ]);
            DB::table('node_access_provisioning_outbox')->insert([
                'id' => $requestId, 'peer_id' => $peerId, 'status' => 'pending', 'generation' => 1,
                'attempts' => 0, 'next_attempt_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
            DB::table('nodes')->where('id', $node->id)->increment('active_sessions', 1, ['updated_at' => $at]);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'actor_user_id' => $userId,
                'action' => 'node.peer_provision_requested', 'target_type' => 'node_access_peer',
                'target_id' => $peerId, 'created_at' => $at,
            ]);

            return DB::table('node_access_peers')->where('id', $peerId)->first();
        }, 3);

        return $reserved ? $this->present($reserved, $userId, $proofFingerprint) : $this->unavailable();
    }

    private function activeEntitlement(string $userId, ?string $entitlementId = null): ?object
    {
        $now = now()->utc()->toIso8601String();
        $query = DB::table('entitlements as e')->join('plans as p', 'p.id', '=', 'e.plan_id')
            ->join('users as u', 'u.id', '=', 'e.user_id')->where('u.status', 'active')
            ->where('e.user_id', $userId)->where('e.status', 'active')
            ->where('e.starts_at', '<=', $now)->where('e.ends_at', '>', $now)
            ->whereColumn('e.data_used_gb', '<', 'p.traffic_gb')
            ->orderByDesc('e.ends_at');
        if ($entitlementId !== null) {
            $query->where('e.id', $entitlementId);
        }

        return $query->first(['e.id', 'e.ends_at']);
    }

    private function activePeerEntitlement(object $peer, string $userId): bool
    {
        if (! $peer->entitlement_id || ! $peer->expires_at) {
            return false;
        }

        try {
            if (! Carbon::parse($peer->expires_at)->isAfter(now())) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return $this->activeEntitlement($userId, (string) $peer->entitlement_id) !== null;
    }

    /**
     * @return array{status:string,message:string,peer_id?:string,node_id?:string,peer_generation?:int,device_binding_generation?:int,bridge_endpoint?:string,bridge_server_ca_pem?:string,peer_credential?:string,profile?:string}
     */
    private function present(object $peer, string $userId, string $proofFingerprint): array
    {
        $device = DB::table('member_devices')->where('id', $peer->device_id)->where('user_id', $userId)
            ->where('status', 'registered')->whereNull('remove_requested_at')->first();
        if (! $device || ! $device->public_key_fingerprint || ! $this->activePeerEntitlement($peer, $userId)
            || ! hash_equals((string) $device->public_key_fingerprint, $proofFingerprint)
            || ! hash_equals((string) $peer->device_key_fingerprint, $proofFingerprint)
            || (int) $peer->device_binding_generation !== (int) $device->binding_generation) {
            return $this->unavailable();
        }

        if ($peer->status === 'provisioning') {
            return ['status' => 'provisioning', 'message' => '正在准备设备线路'];
        }
        $node = DB::table('nodes')->where('id', $peer->node_id)->first([
            'is_test', 'status', 'health_status', 'last_health_check_at', 'agent_endpoint',
        ]);
        if (! $node || (bool) $node->is_test || $node->status !== 'available' || $node->health_status !== 'healthy'
            || ! $this->freshHealthCheck($node->last_health_check_at)
            || $peer->status !== 'active' || $peer->provision_confirmation_status !== 'confirmed'
            || ! $peer->credential_ciphertext || ! $peer->profile_ciphertext
            || ! $peer->bridge_endpoint || ! $peer->bridge_server_ca_ciphertext
            || ! $this->hasSupportedProfileArtifactMetadata($peer)) {
            return $this->unavailable();
        }

        try {
            $credential = Crypt::decryptString($peer->credential_ciphertext);
            $profile = Crypt::decryptString($peer->profile_ciphertext);
            $bridgeServerCa = Crypt::decryptString($peer->bridge_server_ca_ciphertext);
        } catch (DecryptException) {
            return $this->unavailable();
        }
        if (! hash_equals((string) $peer->profile_sha256, hash('sha256', $profile))
            || ! NodeAccessAgentAcknowledgement::isApprovedMemberBridgeDescriptor(
                $peer->bridge_endpoint, $bridgeServerCa, $node,
            )) {
            return $this->unavailable();
        }

        return [
            'status' => 'ready',
            'message' => '设备线路已就绪',
            'peer_id' => (string) $peer->id,
            'node_id' => (string) $peer->node_id,
            'peer_generation' => (int) $peer->generation,
            'device_binding_generation' => (int) $peer->device_binding_generation,
            'bridge_endpoint' => (string) $peer->bridge_endpoint,
            'bridge_server_ca_pem' => $bridgeServerCa,
            'peer_credential' => $credential,
            'profile' => $profile,
        ];
    }

    private function hasSupportedProfileArtifactMetadata(object $peer): bool
    {
        $expectedRenderer = [
            'mihomo-yaml' => 'xlb-mihomo-bridge-v1',
            'xlb-client-manifest-v1' => 'xlb-native-bridge-v1',
        ];

        return isset($expectedRenderer[$peer->profile_format ?? ''])
            && $expectedRenderer[$peer->profile_format] === ($peer->profile_renderer_id ?? null)
            && preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', (string) ($peer->profile_renderer_version ?? '')) === 1
            && preg_match('/\A[a-f0-9]{64}\z/', (string) ($peer->profile_sha256 ?? '')) === 1;
    }

    private function freshHealthCheck(?string $lastCheck): bool
    {
        if (! $lastCheck) {
            return false;
        }

        try {
            return Carbon::parse($lastCheck)->greaterThanOrEqualTo(
                now()->subSeconds(max(1, (int) config('subscription_routes.health_check_ttl_seconds', 300)))
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{status:string,message:string} */
    private function unavailable(): array
    {
        return ['status' => 'unavailable', 'message' => '服务线路暂不可用'];
    }
}
