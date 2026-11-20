<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionCredentialService
{
    public function __construct(
        private NodePeerRevocationRequestService $revocations,
        private NodePeerSubscriptionBindingRequestService $subscriptionBindings,
    ) {}

    /**
     * @return array{id:string,name:string,status:string,created_at:string,expires_at:?string,last_used_at:?string,url:?string,created:bool}
     */
    public function ensure(string $userId): array
    {
        return DB::transaction(function () use ($userId): array {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $subscription = DB::table('subscriptions')->where('user_id', $userId)->where('status', 'active')
                ->orderByDesc('created_at')->lockForUpdate()->first();

            if ($subscription) {
                $result = $this->present($subscription);
                if ($result['url'] !== null) {
                    return [...$result, 'created' => false];
                }

                $secret = $this->newToken();
                $encrypted = Crypt::encryptString($secret);
                DB::table('subscriptions')->where('id', $subscription->id)->update([
                    'token_hash' => hash('sha256', $secret),
                    'token_encrypted' => $encrypted,
                ]);
                $this->audit($userId, 'subscription.credential_reissued', $subscription->id);

                return [...$this->present((object) [...(array) $subscription, 'token_encrypted' => $encrypted]), 'created' => false];
            }

            $id = (string) Str::uuid();
            $secret = $this->newToken();
            $createdAt = now()->toIso8601String();
            DB::table('subscriptions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'name' => 'Clash 订阅',
                'token_hash' => hash('sha256', $secret),
                'token_encrypted' => Crypt::encryptString($secret),
                'status' => 'active',
                'created_at' => $createdAt,
                'expires_at' => null,
            ]);
            $this->audit($userId, 'subscription.created', $id);

            return [
                'id' => $id,
                'name' => 'Clash 订阅',
                'status' => 'active',
                'created_at' => $createdAt,
                'expires_at' => null,
                'last_used_at' => null,
                'url' => '/subscribe/'.$secret,
                'created' => true,
            ];
        }, 3);
    }

    /**
     * @return array{subscription:array<string,mixed>,created:bool}
     */
    public function ensureForDevice(string $userId, string $deviceId): array
    {
        $this->assertDeviceCredentialScope();

        return DB::transaction(function () use ($userId, $deviceId): array {
            $device = $this->eligibleDevice($userId, $deviceId);
            $peer = $this->activePeerForDevice($userId, $device);
            $subscription = DB::table('subscriptions')->where('user_id', $userId)
                ->where('member_device_id', $deviceId)->lockForUpdate()->first();

            if ($subscription && $subscription->status === 'active' && $subscription->token_encrypted) {
                $this->assertSubscriptionPeerBinding($subscription, $peer, $device);
                $this->subscriptionBindings->request($subscription, $peer);

                return [
                    'subscription' => $this->present((object) [...(array) $subscription, 'device_name' => $device->name]),
                    'created' => false,
                ];
            }

            $secret = $this->newToken();
            $now = now()->utc()->toIso8601String();
            if ($subscription) {
                DB::table('subscriptions')->where('id', $subscription->id)->update([
                    'name' => $device->name.' · Clash / Mihomo',
                    'token_hash' => hash('sha256', $secret),
                    'token_encrypted' => Crypt::encryptString($secret),
                    'status' => 'active',
                    'credential_generation' => (int) $subscription->credential_generation + 1,
                    'node_access_peer_id' => $peer->id,
                    'node_access_peer_generation' => (int) $peer->generation,
                    'member_device_generation' => (int) $device->binding_generation,
                    'rotated_at' => $now,
                ]);
                $this->audit($userId, 'subscription.device_credential_reissued', $subscription->id);
                $id = $subscription->id;
            } else {
                $id = (string) Str::uuid();
                DB::table('subscriptions')->insert([
                    'id' => $id,
                    'user_id' => $userId,
                    'member_device_id' => $deviceId,
                    'node_access_peer_id' => $peer->id,
                    'node_access_peer_generation' => (int) $peer->generation,
                    'member_device_generation' => (int) $device->binding_generation,
                    'name' => $device->name.' · Clash / Mihomo',
                    'token_hash' => hash('sha256', $secret),
                    'token_encrypted' => Crypt::encryptString($secret),
                    'status' => 'active',
                    'credential_generation' => 1,
                    'rotated_at' => null,
                    'created_at' => $now,
                    'expires_at' => null,
                    'last_used_at' => null,
                ]);
                $this->audit($userId, 'subscription.device_credential_created', $id);
            }

            $issued = DB::table('subscriptions')->where('id', $id)->first();
            $this->subscriptionBindings->request($issued, $peer);

            return [
                'subscription' => $this->present((object) [...(array) $issued, 'device_name' => $device->name]),
                'created' => $subscription === null,
            ];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function rotateForDevice(string $userId, string $deviceId): array
    {
        $this->assertDeviceCredentialScope();

        return DB::transaction(function () use ($userId, $deviceId): array {
            $device = $this->eligibleDevice($userId, $deviceId);
            $peer = $this->activePeerForDevice($userId, $device);
            $subscription = DB::table('subscriptions')->where('user_id', $userId)
                ->where('member_device_id', $deviceId)->where('status', 'active')->lockForUpdate()->first();
            if (! $subscription) {
                throw new MemberDeviceSubscriptionException('not_found', '该设备没有可轮换的订阅凭证', 404);
            }
            $this->assertSubscriptionPeerBinding($subscription, $peer, $device);

            $secret = $this->newToken();
            $now = now()->utc()->toIso8601String();
            $generation = (int) $subscription->credential_generation + 1;
            DB::table('subscriptions')->where('id', $subscription->id)->update([
                'token_hash' => hash('sha256', $secret),
                'token_encrypted' => Crypt::encryptString($secret),
                'credential_generation' => $generation,
                'node_access_peer_id' => $peer->id,
                'node_access_peer_generation' => (int) $peer->generation,
                'member_device_generation' => (int) $device->binding_generation,
                'rotated_at' => $now,
            ]);
            $this->audit($userId, 'subscription.device_credential_rotated', $subscription->id);
            $rotated = DB::table('subscriptions')->where('id', $subscription->id)->first();
            $this->subscriptionBindings->request($rotated, $peer);

            return [...$this->present((object) [...(array) $rotated, 'device_name' => $device->name]), 'requires_reimport' => true];
        }, 3);
    }

    public function revokeForDevice(string $userId, string $deviceId): bool
    {
        $this->assertDeviceCredentialScope();

        return DB::transaction(function () use ($userId, $deviceId): bool {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $subscription = DB::table('subscriptions')->where('user_id', $userId)
                ->where('member_device_id', $deviceId)->lockForUpdate()->first();
            if (! $subscription) {
                throw new MemberDeviceSubscriptionException('not_found', '该设备没有订阅凭证', 404);
            }

            if ($subscription->status === 'active') {
                DB::table('subscriptions')->where('id', $subscription->id)->update([
                    'status' => 'revoked', 'token_encrypted' => null,
                ]);
                $this->audit($userId, 'subscription.device_credential_revoked', $subscription->id);
            }

            $peer = $subscription->node_access_peer_id
                ? DB::table('node_access_peers')->where('id', $subscription->node_access_peer_id)->lockForUpdate()->first()
                : null;
            if ($peer && $peer->status !== 'revoked') {
                $this->revocations->request($peer, 'subscription_revoked');

                return true;
            }

            return false;
        }, 3);
    }

    public function current(string $userId): ?array
    {
        $subscription = DB::table('subscriptions')->where('user_id', $userId)->where('status', 'active')
            ->orderByDesc('created_at')->first();

        return $subscription ? $this->present($subscription) : null;
    }

    public function present(object $subscription, bool $includeUrl = true): array
    {
        $bindingStatus = null;
        if (($subscription->member_device_id ?? null) !== null) {
            $binding = DB::table('node_access_subscription_binding_outbox')
                ->where('subscription_id', $subscription->id)
                ->where('generation', (int) ($subscription->credential_generation ?? 1))
                ->first(['status']);
            $bindingStatus = match ($binding?->status) {
                'confirmed', 'confirmed_test' => 'confirmed',
                'pending', 'processing', 'retry' => 'pending',
                default => 'unavailable',
            };
            $includeUrl = $includeUrl && $bindingStatus === 'confirmed';
        }

        $url = null;
        if ($includeUrl && $subscription->status === 'active' && $subscription->token_encrypted) {
            try {
                $url = '/subscribe/'.Crypt::decryptString($subscription->token_encrypted);
            } catch (DecryptException) {
                $url = null;
            }
        }

        return [
            'id' => $subscription->id,
            'name' => $subscription->name,
            'status' => $subscription->status,
            'created_at' => $subscription->created_at,
            'expires_at' => $subscription->expires_at,
            'last_used_at' => $subscription->last_used_at,
            'url' => $url,
            'device_id' => $subscription->member_device_id ?? null,
            'device_name' => $subscription->device_name ?? null,
            'credential_generation' => (int) ($subscription->credential_generation ?? 1),
            'rotated_at' => $subscription->rotated_at ?? null,
            'requires_reimport' => false,
            'node_binding_status' => $bindingStatus,
        ];
    }

    /**
     * @return array{id:string,name:string,status:string,created_at:string,expires_at:?string,last_used_at:?string,url:string}|null
     */
    public function rotate(string $userId, string $subscriptionId): ?array
    {
        return DB::transaction(function () use ($userId, $subscriptionId): ?array {
            $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->where('user_id', $userId)
                ->where('status', 'active')->lockForUpdate()->first();
            if (! $subscription) {
                return null;
            }

            $secret = $this->newToken();
            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'token_hash' => hash('sha256', $secret),
                'token_encrypted' => Crypt::encryptString($secret),
            ]);
            $this->audit($userId, 'subscription.rotated', $subscriptionId);

            return [
                'id' => $subscriptionId,
                'name' => $subscription->name,
                'status' => 'active',
                'created_at' => $subscription->created_at,
                'expires_at' => $subscription->expires_at,
                'last_used_at' => $subscription->last_used_at,
                'url' => '/subscribe/'.$secret,
            ];
        }, 3);
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function assertDeviceCredentialScope(): void
    {
        if (! app()->environment(['local', 'testing']) || config('subscription_routes.scope') !== 'test') {
            throw new MemberDeviceSubscriptionException(
                'third_party_device_binding_unavailable',
                '第三方客户端尚无可验证的设备绑定，当前不能签发生产订阅凭证',
                409,
            );
        }
    }

    private function eligibleDevice(string $userId, string $deviceId): object
    {
        $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
        $device = DB::table('member_devices')->where('id', $deviceId)->where('user_id', $userId)
            ->where('status', 'registered')->whereNull('remove_requested_at')->lockForUpdate()->first();
        if (! $device) {
            throw new MemberDeviceSubscriptionException('not_found', '设备不存在或正在移除', 404);
        }
        if (! $device->public_key || ! $device->public_key_fingerprint || ! $device->bound_at) {
            throw new MemberDeviceSubscriptionException('device_binding_required', '请先完成此设备的密钥绑定', 409);
        }
        if (! $user || $user->role !== 'member' || $user->status !== 'active') {
            throw new MemberDeviceSubscriptionException('active_entitlement_required', '需要有效会员权益', 409);
        }

        $entitlement = DB::table('entitlements as e')->join('plans as p', 'p.id', '=', 'e.plan_id')
            ->where('e.user_id', $userId)->where('e.status', 'active')
            ->where('e.starts_at', '<=', now()->utc()->toIso8601String())
            ->where('e.ends_at', '>', now()->utc()->toIso8601String())
            ->whereColumn('e.data_used_gb', '<', 'p.traffic_gb')
            ->orderByDesc('e.ends_at')->first(['p.max_devices']);
        if (! $entitlement) {
            throw new MemberDeviceSubscriptionException('active_entitlement_required', '需要有效会员权益', 409);
        }

        $registeredDevices = DB::table('member_devices')->where('user_id', $userId)->where('status', 'registered')->count();
        if ($registeredDevices > (int) $entitlement->max_devices) {
            throw new MemberDeviceSubscriptionException('device_limit_reached', '登记设备已超过当前套餐上限，请先移除多余设备', 409);
        }

        return $device;
    }

    private function activePeerForDevice(string $userId, object $device): object
    {
        $peer = DB::table('node_access_peers as peer')->join('nodes as node', 'node.id', '=', 'peer.node_id')
            ->join('entitlements as peer_entitlement', 'peer_entitlement.id', '=', 'peer.entitlement_id')
            ->join('plans as peer_plan', 'peer_plan.id', '=', 'peer_entitlement.plan_id')
            ->where('peer.user_id', $userId)->where('peer.device_id', $device->id)
            ->where('peer.status', 'active')->whereNotNull('peer.credential_ciphertext')
            ->whereNotNull('peer.profile_ciphertext')->whereNotNull('peer.profile_sha256')
            ->where('peer_entitlement.user_id', $userId)->where('peer_entitlement.status', 'active')
            ->where('peer_entitlement.starts_at', '<=', now()->utc()->toIso8601String())
            ->where('peer_entitlement.ends_at', '>', now()->utc()->toIso8601String())
            ->whereColumn('peer_entitlement.data_used_gb', '<', 'peer_plan.traffic_gb')
            ->whereNotNull('peer.expires_at')->where('peer.expires_at', '>', now()->utc()->toIso8601String())
            ->where('node.status', 'available')
            ->where('peer.device_binding_generation', (int) $device->binding_generation)
            ->where('peer.device_key_fingerprint', $device->public_key_fingerprint)
            ->where(function ($query): void {
                if (app()->environment(['local', 'testing']) && config('subscription_routes.scope') === 'test') {
                    $query->where('node.is_test', true)->where('node.health_status', 'verified_test')
                        ->where('peer.provision_confirmation_status', 'confirmed_test')
                        ->where('peer.profile_format', 'sandbox-only')
                        ->where('peer.profile_renderer_id', 'isolated-test-agent');

                    return;
                }

                $query->where('node.is_test', false)->where('node.health_status', 'healthy')
                    ->where('peer.provision_confirmation_status', 'confirmed')
                    ->whereIn('peer.profile_format', ['mihomo-yaml', 'xlb-client-manifest-v1'])
                    ->whereIn('peer.profile_renderer_id', ['xlb-mihomo-bridge-v1', 'xlb-native-bridge-v1']);
            })->orderByDesc('peer.created_at')->first([
                'peer.id', 'peer.node_id', 'peer.device_id', 'peer.user_id', 'peer.generation', 'peer.status',
                'peer.provision_confirmation_status', 'peer.device_key_fingerprint', 'peer.device_binding_generation',
                'peer.credential_ciphertext', 'peer.profile_ciphertext', 'peer.profile_sha256',
                'peer.profile_format', 'peer.profile_renderer_id', 'node.is_test',
            ]);
        if (! $peer || ! $this->hasVerifiedProfileArtifact($peer)) {
            throw new MemberDeviceSubscriptionException('device_peer_unavailable', '此设备尚无已确认的专属节点授权', 409);
        }

        return $peer;
    }

    private function hasVerifiedProfileArtifact(object $peer): bool
    {
        try {
            $profile = Crypt::decryptString((string) $peer->profile_ciphertext);
        } catch (DecryptException) {
            return false;
        }

        return $profile !== '' && strlen($profile) <= 131072
            && is_string($peer->profile_sha256)
            && hash_equals($peer->profile_sha256, hash('sha256', $profile));
    }

    private function assertSubscriptionPeerBinding(object $subscription, object $peer, object $device): void
    {
        if (($subscription->node_access_peer_id ?? null) !== $peer->id
            || (int) ($subscription->node_access_peer_generation ?? 0) !== (int) $peer->generation
            || (int) ($subscription->member_device_generation ?? 0) !== (int) $device->binding_generation) {
            throw new MemberDeviceSubscriptionException('device_peer_binding_stale', '设备节点授权已变化，请重新授权后再获取订阅', 409);
        }
    }

    private function audit(string $userId, string $action, string $subscriptionId): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'actor_user_id' => $userId,
            'action' => $action,
            'target_type' => 'subscription',
            'target_id' => $subscriptionId,
            'created_at' => now()->toIso8601String(),
        ]);
    }
}
