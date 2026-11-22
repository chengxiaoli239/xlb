<?php

namespace Tests\Unit\Payments;

use App\Payments\MerchantOrder;
use App\Payments\PaymentDecision;
use App\Payments\PaymentEventPolicy;
use App\Payments\PaymentEventType;
use App\Payments\ProviderPaymentEvent;
use App\Payments\SignedSandboxPaymentProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SignedSandboxPaymentProviderTest extends TestCase
{
    private const KEY = 'local-test-signing-key-32-bytes-minimum';

    public function test_payment_creation_query_and_reconciliation_share_provider_neutral_identifiers(): void
    {
        $provider = new SignedSandboxPaymentProvider(self::KEY);
        $order = $this->order();
        $session = $provider->createPayment($order);
        $signed = $provider->simulateEvent('merchant-order-1', 'evt-paid-1', PaymentEventType::Paid, $this->time('2026-11-21T10:00:00Z'), $this->time('2026-11-21T10:00:01Z'));
        $event = $provider->verifyWebhook($signed['body'], $signed['headers'], $this->time('2026-11-21T10:00:02Z'));

        $this->assertSame('signed-sandbox', $session->provider);
        $this->assertSame('merchant-order-1', $session->merchantOrderId);
        $this->assertSame('sandbox-txn-merchant-order-1', $event->channelTransactionId);
        $this->assertSame(2980, $event->amountMinor);
        $this->assertSame('CNY', $event->currency);
        $this->assertSame($event->eventId, $provider->queryPayment('merchant-order-1')?->eventId);
        $this->assertCount(1, $provider->listEvents($this->time('2026-11-21T09:00:00Z'), $this->time('2026-11-21T11:00:00Z'))->events);
    }

    public function test_reconciliation_pagination_does_not_drop_out_of_order_event_ids(): void
    {
        $provider = new SignedSandboxPaymentProvider(self::KEY);
        $provider->createPayment($this->order());
        $from = $this->time('2026-11-21T10:00:00Z');

        for ($index = 0; $index < 100; $index++) {
            $eventId = sprintf('z%03d', $index);
            $occurredAt = $from->modify('+'.$index.' seconds');
            $provider->simulateEvent('merchant-order-1', $eventId, PaymentEventType::Paid, $occurredAt, $occurredAt);
        }
        $lateEventAt = $from->modify('+200 seconds');
        $provider->simulateEvent('merchant-order-1', 'a000', PaymentEventType::Refunded, $lateEventAt, $lateEventAt);

        $firstPage = $provider->listEvents($from, $from->modify('+5 minutes'));
        $secondPage = $provider->listEvents($from, $from->modify('+5 minutes'), $firstPage->nextCursor);
        $actualEventIds = array_map(
            static fn (ProviderPaymentEvent $event): string => $event->eventId,
            [...$firstPage->events, ...$secondPage->events],
        );
        $expectedEventIds = ['a000'];
        for ($index = 0; $index < 100; $index++) {
            $expectedEventIds[] = sprintf('z%03d', $index);
        }
        sort($actualEventIds, SORT_STRING);
        sort($expectedEventIds, SORT_STRING);

        $this->assertCount(100, $firstPage->events);
        $this->assertNotNull($firstPage->nextCursor);
        $this->assertCount(1, $secondPage->events);
        $this->assertSame('z099', $secondPage->events[0]->eventId);
        $this->assertNull($secondPage->nextCursor);
        $this->assertSame($expectedEventIds, $actualEventIds);
        $this->assertCount(count(array_unique($actualEventIds)), $actualEventIds);
    }

    public function test_webhook_signature_time_window_order_amount_and_currency_are_checked(): void
    {
        $provider = new SignedSandboxPaymentProvider(self::KEY);
        $provider->createPayment($this->order());
        $signed = $provider->simulateEvent('merchant-order-1', 'evt-paid-1', PaymentEventType::Paid, $this->time('2026-11-21T10:00:00Z'), $this->time('2026-11-21T10:00:01Z'));

        $tampered = str_replace('2980', '1', $signed['body']);
        $this->expectException(RuntimeException::class);
        $provider->verifyWebhook($tampered, $signed['headers'], $this->time('2026-11-21T10:00:02Z'));
    }

    public function test_stale_webhook_signature_is_rejected(): void
    {
        $provider = new SignedSandboxPaymentProvider(self::KEY);
        $provider->createPayment($this->order());
        $signed = $provider->simulateEvent('merchant-order-1', 'evt-paid-1', PaymentEventType::Paid, $this->time('2026-11-21T10:00:00Z'), $this->time('2026-11-21T10:00:01Z'));

        $this->expectException(RuntimeException::class);
        $provider->verifyWebhook($signed['body'], $signed['headers'], $this->time('2026-11-21T10:06:00Z'));
    }

    public function test_event_policy_is_idempotent_and_sends_late_or_out_of_order_facts_to_review(): void
    {
        $policy = new PaymentEventPolicy;
        $paid = $this->event('event-paid', PaymentEventType::Paid, '2026-11-21T10:00:00Z');
        $refund = $this->event('event-refund', PaymentEventType::Refunded, '2026-11-21T10:01:00Z');
        $latePaid = $this->event('event-late', PaymentEventType::Paid, '2026-11-21T10:31:00Z');

        $this->assertSame(PaymentDecision::Apply, $policy->decide('merchant-order-1', 'pending', 2980, 'CNY', $this->time('2026-11-21T10:30:00Z'), $paid, []));
        $this->assertSame(PaymentDecision::Duplicate, $policy->decide('merchant-order-1', 'pending', 2980, 'CNY', $this->time('2026-11-21T10:30:00Z'), $paid, ['signed-sandbox:event-paid']));
        $this->assertSame(PaymentDecision::Review, $policy->decide('merchant-order-1', 'pending', 2980, 'CNY', $this->time('2026-11-21T10:30:00Z'), $refund, []));
        $this->assertSame(PaymentDecision::Review, $policy->decide('merchant-order-1', 'pending', 2980, 'CNY', $this->time('2026-11-21T10:30:00Z'), $latePaid, []));
        $this->assertSame(PaymentDecision::Apply, $policy->decide('merchant-order-1', 'paid', 2980, 'CNY', $this->time('2026-11-21T10:30:00Z'), $refund, []));
        $this->assertSame(PaymentDecision::Review, $policy->decide('merchant-order-1', 'pending', 1, 'CNY', $this->time('2026-11-21T10:30:00Z'), $paid, []));
        $this->assertSame(PaymentDecision::Review, $policy->decide('another-order', 'pending', 2980, 'CNY', $this->time('2026-11-21T10:30:00Z'), $paid, []));
    }

    private function order(): MerchantOrder
    {
        return new MerchantOrder('merchant-order-1', 2980, 'CNY', $this->time('2026-11-21T10:30:00Z'));
    }

    private function event(string $id, PaymentEventType $type, string $at): ProviderPaymentEvent
    {
        return new ProviderPaymentEvent('signed-sandbox', $id, 'merchant-order-1', 'txn-1', 2980, 'CNY', $type, $this->time($at));
    }

    private function time(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}
