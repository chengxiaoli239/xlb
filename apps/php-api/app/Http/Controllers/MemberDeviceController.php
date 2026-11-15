<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MemberDeviceController extends Controller
{
    private const PLATFORMS = ['android', 'ios', 'windows', 'macos', 'linux', 'browser', 'other'];

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $now = now()->utc()->toIso8601String();
        $entitlement = $this->activeEntitlement($userId, $now);
        $devices = DB::table('member_devices')
            ->where('user_id', $userId)
            ->where('status', 'registered')
            ->orderByDesc('created_at')
            ->get(['id', 'installation_id', 'name', 'platform', 'status', 'created_at', 'revoked_at'])
            ->map(fn (object $device): array => $this->present($device))
            ->all();
        $registeredCount = count($devices);
        $maxDevices = $entitlement ? (int) $entitlement->max_devices : 0;

        return response()->json(['data' => [
            'entitlement_active' => $entitlement !== null,
            'max_devices' => $maxDevices,
            'registered_count' => $registeredCount,
            'available_slots' => max(0, $maxDevices - $registeredCount),
            'devices' => $devices,
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'installation_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:1', 'max:80', 'regex:/\S/u'],
            'platform' => ['required', 'string', Rule::in(self::PLATFORMS)],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', '请检查设备标识、名称和平台', 400);
        }

        $validated = $validator->validated();
        $userId = $request->user()->id;
        $installationId = strtolower($validated['installation_id']);
        $name = trim($validated['name']);
        $platform = $validated['platform'];
        if ($name === '') {
            return $this->error('validation_error', '设备名称不能为空', 400);
        }

        $result = DB::transaction(function () use ($userId, $installationId, $name, $platform): array {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();

            $existing = DB::table('member_devices')
                ->where('user_id', $userId)
                ->where('installation_id', $installationId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ($existing->status === 'registered') {
                    return ['device' => $this->present($existing), 'created' => false];
                }

                return ['error' => ['code' => 'device_registration_revoked', 'message' => '该设备登记已撤销，请在重新安装后使用新的设备标识']];
            }

            $entitlement = $this->activeEntitlement($userId, now()->utc()->toIso8601String());
            if (! $entitlement) {
                return ['error' => ['code' => 'active_entitlement_required', 'message' => '需要有效会员权益才能登记设备']];
            }

            $registeredCount = DB::table('member_devices')
                ->where('user_id', $userId)
                ->where('status', 'registered')
                ->count();
            if ($registeredCount >= (int) $entitlement->max_devices) {
                return ['error' => ['code' => 'device_limit_reached', 'message' => '已达到套餐设备数上限，请先移除不再使用的设备']];
            }

            $id = (string) Str::uuid();
            $createdAt = now()->utc()->toIso8601String();
            DB::table('member_devices')->insert([
                'id' => $id,
                'user_id' => $userId,
                'installation_id' => $installationId,
                'name' => $name,
                'platform' => $platform,
                'status' => 'registered',
                'revoked_at' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $this->audit($userId, 'member_device.registered', $id);

            return ['device' => [
                'id' => $id,
                'installation_id' => $installationId,
                'name' => $name,
                'platform' => $platform,
                'status' => 'registered',
                'created_at' => $createdAt,
                'revoked_at' => null,
            ], 'created' => true];
        }, 3);

        if (isset($result['error'])) {
            return $this->error($result['error']['code'], $result['error']['message'], 409);
        }

        return response()->json(['data' => $result['device']], $result['created'] ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, string $id): Response|JsonResponse
    {
        $userId = $request->user()->id;
        $error = DB::transaction(function () use ($userId, $id): ?array {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();

            $device = DB::table('member_devices')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->where('status', 'registered')
                ->lockForUpdate()
                ->first();
            if (! $device) {
                return ['code' => 'not_found', 'message' => '设备不存在或已移除', 'status' => 404];
            }

            $pendingPeer = DB::table('node_access_peers')
                ->where('user_id', $userId)
                ->where('device_id', $id)
                ->where('status', '!=', 'revoked')
                ->exists();
            if ($pendingPeer) {
                return ['code' => 'device_peer_revocation_pending', 'message' => '设备仍关联未确认撤权的节点凭证，暂不能移除', 'status' => 409];
            }

            $revokedAt = now()->utc()->toIso8601String();
            DB::table('member_devices')->where('id', $id)->update([
                'status' => 'revoked',
                'revoked_at' => $revokedAt,
                'updated_at' => $revokedAt,
            ]);
            $this->audit($userId, 'member_device.revoked', $id);

            return null;
        }, 3);

        if ($error) {
            return $this->error($error['code'], $error['message'], $error['status']);
        }

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    private function activeEntitlement(string $userId, string $now): ?object
    {
        return DB::table('entitlements as e')
            ->join('plans as p', 'p.id', '=', 'e.plan_id')
            ->where('e.user_id', $userId)
            ->where('e.status', 'active')
            ->where('e.starts_at', '<=', $now)
            ->where('e.ends_at', '>', $now)
            ->orderByDesc('e.ends_at')
            ->first(['e.id', 'e.ends_at', 'p.max_devices']);
    }

    /** @return array{id:string,installation_id:string,name:string,platform:string,status:string,created_at:string,revoked_at:?string} */
    private function present(object $device): array
    {
        return [
            'id' => $device->id,
            'installation_id' => $device->installation_id,
            'name' => $device->name,
            'platform' => $device->platform,
            'status' => $device->status,
            'created_at' => $device->created_at,
            'revoked_at' => $device->revoked_at,
        ];
    }

    private function audit(string $userId, string $action, string $deviceId): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'actor_user_id' => $userId,
            'action' => $action,
            'target_type' => 'member_device',
            'target_id' => $deviceId,
            'created_at' => now()->utc()->toIso8601String(),
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
