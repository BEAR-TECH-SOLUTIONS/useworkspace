<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Raised by PlanEnforcer (cloud) and LicenseEnforcer (self-hosted)
 * when a mutation would exceed the workspace's plan caps. Surfaces as
 * 422 with a flat `{ code, message }` envelope so the desktop client
 * can pattern-match on `code` regardless of edition.
 *
 * Not a ValidationException — this is a billing concern, not a field
 * validation failure, and the response shape stays terse on purpose
 * (no nested `errors` map).
 */
class PlanLimitExceeded extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], 422);
    }
}
