<?php

namespace App\Http\Controllers;

use App\Services\NodePeerProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenSSLAsymmetricKey;

class DeviceBindingController extends Controller
{
    public function challenge(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'installation_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:1', 'max:80', 'regex:/\S/u'],
            'platform' => ['required', 'string', Rule::in(['android', 'ios', 'windows', 'macos', 'linux'])],
            'public_key' => ['required', 'string', 'max:256', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', '请检查设备绑定信息', 400);
        }

        $input = $validator->validated();
        $der = $this->decode($input['public_key']);
        if ($der === null || strlen($der) > 160 || ! $this->validPublicKey($der)) {
            return $this->error('validation_error', '设备公钥无效', 400);
        }

        $userId = $request->user()->id;
        $installationId = strtolower($input['installation_id']);
        $fingerprint = hash('sha256', $der);
        $result = DB::transaction(function () use ($userId, $installationId, $input, $fingerprint): array {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            if (! $this->activeEntitlement($userId)) {
                return ['error' => ['code' => 'active_entitlement_required', 'message' => '需要有效会员权益才能授权设备']];
            }
            $device = DB::table('member_devices')->where('user_id', $userId)
                ->where('installation_id', $installationId)->first();
            if ($device && ($device->status !== 'registered' || $device->remove_requested_at !== null)) {
                return ['error' => ['code' => 'device_registration_revoked', 'message' => '该设备已移除']];
            }
            if ($device && $device->public_key_fingerprint !== null
                && ! hash_equals($device->public_key_fingerprint, $fingerprint)) {
                return ['error' => ['code' => 'device_key_mismatch', 'message' => '设备密钥与已登记设备不一致']];
            }

            $id = (string) Str::uuid();
            $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            DB::table('device_binding_challenges')->insert([
                'id' => $id, 'user_id' => $userId, 'installation_id' => $installationId,
                'name' => trim($input['name']), 'platform' => $input['platform'],
                'public_key' => $input['public_key'], 'public_key_fingerprint' => $fingerprint,
                'challenge' => $challenge, 'expires_at' => now()->addMinutes(5)->utc()->toIso8601String(),
                'used_at' => null, 'created_at' => now()->utc()->toIso8601String(),
            ]);

            return ['data' => ['challenge_id' => $id, 'challenge' => $challenge]];
        }, 3);

        return isset($result['error'])
            ? $this->error($result['error']['code'], $result['error']['message'], 409)
            : response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    public function authorize(Request $request, NodePeerProvisioningService $provisioning): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'challenge_id' => ['required', 'uuid'],
            'signature' => ['required', 'string', 'max:256', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', '设备签名无效', 400);
        }
        $input = $validator->validated();
        $signature = $this->decode($input['signature']);
        if ($signature === null) {
            return $this->error('validation_error', '设备签名无效', 400);
        }

        $userId = $request->user()->id;
        $result = DB::transaction(function () use ($userId, $input, $signature): array {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $challenge = DB::table('device_binding_challenges')->where('id', $input['challenge_id'])
                ->where('user_id', $userId)->lockForUpdate()->first();
            if (! $challenge) {
                return ['error' => ['code' => 'device_binding_challenge_invalid', 'message' => '设备验证请求不存在']];
            }
            if ($challenge->used_at !== null) {
                return ['error' => ['code' => 'device_binding_challenge_used', 'message' => '设备验证请求已使用']];
            }
            if ($challenge->expires_at <= now()->utc()->toIso8601String()) {
                return ['error' => ['code' => 'device_binding_challenge_expired', 'message' => '设备验证请求已过期']];
            }
            $publicKey = $this->publicKey($this->decode($challenge->public_key));
            $challengeBytes = $this->decode($challenge->challenge);
            if (! $publicKey || $challengeBytes === null
                || openssl_verify($challengeBytes, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
                return ['error' => ['code' => 'device_binding_proof_invalid', 'message' => '设备密钥验证失败']];
            }
            $entitlement = $this->activeEntitlement($userId);
            if (! $entitlement) {
                return ['error' => ['code' => 'active_entitlement_required', 'message' => '需要有效会员权益才能授权设备']];
            }
            $device = DB::table('member_devices')->where('user_id', $userId)
                ->where('installation_id', $challenge->installation_id)->lockForUpdate()->first();
            if ($device && ($device->status !== 'registered' || $device->remove_requested_at !== null)) {
                return ['error' => ['code' => 'device_registration_revoked', 'message' => '该设备已移除']];
            }
            if ($device && $device->public_key_fingerprint !== null
                && ! hash_equals($device->public_key_fingerprint, $challenge->public_key_fingerprint)) {
                return ['error' => ['code' => 'device_key_mismatch', 'message' => '设备密钥与已登记设备不一致']];
            }
            $created = ! $device;
            if ($created) {
                $count = DB::table('member_devices')->where('user_id', $userId)->where('status', 'registered')->count();
                if ($count >= (int) $entitlement->max_devices) {
                    return ['error' => ['code' => 'device_limit_reached', 'message' => '设备名额已满']];
                }
            }
            $now = now()->utc()->toIso8601String();
            $id = $device?->id ?? (string) Str::uuid();
            if ($created) {
                DB::table('member_devices')->insert([
                    'id' => $id, 'user_id' => $userId, 'installation_id' => $challenge->installation_id,
                    'name' => $challenge->name, 'platform' => $challenge->platform,
                    'status' => 'registered', 'revoked_at' => null, 'created_at' => $now, 'updated_at' => $now,
                    'public_key' => $challenge->public_key, 'public_key_fingerprint' => $challenge->public_key_fingerprint,
                    'bound_at' => $now,
                ]);
            } elseif ($device->public_key_fingerprint === null) {
                DB::table('member_devices')->where('id', $id)->update([
                    'public_key' => $challenge->public_key,
                    'public_key_fingerprint' => $challenge->public_key_fingerprint,
                    'bound_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('device_binding_challenges')->where('id', $challenge->id)->update(['used_at' => $now]);
            if ($created) {
                $this->audit($userId, $id);
            }

            return [
                'device' => ['id' => $id, 'status' => 'registered', 'bound' => true],
                'proof_fingerprint' => $challenge->public_key_fingerprint,
                'created' => $created,
            ];
        }, 3);
        if (isset($result['error'])) {
            $status = $result['error']['code'] === 'device_binding_proof_invalid' ? 401 : 409;

            return $this->error($result['error']['code'], $result['error']['message'], $status);
        }

        $requestCorrelationId = $request->attributes->get('request_id');
        $nodeAuthorization = $provisioning->ensureForProvenDevice(
            $userId,
            $result['device']['id'],
            $result['proof_fingerprint'],
            is_string($requestCorrelationId) ? $requestCorrelationId : null,
        );

        return response()->json(['data' => [
            'device' => $result['device'],
            'node_authorization' => $nodeAuthorization,
        ]], $result['created'] ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }

    private function activeEntitlement(string $userId): ?object
    {
        $now = now()->utc()->toIso8601String();

        return DB::table('entitlements as e')->join('plans as p', 'p.id', '=', 'e.plan_id')
            ->join('users as u', 'u.id', '=', 'e.user_id')->where('u.status', 'active')
            ->where('e.user_id', $userId)->where('e.status', 'active')
            ->where('e.starts_at', '<=', $now)->where('e.ends_at', '>', $now)
            ->whereColumn('e.data_used_gb', '<', 'p.traffic_gb')
            ->orderByDesc('e.ends_at')->first(['e.id', 'p.max_devices']);
    }

    private function decode(?string $value): ?string
    {
        if ($value === null || $value === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return null;
        }
        $padding = strlen($value) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding === 0 ? 0 : 4 - $padding), '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private function publicKey(?string $der): OpenSSLAsymmetricKey|false
    {
        if ($der === null) {
            return false;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64)."-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem);
    }

    private function validPublicKey(string $der): bool
    {
        $publicKey = $this->publicKey($der);
        if (! $publicKey) {
            return false;
        }
        $details = openssl_pkey_get_details($publicKey);

        return is_array($details) && $details['type'] === OPENSSL_KEYTYPE_EC
            && ($details['ec']['curve_name'] ?? null) === 'prime256v1';
    }

    private function audit(string $userId, string $deviceId): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'actor_user_id' => $userId,
            'action' => 'member_device.bound', 'target_type' => 'member_device',
            'target_id' => $deviceId, 'created_at' => now()->utc()->toIso8601String(),
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
