<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ClientCatalogController extends Controller
{
    private const PLATFORMS = ['android', 'ios', 'windows', 'macos', 'linux', 'browser'];

    private const COMPATIBILITY_STATUSES = ['pending', 'verified', 'maintenance', 'unsupported'];

    private const REQUIRED_COMPATIBILITY_CHECKS = [
        'profile_parse', 'subscription_import', 'subscription_refresh', 'mode_switch', 'tcp_connect', 'disconnect', 'expiry_revoke', 'dns',
    ];

    private const COMPATIBILITY_CHECKS = [...self::REQUIRED_COMPATIBILITY_CHECKS, 'udp'];

    public function memberIndex(): JsonResponse
    {
        $items = DB::table('client_catalog')->where('is_active', true)
            ->orderBy('platform')->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'platform', 'name', 'version', 'description', 'download_url', 'qr_code_url', 'tutorial_url', 'compatibility_status']);

        return response()->json(['data' => $items]);
    }

    public function adminIndex(): JsonResponse
    {
        $items = DB::table('client_catalog')->orderBy('platform')->orderBy('sort_order')->orderBy('name')->get()
            ->map(static function (object $item): object {
                $item->is_active = (bool) $item->is_active;
                $item->sort_order = (int) $item->sort_order;

                return $item;
            });

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validatedItem($request);
        if ($attributes === null) {
            return $this->error('validation_error', '请检查客户端平台、名称、链接和状态', 400);
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($attributes, $id, $request): void {
            DB::table('client_catalog')->insert([...$attributes, 'id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($request, 'client.created', $id);
        });

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $current = DB::table('client_catalog')->where('id', $id)->lockForUpdate()->first(['id', 'version', 'compatibility_status']);
            if (! $current) {
                return $this->error('not_found', '客户端目录项不存在', 404);
            }

            $attributes = $this->validatedItem($request, $current->compatibility_status === 'verified');
            if ($attributes === null) {
                return $this->error('validation_error', '请检查客户端平台、名称、链接和状态', 400);
            }

            if ($attributes['version'] !== $current->version) {
                $attributes['compatibility_status'] = 'pending';
            }

            DB::table('client_catalog')->where('id', $id)->update([...$attributes, 'updated_at' => now()]);
            $this->audit($request, 'client.updated', $id);

            return response()->json(['data' => ['id' => $id]]);
        });
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['is_active' => ['required', 'boolean']]);
        if ($validator->fails()) {
            return $this->error('validation_error', '客户端上架状态无效', 400);
        }

        $item = DB::table('client_catalog')->where('id', $id)->first(['id', 'is_active']);
        if (! $item) {
            return $this->error('not_found', '客户端目录项不存在', 404);
        }

        $active = $request->boolean('is_active');
        if ((bool) $item->is_active !== $active) {
            DB::transaction(function () use ($active, $id, $request): void {
                DB::table('client_catalog')->where('id', $id)->update(['is_active' => $active, 'updated_at' => now()]);
                $this->audit($request, $active ? 'client.published' : 'client.unpublished', $id);
            });
        }

        return response()->json(['data' => ['id' => $id, 'is_active' => $active]]);
    }

    public function compatibilityRuns(string $id): JsonResponse
    {
        if (! DB::table('client_catalog')->where('id', $id)->exists()) {
            return $this->error('not_found', '客户端目录项不存在', 404);
        }

        $runs = DB::table('client_compatibility_runs as runs')
            ->join('users', 'users.id', '=', 'runs.tester_user_id')
            ->where('runs.client_id', $id)
            ->orderByDesc('runs.recorded_order')->orderByDesc('runs.tested_at')->limit(30)
            ->get([
                'runs.id', 'runs.client_id', 'runs.client_version', 'runs.core_name', 'runs.core_version', 'runs.os_version',
                'runs.profile_format', 'runs.network_type', 'runs.udp_supported', 'runs.checks', 'runs.result',
                'runs.matches_current_version', 'runs.evidence_reference', 'runs.notes', 'runs.tested_at', 'users.name as tester_name',
            ])
            ->map(static function (object $run): object {
                $run->checks = json_decode($run->checks, true, 512, JSON_THROW_ON_ERROR);
                $run->udp_supported = (bool) $run->udp_supported;
                $run->matches_current_version = (bool) $run->matches_current_version;

                return $run;
            });

        return response()->json(['data' => $runs]);
    }

    public function storeCompatibilityRun(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_version' => ['required', 'string', 'max:40'],
            'core_name' => ['required', 'string', 'max:80'],
            'core_version' => ['required', 'string', 'max:40'],
            'os_version' => ['required', 'string', 'max:100'],
            'profile_format' => ['required', 'string', 'in:clash,mihomo'],
            'network_type' => ['required', 'string', 'in:wifi,ethernet,cellular,other'],
            'udp_supported' => ['required', 'boolean'],
            'checks' => ['required', 'array:'.implode(',', self::COMPATIBILITY_CHECKS)],
            'checks.profile_parse' => ['required', 'boolean'],
            'checks.subscription_import' => ['required', 'boolean'],
            'checks.subscription_refresh' => ['required', 'boolean'],
            'checks.mode_switch' => ['required', 'boolean'],
            'checks.tcp_connect' => ['required', 'boolean'],
            'checks.disconnect' => ['required', 'boolean'],
            'checks.expiry_revoke' => ['required', 'boolean'],
            'checks.dns' => ['required', 'boolean'],
            'checks.udp' => ['nullable', 'boolean', 'required_if:udp_supported,true'],
            'evidence_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/'],
            'tested_at' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', '请填写完整的客户端版本、内核版本和验收结果', 400);
        }

        $attributes = $validator->validated();
        $checks = array_map(static fn (mixed $passed): bool => filter_var($passed, FILTER_VALIDATE_BOOLEAN), $attributes['checks']);
        $requiredChecks = self::REQUIRED_COMPATIBILITY_CHECKS;
        if ($attributes['udp_supported']) {
            $requiredChecks[] = 'udp';
        }
        $allChecksPassed = collect($requiredChecks)->every(static fn (string $check): bool => ($checks[$check] ?? false) === true);
        $runId = (string) Str::uuid();
        $testedAt = Carbon::parse($attributes['tested_at']);

        $run = DB::transaction(function () use ($attributes, $checks, $allChecksPassed, $runId, $testedAt, $id, $request): ?array {
            $client = DB::table('client_catalog')->where('id', $id)->lockForUpdate()->first(['id', 'version', 'compatibility_status']);
            if (! $client) {
                return null;
            }

            $matchesCurrentVersion = $client->version !== null && trim($attributes['client_version']) === $client->version;
            $result = ! $matchesCurrentVersion ? 'stale' : ($allChecksPassed ? 'passed' : 'failed');
            $recordedOrder = (int) (DB::table('client_compatibility_runs')->where('client_id', $id)->max('recorded_order') ?? 0) + 1;
            DB::table('client_compatibility_runs')->insert([
                'id' => $runId,
                'client_id' => $id,
                'recorded_order' => $recordedOrder,
                'client_version' => trim($attributes['client_version']),
                'core_name' => trim($attributes['core_name']),
                'core_version' => trim($attributes['core_version']),
                'os_version' => trim($attributes['os_version']),
                'profile_format' => $attributes['profile_format'],
                'network_type' => $attributes['network_type'],
                'udp_supported' => $attributes['udp_supported'],
                'checks' => json_encode($checks, JSON_THROW_ON_ERROR),
                'result' => $result,
                'matches_current_version' => $matchesCurrentVersion,
                'evidence_reference' => $attributes['evidence_reference'],
                'notes' => $attributes['notes'] ?? null,
                'tester_user_id' => $request->user()->id,
                'tested_at' => $testedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'client.compatibility_run_recorded', $id);

            if ($result === 'failed' && $client->compatibility_status === 'verified') {
                DB::table('client_catalog')->where('id', $id)->update(['compatibility_status' => 'maintenance', 'updated_at' => now()]);
                $this->audit($request, 'client.compatibility_failed', $id);
            }

            return ['result' => $result, 'matches_current_version' => $matchesCurrentVersion];
        });

        if ($run === null) {
            return $this->error('not_found', '客户端目录项不存在', 404);
        }

        return response()->json(['data' => [
            'id' => $runId,
            'client_version' => trim($attributes['client_version']),
            'result' => $run['result'],
            'matches_current_version' => $run['matches_current_version'],
            'tested_at' => $testedAt->toIso8601String(),
        ]], 201);
    }

    public function updateCompatibilityStatus(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:'.implode(',', self::COMPATIBILITY_STATUSES)],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', '客户端兼容状态无效', 400);
        }

        $status = $validator->validated()['status'];

        return DB::transaction(function () use ($status, $id, $request): JsonResponse {
            $client = DB::table('client_catalog')->where('id', $id)->lockForUpdate()->first(['id', 'version', 'compatibility_status']);
            if (! $client) {
                return $this->error('not_found', '客户端目录项不存在', 404);
            }

            if ($client->compatibility_status === $status) {
                return response()->json(['data' => ['id' => $id, 'compatibility_status' => $status]]);
            }

            if ($status === 'verified') {
                $latestRun = DB::table('client_compatibility_runs')->where('client_id', $id)
                    ->orderByDesc('recorded_order')
                    ->first(['client_version', 'matches_current_version', 'result', 'created_at']);
                $hasCurrentPassingRun = $latestRun
                    && $latestRun->client_version === $client->version
                    && (bool) $latestRun->matches_current_version
                    && $latestRun->result === 'passed';

                if (! $hasCurrentPassingRun) {
                    return $this->error('verification_evidence_required', '当前版本的最新验收记录未通过或需要重新测试', 409);
                }
            }

            if ($client->compatibility_status !== $status) {
                DB::table('client_catalog')->where('id', $id)->update(['compatibility_status' => $status, 'updated_at' => now()]);
                $this->audit($request, $status === 'verified' ? 'client.compatibility_verified' : 'client.compatibility_status_changed', $id);
            }

            return response()->json(['data' => ['id' => $id, 'compatibility_status' => $status]]);
        });
    }

    /** @return array<string, bool|int|string|null>|null */
    private function validatedItem(Request $request, bool $allowExistingVerified = false): ?array
    {
        $statuses = array_values(array_filter(self::COMPATIBILITY_STATUSES, static fn (string $status): bool => $status !== 'verified' || $allowExistingVerified));
        $validator = Validator::make($request->all(), [
            'platform' => ['required', 'string', 'in:'.implode(',', self::PLATFORMS)],
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'version' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'download_url' => ['nullable', 'url', 'max:2048', 'regex:/^https:\/\/\S+$/i'],
            'qr_code_url' => ['nullable', 'url', 'max:2048', 'regex:/^https:\/\/\S+$/i'],
            'tutorial_url' => ['nullable', 'url', 'max:2048', 'regex:/^https:\/\/\S+$/i'],
            'compatibility_status' => ['required', 'string', 'in:'.implode(',', $statuses)],
            'sort_order' => ['required', 'integer', 'between:0,100000'],
            'is_active' => ['required', 'boolean'],
        ]);

        return $validator->fails() ? null : $validator->validated();
    }

    private function audit(Request $request, string $action, string $target): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'actor_user_id' => $request->user()->id,
            'action' => $action,
            'target_type' => 'client_catalog',
            'target_id' => $target,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
