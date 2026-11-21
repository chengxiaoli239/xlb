<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Payments\MerchantOrder;
use App\Payments\PaymentDecision;
use App\Payments\PaymentEventType;
use App\Payments\PersistentPaymentEventLedger;
use App\Payments\ProviderPaymentEvent;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class ProviderPaymentEventLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::findOrFail('user-member'));
    }

    public function test_paid_event_is_persisted_once_and_only_queues_entitlement_fulfillment(): void
    {
        $orderId = $this->createOrder();
        $ledger = new PersistentPaymentEventLedger;
        $ledger->register($this->merchantOrder($orderId));
        $payment = $this->event($orderId, 'event-paid-1', PaymentEventType::Paid);

        $this->assertSame(PaymentDecision::Apply, $ledger->ingest($payment));
        $this->assertSame(PaymentDecision::Duplicate, $ledger->ingest($payment));
        $this->assertDatabaseCount('provider_payment_events', 1);
        $this->assertDatabaseHas('provider_payment_orders', [
            'merchant_order_id' => $orderId,
            'status' => 'paid_pending_grant',
            'entitlement_granted' => false,
        ]);
        $this->assertDatabaseHas('provider_payment_fulfillments', [
            'merchant_order_id' => $orderId,
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $this->assertDatabaseCount('entitlement_grants', 0);
        $this->assertDatabaseCount('entitlements', 1);
    }

    public function test_conflicting_duplicate_late_payment_and_pre_payment_refund_are_reviewed(): void
    {
        $orderId = $this->createOrder();
        $ledger = new PersistentPaymentEventLedger;
        $ledger->register($this->merchantOrder($orderId));
        $payment = $this->event($orderId, 'event-paid-1', PaymentEventType::Paid);

        $this->assertSame(PaymentDecision::Apply, $ledger->ingest($payment));
        $this->assertSame(PaymentDecision::Review, $ledger->ingest($this->event($orderId, 'event-paid-1', PaymentEventType::Paid, amount: 1)));
        $lateAt = $this->merchantOrder($orderId)->expiresAt->modify('+1 minute')->format(DATE_ATOM);
        $this->assertSame(PaymentDecision::Review, $ledger->ingest($this->event($orderId, 'event-paid-late', PaymentEventType::Paid, occurredAt: $lateAt)));
        $this->assertSame(PaymentDecision::Review, $ledger->ingest($this->event($orderId, 'event-refund-early', PaymentEventType::Refunded)));
        $this->assertDatabaseHas('provider_payment_orders', ['merchant_order_id' => $orderId, 'status' => 'paid_pending_grant']);
        $this->assertDatabaseCount('provider_payment_fulfillments', 1);
        $this->assertDatabaseCount('provider_payment_compensations', 0);
        $this->assertDatabaseCount('entitlement_grants', 0);
    }

    public function test_fulfillment_and_refund_compensation_retries_are_idempotent(): void
    {
        $orderId = $this->createOrder();
        $ledger = new PersistentPaymentEventLedger;
        $ledger->register($this->merchantOrder($orderId));
        $ledger->ingest($this->event($orderId, 'event-paid-1', PaymentEventType::Paid));

        $ledger->recordFulfillmentAttempt($orderId, false, 'temporary_failure');
        $ledger->recordFulfillmentAttempt($orderId, true);
        $ledger->recordFulfillmentAttempt($orderId, true);
        $this->assertDatabaseHas('provider_payment_fulfillments', ['merchant_order_id' => $orderId, 'status' => 'fulfilled', 'attempts' => 2]);
        $this->assertDatabaseHas('provider_payment_orders', ['merchant_order_id' => $orderId, 'status' => 'paid', 'entitlement_granted' => true]);

        $refund = $this->event($orderId, 'event-refund-1', PaymentEventType::Refunded);
        $this->assertSame(PaymentDecision::Apply, $ledger->ingest($refund));
        $this->assertSame(PaymentDecision::Duplicate, $ledger->ingest($refund));
        $ledger->recordCompensationAttempt($orderId, false, 'refund_temporarily_unavailable');
        $ledger->recordCompensationAttempt($orderId, true);
        $ledger->recordCompensationAttempt($orderId, true);

        $this->assertDatabaseHas('provider_payment_compensations', ['merchant_order_id' => $orderId, 'status' => 'completed', 'attempts' => 2]);
        $this->assertDatabaseHas('provider_payment_orders', [
            'merchant_order_id' => $orderId,
            'status' => 'refunded',
            'entitlement_granted' => false,
            'compensation_count' => 1,
        ]);
    }

    public function test_order_terms_are_immutable_and_live_environments_reject_registration(): void
    {
        $orderId = $this->createOrder();
        $ledger = new PersistentPaymentEventLedger;
        $order = $this->merchantOrder($orderId);
        $ledger->register($order);

        try {
            $ledger->register(new MerchantOrder($orderId, 2980, 'CNY', new DateTimeImmutable('2026-12-01T00:00:00Z')));
            $this->fail('Expected changed merchant order terms to be rejected.');
        } catch (LogicException) {
            $this->assertDatabaseCount('provider_payment_orders', 1);
        }

        $this->app->instance('env', 'production');
        $this->expectException(LogicException::class);
        $ledger->register($order);
    }

    private function createOrder(): string
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/orders', ['plan_id' => 'monthly'])
            ->assertCreated()
            ->json('data.id');
    }

    private function merchantOrder(string $id): MerchantOrder
    {
        return new MerchantOrder(
            $id,
            2980,
            'CNY',
            new DateTimeImmutable(DB::table('orders')->where('id', $id)->value('expires_at')),
        );
    }

    private function event(
        string $orderId,
        string $id,
        PaymentEventType $type,
        int $amount = 2980,
        ?string $occurredAt = null,
    ): ProviderPaymentEvent {
        return new ProviderPaymentEvent(
            'signed-sandbox',
            $id,
            $orderId,
            'sandbox-txn-'.$orderId,
            $amount,
            'CNY',
            $type,
            new DateTimeImmutable($occurredAt ?? now()->utc()->format(DATE_ATOM)),
        );
    }
}
