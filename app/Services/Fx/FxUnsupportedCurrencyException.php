<?php

namespace App\Services\Fx;

use RuntimeException;
use Throwable;

class FxUnsupportedCurrencyException extends RuntimeException
{
    public function __construct(public readonly string $currency, ?Throwable $previous = null)
    {
        parent::__construct("Currency {$currency} is not supported by the FX provider.", 0, $previous);
    }
}
