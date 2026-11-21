<?php

namespace App\Payments;

use DateTimeImmutable;

interface PaymentProvider
{
    public function providerCode(): string;

    public function createPayment(MerchantOrder $order): PaymentSession;

    /** @param array<string, string> $headers */
    public function verifyWebhook(string $rawBody, array $headers, DateTimeImmutable $receivedAt): ProviderPaymentEvent;

    public function queryPayment(string $merchantOrderId): ?ProviderPaymentEvent;

    public function listEvents(DateTimeImmutable $from, DateTimeImmutable $until, ?string $cursor = null): PaymentEventPage;
}
