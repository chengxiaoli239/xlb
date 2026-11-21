<?php

namespace App\Payments;

enum PaymentEventType: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
