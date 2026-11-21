<?php

namespace App\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class PersistentPaymentEventLedger
{
    public function __construct(private readonly PaymentEventPolicy $policy = new PaymentEventPolicy) {}

    public function register(MerchantOrder $order): void
    {
        $sourceOrder = DB::table('orders')->where('id', $order->merchantOrderId)->where('is_test', true)->first();
        if (! app()->environment(['local', 'testing']) || ! $sourceOrder
            || $sourceOrder->status !== 'pending_test'
            || (int) $sourceOrder->total_cents !== $order->amountMinor
            || $sourceOrder->expires_at !== $order->expiresAt->format(DATE_ATOM)) {
            throw new LogicException('Only matching local and testing orders can enter the provider ledger.');
        }

        DB::table('provider_payment_orders')->insertOrIgnore([
            'merchant_order_id' => $order->merchantOrderId,
            'amount_minor' => $order->amountMinor,
            'currency' => $order->currency,
            'expires_at' => $order->expiresAt->format(DATE_ATOM),
            'status' => 'pending',
            'entitlement_granted' => false,
            'compensation_count' => 0,
            'updated_at' => now()->utc()->toIso8601String(),
        ]);
        $registered = DB::table('provider_payment_orders')->where('merchant_order_id', $order->merchantOrderId)->first();
        if (! $registered || (int) $registered->amount_minor !== $order->amountMinor
            || $registered->currency !== $order->currency
            || $registered->expires_at !== $order->expiresAt->format(DATE_ATOM)) {
            throw new LogicException('Merchant order was already registered with different payment terms.');
        }
    }

    public function ingest(ProviderPaymentEvent $event): PaymentDecision
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Provider event ingestion is restricted to local and testing environments.');
        }

        return DB::transaction(function () use ($event): PaymentDecision {
            $existingEvent = DB::table('provider_payment_events')->where('provider', $event->provider)
                ->where('event_id', $event->eventId)->first();
            if ($existingEvent) {
                return $this->sameEvent($existingEvent, $event) ? PaymentDecision::Duplicate : PaymentDecision::Review;
            }

            $order = DB::table('provider_payment_orders')->where('merchant_order_id', $event->merchantOrderId)->lockForUpdate()->first();
            $decision = $order
                ? $this->policy->decide(
                    $event->merchantOrderId,
                    $order->status,
                    (int) $order->amount_minor,
                    $order->currency,
                    new \DateTimeImmutable($order->expires_at),
                    $event,
                    [],
                )
                : PaymentDecision::Review;

            $eventRecordId = (string) Str::uuid();
            DB::table('provider_payment_events')->insert([
                'id' => $eventRecordId,
                'provider' => $event->provider,
                'event_id' => $event->eventId,
                'merchant_order_id' => $event->merchantOrderId,
                'channel_transaction_id' => $event->channelTransactionId,
                'amount_minor' => $event->amountMinor,
                'currency' => $event->currency,
                'event_type' => $event->type->value,
                'occurred_at' => $event->occurredAt->format(DATE_ATOM),
                'decision' => $decision->value,
                'received_at' => now()->utc()->toIso8601String(),
            ]);

            if (! $order || $decision !== PaymentDecision::Apply) {
                return $decision;
            }

            $at = now()->utc()->toIso8601String();
            if ($event->type === PaymentEventType::Paid) {
                DB::table('provider_payment_orders')->where('merchant_order_id', $event->merchantOrderId)
                    ->where('status', 'pending')->update(['status' => 'paid_pending_grant', 'updated_at' => $at]);
                DB::table('provider_payment_fulfillments')->insertOrIgnore([
                    'merchant_order_id' => $event->merchantOrderId,
                    'accepted_event_id' => $eventRecordId,
                    'status' => 'pending',
                    'attempts' => 0,
                    'available_at' => $at,
                ]);
            } elseif ($event->type === PaymentEventType::Failed) {
                DB::table('provider_payment_orders')->where('merchant_order_id', $event->merchantOrderId)
                    ->where('status', 'pending')->update(['status' => 'failed', 'updated_at' => $at]);
            } else {
                DB::table('provider_payment_orders')->where('merchant_order_id', $event->merchantOrderId)
                    ->where('status', 'paid')->update(['status' => 'refund_pending', 'updated_at' => $at]);
                DB::table('provider_payment_compensations')->insertOrIgnore([
                    'merchant_order_id' => $event->merchantOrderId,
                    'refund_event_id' => $eventRecordId,
                    'status' => 'pending',
                    'attempts' => 0,
                    'available_at' => $at,
                ]);
            }

            return $decision;
        }, 3);
    }

    public function recordFulfillmentAttempt(string $merchantOrderId, bool $succeeded, ?string $errorCode = null): void
    {
        DB::transaction(function () use ($merchantOrderId, $succeeded, $errorCode): void {
            $fulfillment = DB::table('provider_payment_fulfillments')->where('merchant_order_id', $merchantOrderId)->lockForUpdate()->first();
            $order = DB::table('provider_payment_orders')->where('merchant_order_id', $merchantOrderId)->lockForUpdate()->first();
            if (! $fulfillment || ! $order) {
                throw new LogicException('Payment fulfillment does not exist.');
            }
            if ($fulfillment->status === 'fulfilled') {
                return;
            }
            if ($order->status !== 'paid_pending_grant') {
                throw new LogicException('Payment order is not awaiting entitlement fulfillment.');
            }

            $at = now()->utc()->toIso8601String();
            DB::table('provider_payment_fulfillments')->where('merchant_order_id', $merchantOrderId)->update([
                'status' => $succeeded ? 'fulfilled' : 'pending',
                'attempts' => (int) $fulfillment->attempts + 1,
                'last_error_code' => $succeeded ? null : $errorCode,
                'available_at' => $at,
                'fulfilled_at' => $succeeded ? $at : null,
            ]);
            if ($succeeded) {
                DB::table('provider_payment_orders')->where('merchant_order_id', $merchantOrderId)->update([
                    'status' => 'paid', 'entitlement_granted' => true, 'updated_at' => $at,
                ]);
            }
        }, 3);
    }

    public function recordCompensationAttempt(string $merchantOrderId, bool $succeeded, ?string $errorCode = null): void
    {
        DB::transaction(function () use ($merchantOrderId, $succeeded, $errorCode): void {
            $compensation = DB::table('provider_payment_compensations')->where('merchant_order_id', $merchantOrderId)->lockForUpdate()->first();
            $order = DB::table('provider_payment_orders')->where('merchant_order_id', $merchantOrderId)->lockForUpdate()->first();
            if (! $compensation || ! $order) {
                throw new LogicException('Payment compensation does not exist.');
            }
            if ($compensation->status === 'completed') {
                return;
            }
            if ($order->status !== 'refund_pending') {
                throw new LogicException('Payment order is not awaiting refund compensation.');
            }

            $at = now()->utc()->toIso8601String();
            DB::table('provider_payment_compensations')->where('merchant_order_id', $merchantOrderId)->update([
                'status' => $succeeded ? 'completed' : 'pending',
                'attempts' => (int) $compensation->attempts + 1,
                'last_error_code' => $succeeded ? null : $errorCode,
                'available_at' => $at,
                'completed_at' => $succeeded ? $at : null,
            ]);
            if ($succeeded) {
                DB::table('provider_payment_orders')->where('merchant_order_id', $merchantOrderId)->update([
                    'status' => 'refunded', 'entitlement_granted' => false,
                    'compensation_count' => (int) $order->compensation_count + 1, 'updated_at' => $at,
                ]);
            }
        }, 3);
    }

    private function sameEvent(object $stored, ProviderPaymentEvent $event): bool
    {
        return $stored->merchant_order_id === $event->merchantOrderId
            && $stored->channel_transaction_id === $event->channelTransactionId
            && (int) $stored->amount_minor === $event->amountMinor
            && $stored->currency === $event->currency
            && $stored->event_type === $event->type->value
            && $stored->occurred_at === $event->occurredAt->format(DATE_ATOM);
    }
}
