<?php

namespace App\Payments;

interface PaymentEventStore
{
    public function add(PaymentOrderAggregate $order): void;

    public function find(string $merchantOrderId): ?PaymentOrderAggregate;

    public function save(PaymentOrderAggregate $order): void;
}
