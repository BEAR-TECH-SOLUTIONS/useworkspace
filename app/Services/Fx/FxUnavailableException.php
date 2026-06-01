<?php

namespace App\Services\Fx;

use RuntimeException;
use Throwable;

class FxUnavailableException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('FX provider is unavailable and no recent cached rates exist.', 0, $previous);
    }
}
