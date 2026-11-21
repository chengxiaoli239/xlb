<?php

namespace App\Payments;

enum PaymentDecision: string
{
    case Apply = 'apply';
    case Duplicate = 'duplicate';
    case Review = 'review';
    case Ignore = 'ignore';
}
