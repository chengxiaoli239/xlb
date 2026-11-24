<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Payments\MerchantOrder;
use App\Payments\PaymentDecision;
use App\Payments\PaymentEventType;
use App\Payments\PaymentReconciliationService;
use App\Payments\PersistentPaymentEventLedger;
use App\Payments\PersistentSandboxPaymentProvider;
use App\Payments\ProviderPaymentEvent;
use App\Payments\SandboxPaymentFulfillmentWorker;
use App\Payments\SignedSandboxPaymentProvider;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

class ProviderPaymentEventLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNING_KEY = 'local-provider-reconciliation-test-key';

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

    public function test_signed_local_webhook_is_idempotent_and_worker_grants_sandbox_entitlement(): void
    {
        $orderId = $this->createOrder();
        $session = $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-provider-session')
            ->assertOk()->json('data');
        $signed = $this->signedPaidEvent($session);
        (new PersistentSandboxPaymentProvider((string) config('app.key'), new PersistentPaymentEventLedger))
            ->verifyWebhook($signed['body'], $signed['headers'], now()->utc()->toDateTimeImmutable());

        $this->webhook($signed)->assertOk()->assertJsonPath('data.decision', 'apply');
        $this->webhook($signed)
            ->assertOk()->assertJsonPath('data.decision', 'duplicate');
        $this->assertDatabaseCount('provider_payment_events', 1);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'pending_test']);
        $this->assertDatabaseCount('entitlement_grants', 0);

        $this->artisan('payments:process-sandbox-fulfillments')->assertSuccessful();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'paid_test']);
        $this->assertDatabaseHas('entitlement_grants', ['order_id' => $orderId, 'status' => 'granted_test']);
        $this->assertDatabaseHas('provider_payment_orders', ['merchant_order_id' => $orderId, 'status' => 'paid', 'entitlement_granted' => true]);
        $this->assertDatabaseHas('provider_payment_fulfillments', ['merchant_order_id' => $orderId, 'status' => 'fulfilled', 'attempts' => 1]);
        $this->assertDatabaseCount('entitlements', 2);

        $refund = $this->signedSandboxEvent($session, 'refunded');
        $this->webhook($refund)->assertOk()->assertJsonPath('data.decision', 'apply');
        $this->webhook($refund)->assertOk()->assertJsonPath('data.decision', 'duplicate');
        $this->artisan('payments:process-sandbox-refunds')->assertSuccessful();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'refunded_test']);
        $this->assertDatabaseHas('entitlement_grants', ['order_id' => $orderId, 'status' => 'revoked_test']);
        $this->assertDatabaseHas('provider_payment_orders', ['merchant_order_id' => $orderId, 'status' => 'refunded', 'entitlement_granted' => false]);
        $this->assertDatabaseHas('provider_payment_compensations', ['merchant_order_id' => $orderId, 'status' => 'completed', 'attempts' => 1]);
        $this->assertDatabaseCount('sandbox_refund_events', 1);
    }

    public function test_distinct_provider_fulfillments_append_the_same_member_entitlement_chain(): void
    {
        $subscription = $this->postJson('/api/v1/me/subscriptions')->assertCreated()->json('data');
        $currentEnd = DB::table('entitlements')->where('id', 'entitlement-member-annual')->value('ends_at');
        $firstOrderId = $this->createOrder();
        $secondOrderId = $this->createOrder();
        $ledger = new PersistentPaymentEventLedger;
        $worker = app(SandboxPaymentFulfillmentWorker::class);

        foreach ([$firstOrderId, $secondOrderId] as $orderId) {
            $ledger->register($this->merchantOrder($orderId));
            $this->assertSame(PaymentDecision::Apply,
                $ledger->ingest($this->event($orderId, 'paid-'.$orderId, PaymentEventType::Paid)));
            $this->assertTrue($worker->process($orderId));
        }

        $firstGrant = DB::table('entitlement_grants')->where('order_id', $firstOrderId)->first();
        $secondGrant = DB::table('entitlement_grants')->where('order_id', $secondOrderId)->first();

        $this->assertSame($currentEnd, $firstGrant->starts_at);
        $this->assertSame($firstGrant->ends_at, $secondGrant->starts_at);
        $this->assertDatabaseHas('provider_payment_orders', ['merchant_order_id' => $firstOrderId, 'status' => 'paid']);
        $this->assertDatabaseHas('provider_payment_orders', ['merchant_order_id' => $secondOrderId, 'status' => 'paid']);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('entitlement_grants', 2);
    }

    public function test_bad_signature_and_expired_timestamp_do_not_create_payment_events(): void
    {
        $orderId = $this->createOrder();
        $session = $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-provider-session')->assertOk()->json('data');
        $signed = $this->signedPaidEvent($session);
        (new PersistentSandboxPaymentProvider((string) config('app.key'), new PersistentPaymentEventLedger))
            ->verifyWebhook($signed['body'], $signed['headers'], now()->utc()->toDateTimeImmutable());

        $badHeaders = [...$signed['headers'], 'X-Sandbox-Signature' => str_repeat('0', 64)];
        $this->webhook(['body' => $signed['body'], 'headers' => $badHeaders])->assertBadRequest();

        $expiredTimestamp = (string) (now()->utc()->getTimestamp() - 301);
        $expiredHeaders = [
            'X-Sandbox-Timestamp' => $expiredTimestamp,
            'X-Sandbox-Signature' => hash_hmac('sha256', $expiredTimestamp.'.'.$signed['body'], (string) config('app.key')),
        ];
        $this->webhook(['body' => $signed['body'], 'headers' => $expiredHeaders])->assertBadRequest();
        $this->assertDatabaseCount('provider_payment_events', 0);
        $this->assertDatabaseCount('entitlement_grants', 0);
    }

    public function test_fulfillment_failure_is_retained_for_retry_then_succeeds_once(): void
    {
        $orderId = $this->createOrder();
        $session = $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-provider-session')->assertOk()->json('data');
        $signed = $this->signedPaidEvent($session);
        $this->webhook($signed)
            ->assertOk()->assertJsonPath('data.decision', 'apply');

        DB::table('orders')->where('id', $orderId)->update(['status' => 'cancelled_test']);
        $this->artisan('payments:process-sandbox-fulfillments')->assertSuccessful();
        $this->assertDatabaseHas('provider_payment_fulfillments', [
            'merchant_order_id' => $orderId,
            'status' => 'pending',
            'attempts' => 1,
            'last_error_code' => 'sandbox_fulfillment_failed',
        ]);
        $this->assertDatabaseCount('entitlement_grants', 0);

        DB::table('orders')->where('id', $orderId)->update(['status' => 'pending_test']);
        $this->artisan('payments:process-sandbox-fulfillments')->assertSuccessful();
        $this->assertDatabaseHas('provider_payment_fulfillments', ['merchant_order_id' => $orderId, 'status' => 'fulfilled', 'attempts' => 2]);
        $this->assertDatabaseCount('entitlement_grants', 1);
        $this->assertDatabaseCount('entitlements', 2);
    }

    public function test_refund_compensation_failure_is_retained_for_retry_without_revoking_entitlement_early(): void
    {
        $orderId = $this->createOrder();
        $session = $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-provider-session')->assertOk()->json('data');
        $this->webhook($this->signedPaidEvent($session))->assertOk();
        $this->artisan('payments:process-sandbox-fulfillments')->assertSuccessful();

        $refund = $this->signedSandboxEvent($session, 'refunded');
        $this->webhook($refund)->assertOk()->assertJsonPath('data.decision', 'apply');
        DB::table('orders')->where('id', $orderId)->update(['status' => 'cancelled_test']);

        $this->artisan('payments:process-sandbox-refunds')->assertSuccessful();

        $this->assertDatabaseHas('provider_payment_compensations', [
            'merchant_order_id' => $orderId,
            'status' => 'pending',
            'attempts' => 1,
            'last_error_code' => 'sandbox_refund_not_completed',
        ]);
        $this->assertDatabaseHas('provider_payment_orders', [
            'merchant_order_id' => $orderId,
            'status' => 'refund_pending',
            'entitlement_granted' => true,
        ]);
        $this->assertDatabaseHas('entitlement_grants', ['order_id' => $orderId, 'status' => 'granted_test']);
        $this->assertDatabaseCount('sandbox_refund_events', 0);

        DB::table('orders')->where('id', $orderId)->update(['status' => 'paid_test']);
        $this->artisan('payments:process-sandbox-refunds')->assertSuccessful();

        $this->assertDatabaseHas('provider_payment_compensations', [
            'merchant_order_id' => $orderId,
            'status' => 'completed',
            'attempts' => 2,
        ]);
        $this->assertDatabaseHas('provider_payment_orders', [
            'merchant_order_id' => $orderId,
            'status' => 'refunded',
            'entitlement_granted' => false,
            'compensation_count' => 1,
        ]);
        $this->assertDatabaseHas('entitlement_grants', ['order_id' => $orderId, 'status' => 'revoked_test']);
        $this->assertDatabaseCount('sandbox_refund_events', 1);
    }

    public function test_sandbox_webhook_route_is_disabled_outside_local_and_testing(): void
    {
        $this->app->instance('env', 'production');

        $this->postJson('/api/v1/payments/sandbox/webhook', [])->assertForbidden();
        $this->assertDatabaseCount('provider_payment_events', 0);
    }

    public function test_reconciliation_reports_matched_and_provider_only_events_without_mutating_ledger(): void
    {
        $orderId = $this->createOrder();
        $order = $this->merchantOrder($orderId);
        $ledger = new PersistentPaymentEventLedger;
        $ledger->register($order);
        $provider = new SignedSandboxPaymentProvider(self::SIGNING_KEY);
        $provider->createPayment($order);
        $now = now()->utc()->toDateTimeImmutable();
        $first = $provider->simulateEvent($orderId, 'provider-paid-1', PaymentEventType::Paid, $now, $now);
        $paid = $provider->verifyWebhook($first['body'], $first['headers'], $now);
        $ledger->ingest($paid);
        $provider->simulateEvent($orderId, 'provider-paid-2', PaymentEventType::Paid, $now, $now);

        $report = (new PaymentReconciliationService)->reconcile($provider, $now->modify('-1 minute'), $now->modify('+1 minute'));

        $this->assertSame(1, $report->count('matched'));
        $this->assertSame(1, $report->count('missing_local'));
        $this->assertSame(0, $report->count('missing_provider'));
        $this->assertDatabaseCount('provider_payment_events', 1);
        $this->assertDatabaseHas('provider_payment_orders', ['merchant_order_id' => $orderId, 'status' => 'paid_pending_grant']);
    }

    public function test_reconciliation_reports_fact_mismatches_and_local_only_events(): void
    {
        $orderId = $this->createOrder();
        $order = $this->merchantOrder($orderId);
        $ledger = new PersistentPaymentEventLedger;
        $ledger->register($order);
        $provider = new SignedSandboxPaymentProvider(self::SIGNING_KEY);
        $provider->createPayment($order);
        $now = now()->utc()->toDateTimeImmutable();

        $remotePaid = $provider->simulateEvent($orderId, 'provider-paid-1', PaymentEventType::Paid, $now, $now);
        $localPaid = $provider->verifyWebhook($remotePaid['body'], $remotePaid['headers'], $now);
        $ledger->ingest($localPaid);
        $provider->simulateEvent($orderId, 'provider-paid-1', PaymentEventType::Failed, $now, $now);
        $localOnly = new ProviderPaymentEvent('signed-sandbox', 'local-only', $orderId, 'sandbox-txn-'.$orderId, 2980, 'CNY', PaymentEventType::Failed, $now);
        $ledger->ingest($localOnly);

        $report = (new PaymentReconciliationService)->reconcile($provider, $now->modify('-1 minute'), $now->modify('+1 minute'));

        $this->assertSame(1, $report->count('mismatch'));
        $this->assertSame(1, $report->count('missing_provider'));
        $this->assertDatabaseCount('provider_payment_events', 2);
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

    /** @return array{body:string,headers:array<string,string>} */
    private function signedPaidEvent(array $session): array
    {
        return $this->signedSandboxEvent($session, 'paid');
    }

    private function signedSandboxEvent(array $session, string $type): array
    {
        $timestamp = (string) now()->utc()->getTimestamp();
        $body = json_encode([
            'provider' => $session['provider'],
            'event_id' => 'event-'.Str::uuid(),
            'merchant_order_id' => $session['merchantOrderId'],
            'transaction_id' => $session['channelTransactionId'],
            'amount_minor' => 2980,
            'currency' => 'CNY',
            'event_type' => $type,
            'occurred_at' => now()->utc()->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR);

        return [
            'body' => $body,
            'headers' => [
                'X-Sandbox-Timestamp' => $timestamp,
                'X-Sandbox-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, (string) config('app.key')),
            ],
        ];
    }

    /** @param array{body:string,headers:array<string,string>} $signed */
    private function webhook(array $signed): TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SANDBOX_TIMESTAMP' => $signed['headers']['X-Sandbox-Timestamp'],
            'HTTP_X_SANDBOX_SIGNATURE' => $signed['headers']['X-Sandbox-Signature'],
        ];

        return $this->call('POST', '/api/v1/payments/sandbox/webhook', [], [], [], $server, $signed['body']);
    }
}
