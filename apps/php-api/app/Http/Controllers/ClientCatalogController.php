<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ClientCatalogController extends Controller
{
    private const PLATFORMS = ['android', 'ios', 'windows', 'macos', 'linux', 'browser'];

    private const COMPATIBILITY_STATUSES = ['pending', 'verified', 'maintenance', 'unsupported'];

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
        $attributes = $this->validatedItem($request);
        if ($attributes === null) {
            return $this->error('validation_error', '请检查客户端平台、名称、链接和状态', 400);
        }

        if (! DB::table('client_catalog')->where('id', $id)->exists()) {
            return $this->error('not_found', '客户端目录项不存在', 404);
        }

        DB::transaction(function () use ($attributes, $id, $request): void {
            DB::table('client_catalog')->where('id', $id)->update([...$attributes, 'updated_at' => now()]);
            $this->audit($request, 'client.updated', $id);
        });

        return response()->json(['data' => ['id' => $id]]);
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

    /** @return array<string, bool|int|string|null>|null */
    private function validatedItem(Request $request): ?array
    {
        $validator = Validator::make($request->all(), [
            'platform' => ['required', 'string', 'in:'.implode(',', self::PLATFORMS)],
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'version' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'download_url' => ['nullable', 'url', 'max:2048', 'regex:/^https:\/\/\S+$/i'],
            'qr_code_url' => ['nullable', 'url', 'max:2048', 'regex:/^https:\/\/\S+$/i'],
            'tutorial_url' => ['nullable', 'url', 'max:2048', 'regex:/^https:\/\/\S+$/i'],
            'compatibility_status' => ['required', 'string', 'in:'.implode(',', self::COMPATIBILITY_STATUSES)],
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
