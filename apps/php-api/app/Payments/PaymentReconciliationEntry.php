<?php

namespace App\Payments;

final readonly class PaymentReconciliationEntry
{
    public function __construct(
        public string $provider,
        public string $eventId,
        public string $merchantOrderId,
        public string $outcome,
    ) {}
}
