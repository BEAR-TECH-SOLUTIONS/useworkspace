<?php

namespace App\Services\Sharing;

use RuntimeException;

class SnapshotTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $size, public readonly int $cap, public readonly string $resourceType)
    {
        parent::__construct("Snapshot for {$resourceType} is {$size} bytes; cap is {$cap}.");
    }
}
