<?php

namespace Tests\Unit\Payments;

use App\Payments\InMemoryPaymentEventStore;
use App\Payments\MerchantOrder;
use App\Payments\PaymentDecision;
use App\Payments\PaymentEventProcessor;
use App\Payments\PaymentEventType;
use App\Payments\ProviderPaymentEvent;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class PaymentEventProcessorTest extends TestCase
{
    public function test_duplicate_payment_event_grants_entitlement_only_once(): void
    {
        $processor = $this->processor();
        $this->register($processor);
        $payment = $this->event('paid-1', PaymentEventType::Paid, '2026-11-21T10:00:00Z');

        $this->assertSame(PaymentDecision::Apply, $processor->process($payment));
        $this->assertSame(PaymentDecision::Duplicate, $processor->process($payment));
        $this->assertSame([
            'status' => 'paid',
            'entitlement_granted' => true,
            'compensation_count' => 0,
            'events' => [['event_id' => 'paid-1', 'type' => 'paid', 'decision' => 'apply']],
        ], $processor->snapshot('merchant-order-1'));
    }

    public function test_refund_arriving_before_payment_is_reviewed_without_changing_the_order(): void
    {
        $processor = $this->processor();
        $this->register($processor);

        $this->assertSame(PaymentDecision::Review, $processor->process($this->event('refund-1', PaymentEventType::Refunded, '2026-11-21T10:01:00Z')));
        $this->assertSame('pending', $processor->snapshot('merchant-order-1')['status']);
        $this->assertFalse($processor->snapshot('merchant-order-1')['entitlement_granted']);
    }

    public function test_valid_refund_appends_one_compensation_and_revokes_the_grant(): void
    {
        $processor = $this->processor();
        $this->register($processor);
        $processor->process($this->event('paid-1', PaymentEventType::Paid, '2026-11-21T10:00:00Z'));
        $refund = $this->event('refund-1', PaymentEventType::Refunded, '2026-11-21T10:01:00Z');

        $this->assertSame(PaymentDecision::Apply, $processor->process($refund));
        $this->assertSame(PaymentDecision::Duplicate, $processor->process($refund));
        $snapshot = $processor->snapshot('merchant-order-1');
        $this->assertSame('refunded', $snapshot['status']);
        $this->assertFalse($snapshot['entitlement_granted']);
        $this->assertSame(1, $snapshot['compensation_count']);
    }

    public function test_late_payment_and_wrong_order_or_amount_are_reviewed_without_granting(): void
    {
        $processor = $this->processor();
        $this->register($processor);

        $late = $this->event('late-1', PaymentEventType::Paid, '2026-11-21T10:31:00Z');
        $wrongOrder = $this->event('wrong-order', PaymentEventType::Paid, '2026-11-21T10:10:00Z', 'other-order');
        $wrongAmount = $this->event('wrong-amount', PaymentEventType::Paid, '2026-11-21T10:10:00Z', amount: 1);

        $this->assertSame(PaymentDecision::Review, $processor->process($late));
        $this->assertSame(PaymentDecision::Review, $processor->process($wrongOrder));
        $this->assertSame(PaymentDecision::Review, $processor->process($wrongAmount));
        $snapshot = $processor->snapshot('merchant-order-1');
        $this->assertSame('pending', $snapshot['status']);
        $this->assertFalse($snapshot['entitlement_granted']);
    }

    private function processor(): PaymentEventProcessor
    {
        return new PaymentEventProcessor(new InMemoryPaymentEventStore);
    }

    private function register(PaymentEventProcessor $processor): void
    {
        $processor->register(new MerchantOrder('merchant-order-1', 2980, 'CNY', new DateTimeImmutable('2026-11-21T10:30:00Z')));
    }

    private function event(string $id, PaymentEventType $type, string $at, string $orderId = 'merchant-order-1', int $amount = 2980): ProviderPaymentEvent
    {
        return new ProviderPaymentEvent('signed-sandbox', $id, $orderId, 'sandbox-txn-'.$orderId, $amount, 'CNY', $type, new DateTimeImmutable($at));
    }
}
