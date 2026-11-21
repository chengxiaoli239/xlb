<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AnonymousAccountController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'installation_id' => ['required', 'uuid'],
            'recovery_secret' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'consent' => ['required', 'boolean'],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', '请检查设备标识、恢复凭据和授权确认', 400);
        }

        if (! $request->boolean('consent')) {
            return $this->error('consent_required', '需要先确认匿名账号使用说明', 400);
        }

        $input = $validator->validated();
        $recoverySecretBytes = $this->decodeRecoverySecret($input['recovery_secret']);
        if ($recoverySecretBytes === null) {
            return $this->error('validation_error', '恢复凭据格式无效', 400);
        }

        $installationId = strtolower($input['installation_id']);
        $recoverySecretHash = hash('sha256', $recoverySecretBytes);
        try {
            $result = DB::transaction(function () use ($installationId, $recoverySecretHash): array {
                $existing = $this->existingAccount($installationId, $recoverySecretHash, true);
                if ($existing !== null) {
                    return $existing;
                }

                if (DB::table('member_devices')->where('installation_id', $installationId)->exists()) {
                    return ['error' => true];
                }

                $now = now()->utc()->toIso8601String();
                $userId = (string) Str::uuid();
                $user = User::query()->create([
                    'id' => $userId,
                    'name' => '匿名会员',
                    'email' => 'anon+'.$userId.'@anonymous.xlb.invalid',
                    'role' => 'member',
                    'status' => 'active',
                    'account_type' => 'anonymous',
                    'password' => Str::random(64),
                ]);

                DB::table('anonymous_accounts')->insert([
                    'installation_id' => $installationId,
                    'user_id' => $userId,
                    'recovery_secret_hash' => $recoverySecretHash,
                    'consent_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return ['user' => $user, 'created' => true];
            }, 3);
        } catch (QueryException $exception) {
            $result = $this->existingAccount($installationId, $recoverySecretHash);
            if ($result === null) {
                throw $exception;
            }
        }

        if (isset($result['error'])) {
            return $this->error('anonymous_registration_conflict', '无法使用此设备信息创建或恢复匿名账号', 409);
        }

        Auth::login($result['user']);
        $request->session()->regenerate();

        return response()->json([
            'data' => [
                'id' => $result['user']->id,
                'email' => null,
                'name' => $result['user']->name,
                'role' => $result['user']->role,
                'account_type' => $result['user']->account_type,
                'created' => $result['created'],
            ],
        ], $result['created'] ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function decodeRecoverySecret(string $secret): ?string
    {
        $decoded = base64_decode(strtr($secret, '-_', '+/').'=', true);
        if ($decoded === false || strlen($decoded) !== 32) {
            return null;
        }

        $canonical = rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');

        return hash_equals($canonical, $secret) ? $decoded : null;
    }

    /**
     * @return array{error: true}|array{user: User, created: false}|null
     */
    private function existingAccount(string $installationId, string $recoverySecretHash, bool $lock = false): ?array
    {
        $query = DB::table('anonymous_accounts')->where('installation_id', $installationId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $existing = $query->first();
        if (! $existing) {
            return null;
        }

        if (! hash_equals($existing->recovery_secret_hash, $recoverySecretHash)) {
            return ['error' => true];
        }

        $user = User::query()->find($existing->user_id);
        if (! $user || $user->account_type !== 'anonymous' || $user->status !== 'active') {
            return ['error' => true];
        }

        return ['user' => $user, 'created' => false];
    }
}
