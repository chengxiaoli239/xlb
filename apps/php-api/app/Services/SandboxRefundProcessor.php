<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class SandboxRefundProcessor
{
    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    public function refund(string $orderId, string $adminId, string $idempotencyKey, string $reason): array
    {
        try {
            return DB::transaction(function () use ($orderId, $adminId, $idempotencyKey, $reason): array {
                $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
                if (! $order) {
                    return $this->failure(404, 'not_found', '订单不存在');
                }
                if (! $order->is_test) {
                    return $this->failure(409, 'test_only', '当前环境只允许处理本地测试订单');
                }

                $refund = DB::table('sandbox_refund_events')->where('order_id', $orderId)->first();
                if ($refund) {
                    return $this->success($orderId, $refund, 200);
                }

                $usedKey = DB::table('sandbox_refund_events')->where('idempotency_key', $idempotencyKey)->first();
                if ($usedKey) {
                    return $this->failure(409, 'idempotency_conflict', '此请求标识已用于其他退款');
                }

                $payment = DB::table('sandbox_payment_events')->where('order_id', $orderId)
                    ->where('status', 'accepted_test')->lockForUpdate()->first();
                $grant = DB::table('entitlement_grants')->where('order_id', $orderId)
                    ->where('status', 'granted_test')->lockForUpdate()->first();
                $entitlement = $grant ? DB::table('entitlements')->where('id', $grant->entitlement_id)
                    ->where('user_id', $order->user_id)->where('status', 'active')->lockForUpdate()->first() : null;

                if ($order->status !== 'paid_test' || ! $payment || ! $grant || ! $entitlement) {
                    return $this->failure(409, 'invalid_transition', '订单未完成沙箱付款，或对应权益已不可撤销');
                }

                $at = now()->utc()->toIso8601String();
                $changedOrder = DB::table('orders')->where('id', $orderId)->where('status', 'paid_test')
                    ->update(['status' => 'refunded_test', 'updated_at' => $at]);
                $changedEntitlement = DB::table('entitlements')->where('id', $entitlement->id)->where('status', 'active')
                    ->update(['status' => 'revoked_test']);
                $changedGrant = DB::table('entitlement_grants')->where('id', $grant->id)->where('status', 'granted_test')
                    ->update(['status' => 'revoked_test']);

                if ($changedOrder !== 1 || $changedEntitlement !== 1 || $changedGrant !== 1) {
                    throw new LogicException('退款状态已被其他请求修改，请刷新后重试');
                }

                $refundId = (string) Str::uuid();
                DB::table('sandbox_refund_events')->insert([
                    'id' => $refundId,
                    'order_id' => $orderId,
                    'payment_event_id' => $payment->id,
                    'idempotency_key' => $idempotencyKey,
                    'amount_cents' => $order->total_cents,
                    'currency' => $payment->currency,
                    'status' => 'refunded_test',
                    'reason' => $reason,
                    'actor_user_id' => $adminId,
                    'created_at' => $at,
                ]);
                DB::table('commission_entries')->where('order_id', $orderId)->where('status', 'pending')
                    ->update(['status' => 'reversed_test']);
                DB::table('order_events')->insert([
                    'id' => (string) Str::uuid(), 'order_id' => $orderId, 'actor_user_id' => $adminId,
                    'from_status' => 'paid_test', 'to_status' => 'refunded_test', 'created_at' => $at,
                ]);
                DB::table('audit_logs')->insert([
                    'id' => (string) Str::uuid(), 'actor_user_id' => $adminId, 'action' => 'order.sandbox_refunded',
                    'target_type' => 'order', 'target_id' => $orderId, 'created_at' => $at,
                ]);

                $hasRemainingEntitlement = DB::table('entitlements')->where('user_id', $order->user_id)->where('status', 'active')
                    ->where('ends_at', '>', $at)->exists();
                if (! $hasRemainingEntitlement) {
                    $subscriptions = DB::table('subscriptions')->where('user_id', $order->user_id)
                        ->where('status', 'active')->lockForUpdate()->get(['id']);
                    DB::table('subscriptions')->where('user_id', $order->user_id)->where('status', 'active')
                        ->update(['status' => 'revoked', 'token_encrypted' => null]);
                    foreach ($subscriptions as $subscription) {
                        DB::table('audit_logs')->insert([
                            'id' => (string) Str::uuid(), 'actor_user_id' => $adminId, 'action' => 'subscription.revoked_by_refund',
                            'target_type' => 'subscription', 'target_id' => $subscription->id, 'created_at' => $at,
                        ]);
                    }
                }

                return $this->success($orderId, DB::table('sandbox_refund_events')->where('id', $refundId)->first(), 201);
            }, 3);
        } catch (LogicException $exception) {
            return $this->failure(409, 'state_changed', $exception->getMessage());
        }
    }

    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    private function success(string $orderId, object $refund, int $status): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        $grant = DB::table('entitlement_grants')->where('order_id', $orderId)->first();

        return ['status' => $status, 'body' => ['data' => [
            'order_id' => $orderId,
            'status' => $order->status,
            'refund' => $refund,
            'entitlement_grant' => $grant,
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
