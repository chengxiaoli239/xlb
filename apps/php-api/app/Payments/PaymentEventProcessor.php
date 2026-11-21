<?php

namespace App\Payments;

use RuntimeException;

class PaymentEventProcessor
{
    public function __construct(private readonly PaymentEventStore $store) {}

    public function register(MerchantOrder $order): PaymentOrderAggregate
    {
        $aggregate = new PaymentOrderAggregate($order);
        $this->store->add($aggregate);

        return $aggregate;
    }

    public function process(ProviderPaymentEvent $event): PaymentDecision
    {
        $order = $this->store->find($event->merchantOrderId);
        if (! $order) {
            return PaymentDecision::Review;
        }
        $decision = $order->apply($event);
        $this->store->save($order);

        return $decision;
    }

    /** @return array{status:string,entitlement_granted:bool,compensation_count:int,events:list<array{event_id:string,type:string,decision:string}>} */
    public function snapshot(string $merchantOrderId): array
    {
        $order = $this->store->find($merchantOrderId)
            ?? throw new RuntimeException('Merchant order is not registered.');

        return $order->snapshot();
    }
}
