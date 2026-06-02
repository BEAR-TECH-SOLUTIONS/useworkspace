<?php

namespace App\Enums;

enum WorkspaceBillingStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Trialing = 'trialing';
}
