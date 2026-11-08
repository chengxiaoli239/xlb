<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SandboxPaymentProcessor
{
    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    public function simulate(string $orderId, string $userId): array
    {
        return DB::transaction(function () use ($orderId, $userId): array {
            $order = DB::table('orders')->where('id', $orderId)->where('user_id', $userId)->lockForUpdate()->first();
            if (! $order) {
                return $this->failure(404, 'not_found', '订单不存在');
            }
            if (! $order->is_test) {
                return $this->failure(409, 'invalid_transition', '当前订单不是本地测试订单');
            }

            $existing = DB::table('sandbox_payment_events')->where('order_id', $orderId)->first();
            if ($existing) {
                if ($existing->status === 'accepted_test') {
                    return $this->success($order, $existing, 200);
                }

                return $this->failure(409, 'payment_requires_review', '付款事件已进入待核验状态，不会自动开通权益');
            }

            $at = now()->utc();
            $expiredPending = $order->status === 'pending_test' && $order->expires_at
                && Carbon::parse($order->expires_at)->lessThanOrEqualTo($at);
            if ($order->status !== 'pending_test' && ! $expiredPending) {
                return $this->failure(409, 'invalid_transition', '当前订单状态不允许模拟付款');
            }

            $eventId = 'sandbox-event-'.$orderId;
            $transactionId = 'sandbox-txn-'.$orderId;
            if ($expiredPending) {
                $paymentId = (string) Str::uuid();
                DB::table('sandbox_payment_events')->insert([
                    'id' => $paymentId, 'event_id' => $eventId, 'transaction_id' => $transactionId, 'order_id' => $orderId,
                    'amount_cents' => $order->total_cents, 'currency' => 'CNY', 'status' => 'review_test',
                    'review_reason' => 'order_expired', 'created_at' => $at->toIso8601String(),
                ]);
                DB::table('audit_logs')->insert([
                    'id' => (string) Str::uuid(), 'actor_user_id' => $userId, 'action' => 'order.payment_review',
                    'target_type' => 'order', 'target_id' => $orderId, 'created_at' => $at->toIso8601String(),
                ]);

                return $this->failure(409, 'payment_requires_review', '订单已超时，事件已记入待核验，不会自动开通权益');
            }

            $changed = DB::table('orders')->where('id', $orderId)->where('status', 'pending_test')
                ->where('expires_at', '>', $at->toIso8601String())
                ->update(['status' => 'paid_test', 'updated_at' => $at->toIso8601String()]);
            if (! $changed) {
                return $this->failure(409, 'invalid_transition', '订单状态已变化，请刷新后重试');
            }

            $paymentId = (string) Str::uuid();
            DB::table('sandbox_payment_events')->insert([
                'id' => $paymentId, 'event_id' => $eventId, 'transaction_id' => $transactionId, 'order_id' => $orderId,
                'amount_cents' => $order->total_cents, 'currency' => 'CNY', 'status' => 'accepted_test',
                'review_reason' => null, 'created_at' => $at->toIso8601String(),
            ]);

            $plan = $order->plan_snapshot ? json_decode($order->plan_snapshot, true, flags: JSON_THROW_ON_ERROR) : (array) DB::table('plans')->where('id', $order->plan_id)->first();
            $startsAt = $this->nextEntitlementStart($userId, $at);
            $endsAt = $startsAt->copy()->addDays((int) $plan['duration_days']);
            $entitlementId = (string) Str::uuid();
            $grantId = (string) Str::uuid();
            DB::table('entitlements')->insert([
                'id' => $entitlementId, 'user_id' => $userId, 'plan_id' => $order->plan_id, 'status' => 'active',
                'starts_at' => $startsAt->toIso8601String(), 'ends_at' => $endsAt->toIso8601String(), 'data_used_gb' => 0,
            ]);
            DB::table('entitlement_grants')->insert([
                'id' => $grantId, 'order_id' => $orderId, 'entitlement_id' => $entitlementId, 'user_id' => $userId,
                'status' => 'granted_test', 'starts_at' => $startsAt->toIso8601String(), 'ends_at' => $endsAt->toIso8601String(),
                'created_at' => $at->toIso8601String(),
            ]);
            DB::table('order_events')->insert([
                'id' => (string) Str::uuid(), 'order_id' => $orderId, 'actor_user_id' => $userId,
                'from_status' => 'pending_test', 'to_status' => 'paid_test', 'created_at' => $at->toIso8601String(),
            ]);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'actor_user_id' => $userId, 'action' => 'order.sandbox_paid',
                'target_type' => 'order', 'target_id' => $orderId, 'created_at' => $at->toIso8601String(),
            ]);

            return $this->success(DB::table('orders')->where('id', $orderId)->first(), DB::table('sandbox_payment_events')->where('id', $paymentId)->first(), 201);
        }, 3);
    }

    private function nextEntitlementStart(string $userId, Carbon $now): Carbon
    {
        $latest = null;
        foreach (DB::table('entitlements')->where('user_id', $userId)->where('status', 'active')->pluck('ends_at') as $end) {
            $candidate = Carbon::parse($end);
            if ($candidate->greaterThan($now) && ($latest === null || $candidate->greaterThan($latest))) {
                $latest = $candidate;
            }
        }

        return $latest ?? $now->copy();
    }

    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    private function success(object $order, object $payment, int $status): array
    {
        $grant = DB::table('entitlement_grants')->where('order_id', $order->id)->first();

        return ['status' => $status, 'body' => ['data' => [
            'id' => $order->id, 'status' => $order->status, 'payment' => $payment, 'entitlement_grant' => $grant,
        ]]];
    }

    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    private function failure(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => ['code' => $code, 'message' => $message]]];
    }
}
