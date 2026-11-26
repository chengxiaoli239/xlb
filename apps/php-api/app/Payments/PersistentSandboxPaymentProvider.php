<?php

namespace App\Payments;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PersistentSandboxPaymentProvider implements PaymentProvider
{
    private const SIGNATURE_WINDOW_SECONDS = 300;

    public function __construct(
        private readonly string $signingKey,
        private readonly PersistentPaymentEventLedger $ledger,
    ) {
        if (strlen($signingKey) < 32) {
            throw new RuntimeException('Sandbox signing key must contain at least 32 bytes.');
        }
    }

    public function providerCode(): string
    {
        return 'signed-sandbox';
    }

    public function createPayment(MerchantOrder $order): PaymentSession
    {
        $this->ledger->register($order);

        return new PaymentSession($this->providerCode(), $order->merchantOrderId, $this->transactionId($order->merchantOrderId), 'pending');
    }

    public function verifyWebhook(string $rawBody, array $headers, DateTimeImmutable $receivedAt): ProviderPaymentEvent
    {
        $timestamp = $headers['X-Sandbox-Timestamp'] ?? '';
        $signature = $headers['X-Sandbox-Signature'] ?? '';
        if (! ctype_digit($timestamp) || abs($receivedAt->getTimestamp() - (int) $timestamp) > self::SIGNATURE_WINDOW_SECONDS) {
            throw new RuntimeException('Sandbox webhook timestamp is outside the accepted window.');
        }
        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->signingKey), $signature)) {
            throw new RuntimeException('Sandbox webhook signature is invalid.');
        }

        try {
            $data = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Sandbox webhook payload is invalid.', previous: $exception);
        }
        if (! is_array($data)
            || ($data['provider'] ?? null) !== $this->providerCode()
            || ! is_string($data['event_id'] ?? null) || $data['event_id'] === ''
            || ! is_string($data['merchant_order_id'] ?? null)
            || ! is_string($data['transaction_id'] ?? null)
            || ! is_int($data['amount_minor'] ?? null)
            || ! is_string($data['currency'] ?? null)
            || ! is_string($data['event_type'] ?? null)
            || ! is_string($data['occurred_at'] ?? null)) {
            throw new RuntimeException('Sandbox webhook fields are invalid.');
        }

        $type = PaymentEventType::tryFrom($data['event_type']);
        if (! $type) {
            throw new RuntimeException('Sandbox webhook event type is invalid.');
        }
        try {
            $occurredAt = new DateTimeImmutable($data['occurred_at']);
        } catch (\Exception $exception) {
            throw new RuntimeException('Sandbox webhook event time is invalid.', previous: $exception);
        }

        $order = DB::table('provider_payment_orders')->where('merchant_order_id', $data['merchant_order_id'])->first();
        if (! $order || $data['transaction_id'] !== $this->transactionId($order->merchant_order_id)
            || $data['amount_minor'] !== (int) $order->amount_minor || $data['currency'] !== $order->currency) {
            throw new RuntimeException('Sandbox webhook does not match a registered merchant order.');
        }

        return new ProviderPaymentEvent(
            $this->providerCode(),
            $data['event_id'],
            $order->merchant_order_id,
            $data['transaction_id'],
            $data['amount_minor'],
            $data['currency'],
            $type,
            $occurredAt,
        );
    }

    public function queryPayment(string $merchantOrderId): ?ProviderPaymentEvent
    {
        $row = DB::table('provider_payment_events')->where('provider', $this->providerCode())
            ->where('merchant_order_id', $merchantOrderId)->where('decision', PaymentDecision::Apply->value)
            ->orderByDesc('received_at')->first();

        return $row ? $this->eventFromRow($row) : null;
    }

    public function listEvents(DateTimeImmutable $from, DateTimeImmutable $until, ?string $cursor = null): PaymentEventPage
    {
        $rows = DB::table('provider_payment_events')->where('provider', $this->providerCode())
            ->when($cursor !== null, fn ($query) => $query->where('event_id', '>', $cursor))
            ->orderBy('event_id')->limit(500)->get();
        $events = $rows->map(fn (object $row): ?ProviderPaymentEvent => $this->eventFromRow($row))
            ->filter(fn (?ProviderPaymentEvent $event): bool => $event !== null && $event->occurredAt >= $from && $event->occurredAt <= $until)
            ->values()->all();
        $nextCursor = $rows->count() === 500 ? $rows->last()->event_id : null;

        return new PaymentEventPage($events, $nextCursor);
    }

    private function eventFromRow(object $row): ?ProviderPaymentEvent
    {
        $type = PaymentEventType::tryFrom($row->event_type);
        if (! $type) {
            return null;
        }

        return new ProviderPaymentEvent(
            $row->provider,
            $row->event_id,
            $row->merchant_order_id,
            $row->channel_transaction_id,
            (int) $row->amount_minor,
            $row->currency,
            $type,
            new DateTimeImmutable($row->occurred_at),
        );
    }

    private function transactionId(string $merchantOrderId): string
    {
        return 'sandbox-txn-'.$merchantOrderId;
    }
}
