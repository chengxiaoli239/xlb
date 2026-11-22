<?php

namespace App\Payments;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class SignedSandboxPaymentProvider implements PaymentProvider
{
    private const SIGNATURE_WINDOW_SECONDS = 300;

    /** @var array<string, MerchantOrder> */
    private array $orders = [];

    /** @var array<string, ProviderPaymentEvent> */
    private array $events = [];

    public function __construct(private readonly string $signingKey)
    {
        if (strlen($signingKey) < 32) {
            throw new InvalidArgumentException('Sandbox signing key must contain at least 32 bytes.');
        }
    }

    public function providerCode(): string
    {
        return 'signed-sandbox';
    }

    public function createPayment(MerchantOrder $order): PaymentSession
    {
        $existing = $this->orders[$order->merchantOrderId] ?? null;
        if ($existing && ($existing->amountMinor !== $order->amountMinor
            || $existing->currency !== $order->currency
            || $existing->expiresAt != $order->expiresAt)) {
            throw new RuntimeException('Merchant order was already created with different payment terms.');
        }

        $this->orders[$order->merchantOrderId] = $order;

        return new PaymentSession($this->providerCode(), $order->merchantOrderId, 'sandbox-txn-'.$order->merchantOrderId, 'pending');
    }

    public function verifyWebhook(string $rawBody, array $headers, DateTimeImmutable $receivedAt): ProviderPaymentEvent
    {
        $timestamp = $headers['X-Sandbox-Timestamp'] ?? '';
        $signature = $headers['X-Sandbox-Signature'] ?? '';
        if (! ctype_digit($timestamp) || abs($receivedAt->getTimestamp() - (int) $timestamp) > self::SIGNATURE_WINDOW_SECONDS) {
            throw new RuntimeException('Sandbox webhook timestamp is outside the accepted window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->signingKey);
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new RuntimeException('Sandbox webhook signature is invalid.');
        }

        try {
            $data = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Sandbox webhook payload is invalid.', previous: $exception);
        }
        if (! is_array($data)
            || ! is_string($data['event_type'] ?? null)
            || ! is_string($data['occurred_at'] ?? null)
            || ! is_string($data['merchant_order_id'] ?? null)
            || ! is_string($data['transaction_id'] ?? null)
            || ! is_string($data['provider'] ?? null)
            || ! is_string($data['event_id'] ?? null)
            || ! is_int($data['amount_minor'] ?? null)
            || ! is_string($data['currency'] ?? null)) {
            throw new RuntimeException('Sandbox webhook payload fields are invalid.');
        }
        $type = PaymentEventType::tryFrom($data['event_type']);
        if (! $type || $data['event_id'] === '') {
            throw new RuntimeException('Sandbox webhook event identity is invalid.');
        }
        try {
            $occurredAt = new DateTimeImmutable($data['occurred_at']);
        } catch (\Exception $exception) {
            throw new RuntimeException('Sandbox webhook event time is invalid.', previous: $exception);
        }

        $order = $this->orders[$data['merchant_order_id']] ?? null;
        if (! $order || $data['transaction_id'] !== 'sandbox-txn-'.$order->merchantOrderId) {
            throw new RuntimeException('Sandbox webhook does not match a known merchant order.');
        }
        if ($data['amount_minor'] !== $order->amountMinor || $data['currency'] !== $order->currency) {
            throw new RuntimeException('Sandbox webhook amount or currency does not match the merchant order.');
        }
        if ($data['provider'] !== $this->providerCode()) {
            throw new RuntimeException('Sandbox webhook identity is invalid.');
        }

        return new ProviderPaymentEvent(
            $this->providerCode(),
            $data['event_id'],
            $order->merchantOrderId,
            'sandbox-txn-'.$order->merchantOrderId,
            $order->amountMinor,
            $order->currency,
            $type,
            $occurredAt,
        );
    }

    public function queryPayment(string $merchantOrderId): ?ProviderPaymentEvent
    {
        foreach (array_reverse($this->events) as $event) {
            if ($event->merchantOrderId === $merchantOrderId) {
                return $event;
            }
        }

        return null;
    }

    public function listEvents(DateTimeImmutable $from, DateTimeImmutable $until, ?string $cursor = null): PaymentEventPage
    {
        $events = array_values(array_filter($this->events, static fn (ProviderPaymentEvent $event): bool => $event->occurredAt >= $from && $event->occurredAt <= $until && (! $cursor || $event->eventId > $cursor)));
        usort($events, static fn (ProviderPaymentEvent $left, ProviderPaymentEvent $right): int => $left->eventId <=> $right->eventId);
        $page = array_slice($events, 0, 100);
        $nextCursor = count($events) > 100 ? $page[array_key_last($page)]->eventId : null;

        return new PaymentEventPage($page, $nextCursor);
    }

    /** @return array{body:string,headers:array<string,string>} */
    public function simulateEvent(
        string $merchantOrderId,
        string $eventId,
        PaymentEventType $type,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $signedAt,
    ): array {
        $order = $this->orders[$merchantOrderId] ?? throw new RuntimeException('Merchant order is unknown.');
        $data = [
            'provider' => $this->providerCode(),
            'event_id' => $eventId,
            'merchant_order_id' => $merchantOrderId,
            'transaction_id' => 'sandbox-txn-'.$merchantOrderId,
            'amount_minor' => $order->amountMinor,
            'currency' => $order->currency,
            'event_type' => $type->value,
            'occurred_at' => $occurredAt->format(DATE_ATOM),
        ];
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $timestamp = (string) $signedAt->getTimestamp();
        $event = $this->verifyWebhook($body, [
            'X-Sandbox-Timestamp' => $timestamp,
            'X-Sandbox-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $this->signingKey),
        ], $signedAt);
        $this->events[$event->idempotencyKey()] = $event;

        return ['body' => $body, 'headers' => [
            'X-Sandbox-Timestamp' => $timestamp,
            'X-Sandbox-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $this->signingKey),
        ]];
    }
}
