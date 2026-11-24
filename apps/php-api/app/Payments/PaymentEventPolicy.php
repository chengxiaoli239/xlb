<?php

namespace App\Payments;

use DateTimeImmutable;

final class PaymentEventPolicy
{
    /**
     * @param  list<string>  $seenEventKeys
     */
    public function decide(
        string $expectedMerchantOrderId,
        string $currentStatus,
        int $expectedAmountMinor,
        string $expectedCurrency,
        DateTimeImmutable $orderExpiresAt,
        ProviderPaymentEvent $event,
        array $seenEventKeys,
    ): PaymentDecision {
        if (in_array($event->idempotencyKey(), $seenEventKeys, true)) {
            return PaymentDecision::Duplicate;
        }
        if ($event->merchantOrderId !== $expectedMerchantOrderId
            || $event->amountMinor !== $expectedAmountMinor
            || $event->currency !== $expectedCurrency) {
            return PaymentDecision::Review;
        }

        return match ($event->type) {
            PaymentEventType::Paid => $this->paidDecision($currentStatus, $orderExpiresAt, $event),
            PaymentEventType::Failed => $currentStatus === 'pending' ? PaymentDecision::Apply : PaymentDecision::Ignore,
            PaymentEventType::Refunded => $currentStatus === 'paid' ? PaymentDecision::Apply : PaymentDecision::Review,
        };
    }

    private function paidDecision(string $currentStatus, DateTimeImmutable $orderExpiresAt, ProviderPaymentEvent $event): PaymentDecision
    {
        if ($event->occurredAt >= $orderExpiresAt) {
            return PaymentDecision::Review;
        }

        return match ($currentStatus) {
            'pending' => PaymentDecision::Apply,
            'cancelled' => PaymentDecision::Review,
            'paid', 'refunded' => PaymentDecision::Review,
            default => PaymentDecision::Ignore,
        };
    }
}
