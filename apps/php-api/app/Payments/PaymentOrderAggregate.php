<?php

namespace App\Payments;

use LogicException;

class PaymentOrderAggregate
{
    private string $status = 'pending';

    private bool $entitlementGranted = false;

    private int $compensationCount = 0;

    /** @var list<string> */
    private array $seenEventKeys = [];

    /** @var list<array{event_id:string,type:string,decision:string}> */
    private array $eventHistory = [];

    public function __construct(
        public readonly MerchantOrder $order,
        private readonly PaymentEventPolicy $policy = new PaymentEventPolicy,
    ) {}

    public function merchantOrderId(): string
    {
        return $this->order->merchantOrderId;
    }

    public function apply(ProviderPaymentEvent $event): PaymentDecision
    {
        $decision = $this->policy->decide(
            $this->order->merchantOrderId,
            $this->status,
            $this->order->amountMinor,
            $this->order->currency,
            $this->order->expiresAt,
            $event,
            $this->seenEventKeys,
        );
        if ($decision === PaymentDecision::Duplicate) {
            return $decision;
        }

        if ($decision === PaymentDecision::Apply) {
            switch ($event->type) {
                case PaymentEventType::Paid:
                    $this->applyPayment();
                    break;
                case PaymentEventType::Failed:
                    $this->status = 'failed';
                    break;
                case PaymentEventType::Refunded:
                    $this->compensateRefund();
                    break;
            }
        }

        $this->seenEventKeys[] = $event->idempotencyKey();
        $this->eventHistory[] = ['event_id' => $event->eventId, 'type' => $event->type->value, 'decision' => $decision->value];

        return $decision;
    }

    /** @return array{status:string,entitlement_granted:bool,compensation_count:int,events:list<array{event_id:string,type:string,decision:string}>} */
    public function snapshot(): array
    {
        return [
            'status' => $this->status,
            'entitlement_granted' => $this->entitlementGranted,
            'compensation_count' => $this->compensationCount,
            'events' => $this->eventHistory,
        ];
    }

    private function applyPayment(): void
    {
        if ($this->status !== 'pending') {
            throw new LogicException('Only pending orders can become paid.');
        }
        $this->status = 'paid';
        $this->entitlementGranted = true;
    }

    private function compensateRefund(): void
    {
        if ($this->status !== 'paid' || ! $this->entitlementGranted) {
            throw new LogicException('Only a granted payment can be refunded.');
        }
        $this->status = 'refunded';
        $this->entitlementGranted = false;
        $this->compensationCount++;
    }
}
