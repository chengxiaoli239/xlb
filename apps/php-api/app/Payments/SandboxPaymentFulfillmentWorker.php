<?php

namespace App\Payments;

use App\Services\SubscriptionCredentialService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class SandboxPaymentFulfillmentWorker
{
    public function __construct(
        private readonly PersistentPaymentEventLedger $ledger,
        private readonly SubscriptionCredentialService $credentials,
    ) {}

    public function process(string $merchantOrderId): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Sandbox payment fulfillment is restricted to local and testing environments.');
        }

        try {
            return DB::transaction(function () use ($merchantOrderId): bool {
                $paymentOrder = DB::table('provider_payment_orders')->where('merchant_order_id', $merchantOrderId)->lockForUpdate()->first();
                $fulfillment = DB::table('provider_payment_fulfillments')->where('merchant_order_id', $merchantOrderId)->lockForUpdate()->first();
                $order = DB::table('orders')->where('id', $merchantOrderId)->where('is_test', true)->lockForUpdate()->first();
                if (! $paymentOrder || ! $fulfillment || ! $order) {
                    throw new LogicException('Sandbox payment fulfillment is not registered.');
                }
                if ($fulfillment->status === 'fulfilled') {
                    return true;
                }
                if ($paymentOrder->status !== 'paid_pending_grant' || $order->status !== 'pending_test') {
                    throw new LogicException('Sandbox order is not awaiting payment fulfillment.');
                }

                $plan = $order->plan_snapshot ? json_decode($order->plan_snapshot, true, flags: JSON_THROW_ON_ERROR) : (array) DB::table('plans')->where('id', $order->plan_id)->first();
                $at = now()->utc();
                $startsAt = $this->nextEntitlementStart($order->user_id, $at);
                $endsAt = $startsAt->copy()->addDays((int) $plan['duration_days']);
                $entitlementId = (string) Str::uuid();
                $grantId = (string) Str::uuid();

                DB::table('entitlements')->insert([
                    'id' => $entitlementId,
                    'user_id' => $order->user_id,
                    'plan_id' => $order->plan_id,
                    'status' => 'active',
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'data_used_gb' => 0,
                ]);
                DB::table('entitlement_grants')->insert([
                    'id' => $grantId,
                    'order_id' => $merchantOrderId,
                    'entitlement_id' => $entitlementId,
                    'user_id' => $order->user_id,
                    'status' => 'granted_test',
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'created_at' => $at->toIso8601String(),
                ]);
                DB::table('orders')->where('id', $merchantOrderId)->where('status', 'pending_test')->update([
                    'status' => 'paid_test', 'updated_at' => $at->toIso8601String(),
                ]);
                DB::table('order_events')->insert([
                    'id' => (string) Str::uuid(),
                    'order_id' => $merchantOrderId,
                    'actor_user_id' => null,
                    'from_status' => 'pending_test',
                    'to_status' => 'paid_test',
                    'created_at' => $at->toIso8601String(),
                ]);
                DB::table('audit_logs')->insert([
                    'id' => (string) Str::uuid(),
                    'actor_user_id' => $order->user_id,
                    'action' => 'order.provider_sandbox_fulfilled',
                    'target_type' => 'order',
                    'target_id' => $merchantOrderId,
                    'created_at' => $at->toIso8601String(),
                ]);
                $this->credentials->ensure($order->user_id);
                $this->ledger->recordFulfillmentAttempt($merchantOrderId, true);

                return true;
            }, 3);
        } catch (Throwable) {
            $this->ledger->recordFulfillmentAttempt($merchantOrderId, false, 'sandbox_fulfillment_failed');

            return false;
        }
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
}
