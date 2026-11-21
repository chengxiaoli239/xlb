<?php

namespace App\Payments;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class PaymentReconciliationService
{
    public function reconcile(PaymentProvider $provider, DateTimeImmutable $from, DateTimeImmutable $until): PaymentReconciliationReport
    {
        if ($until < $from) {
            throw new \InvalidArgumentException('Reconciliation window end must not precede its start.');
        }

        $remoteEvents = [];
        $cursor = null;
        do {
            $page = $provider->listEvents($from, $until, $cursor);
            foreach ($page->events as $event) {
                $remoteEvents[$event->idempotencyKey()] = $event;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        $localRows = DB::table('provider_payment_events')->where('provider', $provider->providerCode())->get();
        $localEvents = [];
        foreach ($localRows as $row) {
            $occurredAt = new DateTimeImmutable($row->occurred_at);
            if ($occurredAt >= $from && $occurredAt <= $until) {
                $localEvents[$row->provider.':'.$row->event_id] = $row;
            }
        }

        $entries = [];
        foreach ($remoteEvents as $key => $event) {
            $row = $localEvents[$key] ?? null;
            $outcome = $row === null ? 'missing_local' : ($this->matches($row, $event) ? 'matched' : 'mismatch');
            $entries[] = new PaymentReconciliationEntry($event->provider, $event->eventId, $event->merchantOrderId, $outcome);
            unset($localEvents[$key]);
        }
        foreach ($localEvents as $row) {
            $entries[] = new PaymentReconciliationEntry($row->provider, $row->event_id, $row->merchant_order_id, 'missing_provider');
        }
        usort($entries, static fn (PaymentReconciliationEntry $left, PaymentReconciliationEntry $right): int => [$left->provider, $left->eventId] <=> [$right->provider, $right->eventId]);

        return new PaymentReconciliationReport($entries);
    }

    private function matches(object $stored, ProviderPaymentEvent $event): bool
    {
        return $stored->merchant_order_id === $event->merchantOrderId
            && $stored->channel_transaction_id === $event->channelTransactionId
            && (int) $stored->amount_minor === $event->amountMinor
            && $stored->currency === $event->currency
            && $stored->event_type === $event->type->value
            && $stored->occurred_at === $event->occurredAt->format(DATE_ATOM);
    }
}
