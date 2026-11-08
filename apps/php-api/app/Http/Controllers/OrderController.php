<?php

namespace App\Http\Controllers;

use App\Services\TestOrderLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function listMember(Request $request): JsonResponse
    {
        return $this->listOrders($request, false);
    }

    public function listAdmin(Request $request): JsonResponse
    {
        return $this->listOrders($request, true);
    }

    public function detail(Request $request, string $id): JsonResponse
    {
        $order = DB::table('orders')->where('id', $id);
        if (! $request->is('api/v1/admin/*')) {
            $order->where('user_id', $request->user()->id);
        }
        $row = $order->first();
        if (! $row) {
            return $this->error('not_found', '订单不存在', 404);
        }
        $plan = $row->plan_snapshot ? json_decode($row->plan_snapshot, true) : (array) DB::table('plans')->where('id', $row->plan_id)->first();
        $user = DB::table('users')->where('id', $row->user_id)->first(['name', 'email']);
        $events = DB::table('order_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')->where('e.order_id', $id)->orderBy('e.sequence')
            ->get(['e.id', 'e.from_status', 'e.to_status', 'e.created_at', 'u.name as actor_name']);
        $payment = DB::table('sandbox_payment_events')->where('order_id', $id)->first();
        $entitlementGrant = DB::table('entitlement_grants')->where('order_id', $id)->first();

        return response()->json(['data' => [
            'id' => $row->id, 'plan_id' => $row->plan_id, 'plan_name' => $plan['name'], 'plan_snapshot' => $plan,
            'total_cents' => $row->total_cents, 'status' => TestOrderLifecycle::effectiveStatus($row), 'is_test' => (bool) $row->is_test,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at, 'expires_at' => $row->expires_at,
            'user_name' => $user->name, 'user_email' => $user->email, 'events' => $events,
            'payment' => $payment, 'entitlement_grant' => $entitlementGrant,
        ]]);
    }

    public function create(Request $request, TestOrderLifecycle $lifecycle): JsonResponse
    {
        if (! app()->environment(['local', 'testing']) || $request->user()->role !== 'member') {
            return $this->error('test_only', '仅本地会员可创建测试订单', 403);
        }
        $validator = Validator::make([...$request->all(), 'idempotency_key' => $request->header('Idempotency-Key')], [
            'plan_id' => ['required', 'string', 'max:255'], 'idempotency_key' => ['required', 'uuid'],
            'total_cents' => ['prohibited'], 'status' => ['prohibited'], 'is_test' => ['prohibited'], 'plan_snapshot' => ['prohibited'],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', '请提交套餐编号及有效的请求标识，金额由服务端计算', 400);
        }

        return DB::transaction(function () use ($request, $lifecycle): JsonResponse {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $existing = DB::table('orders')->where('user_id', $request->user()->id)->where('idempotency_key', strtolower($request->header('Idempotency-Key')))->first();
            if ($existing) {
                if ($existing->plan_id !== $request->input('plan_id')) {
                    return $this->error('idempotency_conflict', '此请求标识已用于其他套餐', 409);
                }

                return $this->detail($request, $existing->id);
            }
            $plan = DB::table('plans as p')->where('p.id', $request->input('plan_id'))->where('p.active', true)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('plans as newer')->whereColumn('newer.family_id', 'p.family_id')->whereColumn('newer.version', '>', 'p.version');
                })->lockForUpdate()->first();
            if (! $plan) {
                return $this->error('plan_unavailable', '套餐已变更或下架，请刷新套餐列表', 409);
            }
            $id = (string) Str::uuid();
            $at = now()->utc();
            $snapshot = array_intersect_key((array) $plan, array_flip(['id', 'family_id', 'version', 'name', 'period_label', 'duration_days', 'price_cents', 'traffic_gb', 'max_devices']));
            DB::table('orders')->insert([
                'id' => $id, 'user_id' => $request->user()->id, 'plan_id' => $plan->id, 'total_cents' => $plan->price_cents,
                'status' => 'pending_test', 'is_test' => true, 'idempotency_key' => strtolower($request->header('Idempotency-Key')),
                'plan_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'expires_at' => $at->copy()->addMinutes(30)->toIso8601String(),
                'created_at' => $at->toIso8601String(), 'updated_at' => $at->toIso8601String(),
            ]);
            $lifecycle->record($id, $request->user()->id, null, 'pending_test', $at->toIso8601String());

            return $this->detail($request, $id)->setStatusCode(201)->header('Location', '/api/v1/me/orders/'.$id);
        }, 3);
    }

    public function cancel(Request $request, string $id, TestOrderLifecycle $lifecycle): JsonResponse
    {
        if (! app()->environment(['local', 'testing']) || $request->user()->role !== 'member') {
            return $this->error('test_only', '仅本地会员可取消自己的测试订单', 403);
        }

        return DB::transaction(function () use ($request, $id, $lifecycle): JsonResponse {
            $order = DB::table('orders')->where('id', $id)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (! $order) {
                return $this->error('not_found', '订单不存在', 404);
            }
            if (! $order->is_test || ! in_array(TestOrderLifecycle::effectiveStatus($order), ['pending_test', 'cancelled_test'], true)) {
                return $this->error('invalid_transition', '当前订单状态不允许取消', 409);
            }
            if ($order->status === 'pending_test') {
                $at = now()->utc()->toIso8601String();
                $changed = DB::table('orders')->where('id', $id)->where('status', 'pending_test')->where('expires_at', '>', $at)
                    ->update(['status' => 'cancelled_test', 'updated_at' => $at]);
                if (! $changed) {
                    return $this->error('invalid_transition', '订单状态已变更，请刷新', 409);
                }
                $lifecycle->record($id, $request->user()->id, 'pending_test', 'cancelled_test', $at);
            }

            return $this->detail($request, $id);
        }, 3);
    }

    private function listOrders(Request $request, bool $admin): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'status' => ['sometimes', 'in:'.implode(',', TestOrderLifecycle::STATUSES)],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', '分页或状态筛选参数无效', 400);
        }
        $at = now()->utc()->toIso8601String();
        $query = DB::table('orders as o')->join('plans as p', 'p.id', '=', 'o.plan_id')->join('users as u', 'u.id', '=', 'o.user_id');
        if (! $admin) {
            $query->where('o.user_id', $request->user()->id);
        }
        $effective = "CASE WHEN o.status = 'pending_test' AND o.expires_at <= ? THEN 'expired_test' ELSE o.status END";
        if ($request->filled('status')) {
            $query->whereRaw('('.$effective.') = ?', [$at, $request->query('status')]);
        }
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('o.created_at')->orderByDesc('o.id')->offset(($page - 1) * $perPage)->limit($perPage)
            ->select(['o.id', 'p.name as plan_name', 'o.total_cents', 'o.created_at', 'o.expires_at', 'u.name as user_name'])
            ->selectRaw($effective.' as status', [$at])->get();

        return response()->json(['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => (int) ceil($total / $perPage)]]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
