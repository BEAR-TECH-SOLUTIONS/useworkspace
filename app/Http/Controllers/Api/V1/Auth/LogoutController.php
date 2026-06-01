<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class LogoutController extends Controller
{
    public function __construct(private readonly TwoFactorVerification $verification) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        /** @var PersonalAccessToken|null $token */
        $token = $user?->currentAccessToken();

        // Audit L9: forget the token-scoped 2fa_verified flag on
        // logout so a re-login on the same token id (Sanctum may
        // recycle ids over very long horizons) doesn't inherit
        // someone else's verification. Best-effort even if the
        // currentAccessToken isn't a PersonalAccessToken.
        if ($user instanceof User) {
            $this->verification->forget(
                $user,
                $token instanceof PersonalAccessToken ? (int) $token->id : null,
            );
        }

        $token?->delete();

        return response()->json(status: 204);
    }
}
