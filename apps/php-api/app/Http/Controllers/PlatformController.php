<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformController extends Controller
{
    private const NODE_TRANSITIONS = [
        'candidate' => ['available', 'maintenance', 'isolated', 'retired'],
        'available' => ['draining', 'maintenance', 'isolated'],
        'draining' => ['available', 'maintenance', 'isolated', 'retired'],
        'maintenance' => ['candidate', 'isolated', 'retired'],
        'isolated' => ['maintenance', 'retired'],
        'retired' => [],
        'disabled' => ['maintenance', 'retired'],
    ];

    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'mode' => 'local-development']);
    }

    public function csrf(): JsonResponse
    {
        return response()->json(['data' => ['token' => csrf_token()]]);
    }

    public function session(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user() ? $this->publicUser($request) : null]);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['email' => ['required', 'email', 'max:190'], 'password' => ['required', 'string', 'max:200']]);
        if ($validator->fails()) {
            return $this->error('validation_error', '请检查邮箱和密码', 400);
        }

        if (! Auth::attempt(['email' => strtolower($request->string('email')->toString()), 'password' => $request->input('password'), 'status' => 'active'])) {
            return $this->error('invalid_credentials', '邮箱或密码不正确', 401);
        }

        $request->session()->regenerate();

        return response()->json(['data' => $this->publicUser($request)]);
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function catalog(): JsonResponse
    {
        $plans = DB::table('plans as p')->where('p.active', true)
            ->whereRaw('p.version = (SELECT MAX(v.version) FROM plans v WHERE v.family_id = p.family_id)')
            ->orderBy('p.duration_days')
            ->get(['p.id', 'p.name', 'p.period_label', 'p.duration_days', 'p.price_cents', 'p.traffic_gb', 'p.max_devices']);

        return response()->json(['data' => $plans]);
    }

    public function adminPlans(): JsonResponse
    {
        $plans = DB::table('plans as p')
            ->whereRaw('p.version = (SELECT MAX(v.version) FROM plans v WHERE v.family_id = p.family_id)')
            ->orderBy('p.duration_days')
            ->get(['p.id', 'p.family_id', 'p.version', 'p.name', 'p.period_label', 'p.duration_days', 'p.price_cents', 'p.traffic_gb', 'p.max_devices', 'p.active']);

        return response()->json(['data' => $plans]);
    }

    public function createPlan(Request $request): JsonResponse
    {
        $attributes = $this->validatedPlan($request);
        if ($attributes === null) {
            return $this->error('validation_error', '请检查套餐名称、价格和权益', 400);
        }

        $id = (string) Str::uuid();
        DB::table('plans')->insert([...$attributes, 'id' => $id, 'family_id' => $id, 'version' => 1, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'plan.created', 'plan', $id);

        return response()->json(['data' => ['id' => $id, 'family_id' => $id, 'version' => 1]], 201);
    }

    public function updatePlan(Request $request, string $family): JsonResponse
    {
        $attributes = $this->validatedPlan($request);
        if ($attributes === null) {
            return $this->error('validation_error', '请检查套餐名称、价格和权益', 400);
        }

        $result = DB::transaction(function () use ($request, $family, $attributes): ?array {
            $current = DB::table('plans')->where('family_id', $family)->orderByDesc('version')->lockForUpdate()->first();
            if (! $current) {
                return null;
            }

            $same = true;
            foreach ($attributes as $key => $value) {
                if ((string) $current->{$key} !== (string) $value) {
                    $same = false;
                }
            }
            if ($same) {
                return ['id' => $current->id, 'family_id' => $family, 'version' => $current->version];
            }

            $id = (string) Str::uuid();
            DB::table('plans')->where('id', $current->id)->update(['active' => false, 'updated_at' => now()]);
            DB::table('plans')->insert([...$attributes, 'id' => $id, 'family_id' => $family, 'version' => $current->version + 1, 'active' => $current->active, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($request, 'plan.version_created', 'plan', $id);

            return ['id' => $id, 'family_id' => $family, 'version' => $current->version + 1];
        });

        return $result ? response()->json(['data' => $result]) : $this->error('not_found', '套餐不存在', 404);
    }

    public function updatePlanStatus(Request $request, string $family): JsonResponse
    {
        $validator = Validator::make($request->all(), ['active' => ['required', 'boolean']]);
        if ($validator->fails()) {
            return $this->error('validation_error', '套餐状态无效', 400);
        }

        $current = DB::table('plans')->where('family_id', $family)->orderByDesc('version')->first();
        if (! $current) {
            return $this->error('not_found', '套餐不存在', 404);
        }

        $active = $request->boolean('active');
        if ((bool) $current->active !== $active) {
            DB::table('plans')->where('id', $current->id)->update(['active' => $active, 'updated_at' => now()]);
            $this->audit($request, 'plan.status_changed', 'plan', $current->id);
        }

        return response()->json(['data' => ['id' => $current->id, 'active' => $active]]);
    }

    public function overview(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $entitlement = DB::table('entitlements as e')->join('plans as p', 'p.id', '=', 'e.plan_id')
            ->where('e.user_id', $userId)->where('e.status', 'active')->where('e.ends_at', '>', now()->toIso8601String())
            ->orderByDesc('e.ends_at')->first(['e.id', 'e.status', 'e.starts_at', 'e.ends_at', 'e.data_used_gb', 'p.id as plan_id', 'p.name as plan_name', 'p.period_label', 'p.price_cents', 'p.traffic_gb', 'p.max_devices']);
        $subscriptions = DB::table('subscriptions')->where('user_id', $userId)->where('status', 'active')->where('expires_at', '>', now()->toIso8601String())->count();
        $orders = DB::table('orders as o')->join('plans as p', 'p.id', '=', 'o.plan_id')->where('o.user_id', $userId)->orderByDesc('o.created_at')->limit(5)->get(['o.id', 'o.total_cents', 'o.status', 'o.created_at', 'p.name as plan_name']);

        return response()->json(['data' => ['user' => $this->publicUser($request), 'entitlement' => $entitlement, 'activeSubscriptions' => $subscriptions, 'recentOrders' => $orders]]);
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = DB::table('orders as o')->join('plans as p', 'p.id', '=', 'o.plan_id')->where('o.user_id', $request->user()->id)->orderByDesc('o.created_at')->get(['o.id', 'p.name as plan_name', 'o.total_cents', 'o.status', 'o.created_at']);

        return response()->json(['data' => $orders]);
    }

    public function summary(): JsonResponse
    {
        $recentAudit = DB::table('audit_logs as a')->join('users as u', 'u.id', '=', 'a.actor_user_id')->orderByDesc('a.created_at')->limit(8)->get(['a.id', 'a.action', 'a.target_type', 'a.target_id', 'a.created_at', 'u.name as actor']);

        return response()->json(['data' => [
            'memberCount' => DB::table('users')->where('role', 'member')->count(),
            'activeEntitlements' => DB::table('entitlements')->where('status', 'active')->where('ends_at', '>', now()->toIso8601String())->count(),
            'activeSubscriptions' => DB::table('subscriptions')->where('status', 'active')->where('expires_at', '>', now()->toIso8601String())->count(),
            'availableNodes' => DB::table('nodes')->where('is_test', true)->where('status', 'available')->count(),
            'nodeCount' => DB::table('nodes')->where('is_test', true)->count(),
            'pendingCommissionCents' => (int) DB::table('commission_entries')->where('status', 'pending')->sum('amount_cents'),
            'recentAudit' => $recentAudit,
        ]]);
    }

    public function users(): JsonResponse
    {
        $users = DB::table('users as u')->leftJoin('entitlements as e', function ($join): void {
            $join->on('e.user_id', '=', 'u.id')->where('e.status', '=', 'active');
        })->leftJoin('plans as p', 'p.id', '=', 'e.plan_id')->where('u.role', 'member')->orderByDesc('u.created_at')
            ->get(['u.id', 'u.email', 'u.name', 'u.status', 'u.created_at', 'p.name as plan_name', 'e.ends_at', 'e.data_used_gb', 'p.traffic_gb']);

        return response()->json(['data' => $users]);
    }

    public function nodes(): JsonResponse
    {
        $nodes = DB::table('nodes')->orderBy('name')->get()->map(fn (object $node): array => $this->nodePayload($node));

        return response()->json(['data' => $nodes]);
    }

    public function nodeDetail(string $id): JsonResponse
    {
        $node = DB::table('nodes')->where('id', $id)->first();
        if (! $node) {
            return $this->error('not_found', '节点不存在', 404);
        }

        $changes = DB::table('node_state_changes as c')->join('users as u', 'u.id', '=', 'c.actor_user_id')
            ->where('c.node_id', $id)->orderByDesc('c.created_at')->limit(20)
            ->get(['c.id', 'c.from_status', 'c.to_status', 'c.created_at', 'u.name as actor_name']);

        return response()->json(['data' => [...$this->nodePayload($node), 'changes' => $changes]]);
    }

    public function createNode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:60', 'regex:/\S/u'],
            'region' => ['required', 'string', 'min:2', 'max:40', 'regex:/\S/u'],
            'endpoint_host' => ['prohibited'],
            'endpoint_port' => ['prohibited'],
            'protocol' => ['prohibited'],
            'status' => ['prohibited'],
            'is_test' => ['prohibited'],
            'max_sessions' => ['prohibited'],
            'active_sessions' => ['prohibited'],
            'latency_ms' => ['prohibited'],
            'health_status' => ['prohibited'],
            'config_version' => ['prohibited'],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', '请检查节点名称和测试区域', 400);
        }

        $name = trim($validator->validated()['name']);
        $region = trim($validator->validated()['region']);
        if (Str::length($name) < 2 || Str::length($region) < 2) {
            return $this->error('validation_error', '请检查节点名称和测试区域', 400);
        }

        $id = (string) Str::uuid();
        $now = now()->toIso8601String();
        DB::transaction(function () use ($request, $id, $now, $name, $region): void {
            DB::table('nodes')->insert([
                'id' => $id,
                'name' => $name,
                'region' => $region,
                'protocol' => 'SOCKS5',
                'endpoint_host' => 'node-'.substr(str_replace('-', '', $id), 0, 12).'.invalid',
                'endpoint_port' => 1080,
                'status' => 'candidate',
                'is_test' => true,
                'max_sessions' => 0,
                'active_sessions' => 0,
                'latency_ms' => null,
                'health_status' => 'not_connected',
                'last_health_check_at' => null,
                'config_version' => 1,
                'updated_at' => $now,
            ]);
            $this->audit($request, 'node.created', 'node', $id);
        });

        return response()->json(['data' => $this->nodePayload(DB::table('nodes')->where('id', $id)->first())], 201);
    }

    public function updateNode(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['status' => ['required', Rule::in(array_keys(self::NODE_TRANSITIONS))]]);
        if ($validator->fails()) {
            return $this->error('validation_error', '节点状态无效', 400);
        }

        $nextStatus = $validator->validated()['status'];
        $result = DB::transaction(function () use ($request, $id, $nextStatus): array {
            $node = DB::table('nodes')->where('id', $id)->lockForUpdate()->first();
            if (! $node) {
                return ['error' => ['code' => 'not_found', 'message' => '节点不存在', 'status' => 404]];
            }
            if (! $node->is_test) {
                return ['error' => ['code' => 'live_node_unsupported', 'message' => '本地控制台不能操作真实节点', 'status' => 409]];
            }
            if ($node->status === $nextStatus) {
                return ['data' => ['id' => $id, 'previous_status' => $node->status, 'status' => $nextStatus]];
            }
            if (! in_array($nextStatus, self::NODE_TRANSITIONS[$node->status] ?? [], true)) {
                return ['error' => ['code' => 'invalid_state_transition', 'message' => '不能直接切换到该生命周期状态', 'status' => 409]];
            }

            $now = now()->toISOString();
            DB::table('nodes')->where('id', $id)->update(['status' => $nextStatus, 'updated_at' => $now]);
            DB::table('node_state_changes')->insert([
                'id' => (string) Str::uuid(), 'node_id' => $id, 'actor_user_id' => $request->user()->id,
                'from_status' => $node->status, 'to_status' => $nextStatus, 'created_at' => $now,
            ]);
            $this->audit($request, 'node.lifecycle_changed', 'node', $id);

            return ['data' => ['id' => $id, 'previous_status' => $node->status, 'status' => $nextStatus]];
        });

        return isset($result['error'])
            ? $this->error($result['error']['code'], $result['error']['message'], $result['error']['status'])
            : response()->json(['data' => $result['data']]);
    }

    public function commissions(): JsonResponse
    {
        $rows = DB::table('commission_entries as c')->join('orders as o', 'o.id', '=', 'c.order_id')->orderByDesc('c.created_at')->get(['c.id', 'c.partner_name', 'c.amount_cents', 'c.status', 'c.created_at', 'o.id as order_id']);

        return response()->json(['data' => $rows]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $rows = DB::table('subscriptions')->where('user_id', $request->user()->id)->orderByDesc('created_at')->get(['id', 'name', 'status', 'created_at', 'expires_at', 'last_used_at']);

        return response()->json(['data' => $rows]);
    }

    public function createSubscription(Request $request): JsonResponse
    {
        $result = DB::transaction(function () use ($request): array {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $count = DB::table('subscriptions')->where('user_id', $request->user()->id)->where('status', 'active')->count();
            if ($count >= 3) {
                return ['error' => ['code' => 'subscription_limit', 'message' => '最多保留 3 个有效订阅'], 'status' => 409];
            }

            $entitlement = DB::table('entitlements')->where('user_id', $request->user()->id)->where('status', 'active')->where('ends_at', '>', now()->toIso8601String())->orderByDesc('ends_at')->first(['ends_at']);
            if (! $entitlement) {
                return ['error' => ['code' => 'membership_required', 'message' => '当前账户没有有效会员权益'], 'status' => 403];
            }

            $id = (string) Str::uuid();
            $secret = $this->newSubscriptionToken();
            $created = now()->toIso8601String();
            DB::table('subscriptions')->insert(['id' => $id, 'user_id' => $request->user()->id, 'name' => 'Clash 订阅', 'token_hash' => hash('sha256', $secret), 'status' => 'active', 'created_at' => $created, 'expires_at' => $entitlement->ends_at]);
            $this->audit($request, 'subscription.created', 'subscription', $id);

            return ['data' => ['id' => $id, 'name' => 'Clash 订阅', 'status' => 'active', 'created_at' => $created, 'expires_at' => $entitlement->ends_at, 'url' => '/subscribe/'.$secret], 'status' => 201];
        });

        return response()->json(isset($result['error']) ? ['error' => $result['error']] : ['data' => $result['data']], $result['status']);
    }

    public function rotateSubscription(Request $request, string $id): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $id): ?array {
            $current = DB::table('subscriptions')->where('id', $id)->where('user_id', $request->user()->id)->where('status', 'active')->lockForUpdate()->first(['id', 'expires_at']);
            if (! $current) {
                return null;
            }

            $newId = (string) Str::uuid();
            $secret = $this->newSubscriptionToken();
            $created = now()->toIso8601String();
            DB::table('subscriptions')->where('id', $id)->update(['status' => 'revoked']);
            DB::table('subscriptions')->insert(['id' => $newId, 'user_id' => $request->user()->id, 'name' => 'Clash 订阅', 'token_hash' => hash('sha256', $secret), 'status' => 'active', 'created_at' => $created, 'expires_at' => $current->expires_at]);
            $this->audit($request, 'subscription.rotated', 'subscription', $id);

            return ['id' => $newId, 'url' => '/subscribe/'.$secret, 'status' => 'active', 'created_at' => $created, 'expires_at' => $current->expires_at];
        });

        return $result ? response()->json(['data' => $result]) : $this->error('not_found', '订阅不存在或已撤销', 404);
    }

    public function revokeSubscription(Request $request, string $id): Response|JsonResponse
    {
        $changed = DB::table('subscriptions')->where('id', $id)->where('user_id', $request->user()->id)->where('status', 'active')->update(['status' => 'revoked']);
        if (! $changed) {
            return $this->error('not_found', '订阅不存在或已撤销', 404);
        }

        $this->audit($request, 'subscription.revoked', 'subscription', $id);

        return response()->noContent();
    }

    public function subscriptionProfile(string $token): Response
    {
        $record = DB::table('subscriptions')->where('token_hash', hash('sha256', $token))->first(['id', 'user_id', 'status', 'expires_at']);
        $now = now()->toIso8601String();
        $entitlement = $record ? DB::table('entitlements')->where('user_id', $record->user_id)->where('status', 'active')->where('ends_at', '>', $now)->exists() : false;
        $node = DB::table('nodes')->where('status', 'available')->where('is_test', true)->first(['name', 'endpoint_host', 'endpoint_port']);
        if (! $record || $record->status !== 'active' || $record->expires_at <= $now || ! $entitlement || ! $node) {
            return response('Subscription unavailable', 404)->header('Content-Type', 'text/plain')->header('Cache-Control', 'no-store');
        }

        DB::table('subscriptions')->where('id', $record->id)->update(['last_used_at' => $now]);
        $name = json_encode($node->name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $yaml = implode("\n", [
            '# 迅连宝本地开发配置，不包含真实节点或可用凭证。',
            '# 仅用于验证 Mihomo 订阅格式和导入流程。',
            'mixed-port: 7890',
            'allow-lan: false',
            'mode: rule',
            'log-level: warning',
            'proxies:',
            '  - name: '.$name,
            '    type: socks5',
            '    server: '.$node->endpoint_host,
            '    port: '.$node->endpoint_port,
            '    udp: false',
            'proxy-groups:',
            '  - name: 迅连宝测试',
            '    type: select',
            '    proxies: ['.$name.', DIRECT]',
            'rules:',
            '  - MATCH,迅连宝测试',
            '',
        ]);

        return response($yaml, 200)->header('Content-Type', 'text/yaml; charset=utf-8')->header('Cache-Control', 'no-store');
    }

    /** @return array<string, bool|int|string|array<string>|null> */
    private function nodePayload(object $node): array
    {
        return [
            'id' => $node->id,
            'name' => $node->name,
            'region' => $node->region,
            'protocol' => $node->protocol,
            'endpoint_host' => $node->endpoint_host,
            'endpoint_port' => (int) $node->endpoint_port,
            'status' => $node->status,
            'is_test' => (bool) $node->is_test,
            'max_sessions' => (int) $node->max_sessions,
            'active_sessions' => (int) $node->active_sessions,
            'latency_ms' => $node->latency_ms === null ? null : (int) $node->latency_ms,
            'health_status' => $node->health_status,
            'last_health_check_at' => $node->last_health_check_at,
            'config_version' => (int) $node->config_version,
            'updated_at' => $node->updated_at,
            'allowed_transitions' => $node->is_test ? (self::NODE_TRANSITIONS[$node->status] ?? []) : [],
        ];
    }

    private function newSubscriptionToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @return array<string, int|float|string>|null */
    private function validatedPlan(Request $request): ?array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'period_label' => ['required', 'string', 'min:1', 'max:30'],
            'duration_days' => ['required', 'integer', 'between:1,3650'],
            'price_cents' => ['required', 'integer', 'between:0,99999900'],
            'traffic_gb' => ['required', 'numeric', 'between:1,100000'],
            'max_devices' => ['required', 'integer', 'between:1,100'],
        ]);

        return $validator->fails() ? null : $validator->validated();
    }

    /** @return array{id: string, email: string, name: string, role: string} */
    private function publicUser(Request $request): array
    {
        $user = $request->user();

        return ['id' => $user->id, 'email' => $user->email, 'name' => $user->name, 'role' => $user->role];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function audit(Request $request, string $action, string $type, string $target): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'actor_user_id' => $request->user()->id, 'action' => $action, 'target_type' => $type, 'target_id' => $target, 'created_at' => now()->toIso8601String()]);
    }
}
