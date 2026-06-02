<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Hosting = 'hosting';
    case Domain = 'domain';
    case Saas = 'saas';
    case Software = 'software';
    case Hardware = 'hardware';
    case Service = 'service';
    case Other = 'other';
}
