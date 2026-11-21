<?php

namespace App\Payments;

use DateTimeImmutable;

final readonly class ProviderPaymentEvent
{
    public function __construct(
        public string $provider,
        public string $eventId,
        public string $merchantOrderId,
        public string $channelTransactionId,
        public int $amountMinor,
        public string $currency,
        public PaymentEventType $type,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function idempotencyKey(): string
    {
        return $this->provider.':'.$this->eventId;
    }
}
