<?php

namespace App\Payments;

final readonly class PaymentSession
{
    public function __construct(
        public string $provider,
        public string $merchantOrderId,
        public string $channelTransactionId,
        public string $status,
    ) {}
}
