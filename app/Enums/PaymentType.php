<?php

namespace App\Enums;

/**
 * How an expense is paid. Free-form on `Other` — the matching column
 * `payment_method_other` carries the human-typed description and is
 * required exactly when this enum is `Other`.
 */
enum PaymentType: string
{
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Paypal = 'paypal';
    case Crypto = 'crypto';
    case Cash = 'cash';
    case Check = 'check';
    case DirectDebit = 'direct_debit';
    case Other = 'other';
}
