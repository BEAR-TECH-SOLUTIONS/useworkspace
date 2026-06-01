<?php

namespace App\Services\Workspaces\Billing;

use RuntimeException;

class BillingNotConfiguredException extends RuntimeException
{
    public function __construct(string $message = 'Billing is not configured on this environment.')
    {
        parent::__construct($message);
    }
}
