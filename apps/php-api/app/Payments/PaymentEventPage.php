<?php

namespace App\Payments;

final readonly class PaymentEventPage
{
    /**
     * @param  list<ProviderPaymentEvent>  $events
     */
    public function __construct(public array $events, public ?string $nextCursor) {}
}
