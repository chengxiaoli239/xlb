<?php

namespace App\Payments;

use LogicException;

class InMemoryPaymentEventStore implements PaymentEventStore
{
    /** @var array<string, PaymentOrderAggregate> */
    private array $orders = [];

    public function add(PaymentOrderAggregate $order): void
    {
        $id = $order->merchantOrderId();
        if (isset($this->orders[$id])) {
            throw new LogicException('Merchant order already exists in the in-memory payment store.');
        }
        $this->orders[$id] = $order;
    }

    public function find(string $merchantOrderId): ?PaymentOrderAggregate
    {
        return $this->orders[$merchantOrderId] ?? null;
    }

    public function save(PaymentOrderAggregate $order): void
    {
        if (! isset($this->orders[$order->merchantOrderId()])) {
            throw new LogicException('Cannot save an unregistered merchant order.');
        }
        $this->orders[$order->merchantOrderId()] = $order;
    }
}
