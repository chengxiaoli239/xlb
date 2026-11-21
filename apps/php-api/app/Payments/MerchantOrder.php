<?php

namespace App\Payments;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MerchantOrder
{
    public function __construct(
        public string $merchantOrderId,
        public int $amountMinor,
        public string $currency,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($merchantOrderId === '' || strlen($merchantOrderId) > 128) {
            throw new InvalidArgumentException('Merchant order ID must contain 1 to 128 characters.');
        }
        if ($amountMinor < 1) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter uppercase code.');
        }
    }
}
