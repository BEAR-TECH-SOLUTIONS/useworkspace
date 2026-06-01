<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks authed requests until the user has uploaded their master-password
 * crypto bundle via POST /api/v1/auth/master-password.
 *
 * Exempt routes (handled outside the middleware because they need to be
 * reachable precisely *because* the user hasn't set up their bundle yet):
 *   - POST /api/v1/logout
 *   - GET  /api/v1/auth/me
 *   - POST /api/v1/auth/master-password
 *
 * Every other authed endpoint is behind this gate. A blocked request returns
 * `409 Conflict` with `code: master_password_required` so the client can route
 * the user straight to the setup screen.
 */
class EnsureMasterPasswordSet
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null && ! $user->hasMasterPassword()) {
            return response()->json([
                'message' => 'Master password setup is required before using this endpoint.',
                'code' => 'master_password_required',
            ], 409);
        }

        return $next($request);
    }
}
