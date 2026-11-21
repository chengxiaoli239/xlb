<?php

namespace App\Payments;

final readonly class PaymentReconciliationReport
{
    /**
     * @param  list<PaymentReconciliationEntry>  $entries
     */
    public function __construct(public array $entries) {}

    public function count(string $outcome): int
    {
        return count(array_filter($this->entries, static fn (PaymentReconciliationEntry $entry): bool => $entry->outcome === $outcome));
    }
}
