<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RotateMasterPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\TwoFactorVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/v1/auth/master-password
 *
 * Rotates a user's master-password crypto bundle. Client-side the
 * user has decrypted their existing RSA private key with the old
 * master password and re-wrapped it under a fresh password + salt.
 * The server only persists ciphertext + verifier + iv + salt. The
 * RSA public key MUST NOT change — every wrapped vault key
 * (`resource_keys.encrypted_key`) is bound to it; rewriting public_key
 * would orphan every grant the user holds. Hence the FormRequest
 * rejects any incoming public_key field, and this controller only
 * touches the four bundle columns.
 *
 * Distinct from {@see SetupMasterPasswordController} which handles
 * the one-time FIRST-time setup (and 409s on the second call). The
 * rotation contract is the inverse: it requires master_password_set
 * to already be true.
 *
 * Security model:
 *   • Caller must be authenticated (auth:sanctum).
 *   • current_password is required (audit H8) — verified by the
 *     FormRequest's Rule::currentPassword. Closes the stolen-bearer
 *     lock-out vector since the server cannot verify the OLD
 *     master password.
 *   • If 2FA is enabled, the same 10-minute `2fa_verified` cache
 *     entry that gates rotate-key / delete-project must be present.
 *   • Route is throttled via `password-change` (same limit as
 *     PUT /auth/password). The verifier is never read back.
 */
class RotateMasterPasswordController extends Controller
{
    public function __construct(private readonly TwoFactorVerification $verification) {}

    public function __invoke(RotateMasterPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Precondition: there must already be a bundle to rotate. A
        // fresh account that hasn't completed setup uses the POST
        // (one-time setup) endpoint; surfacing a distinct code here
        // lets the client redirect to that flow without guessing.
        if (! $user->hasMasterPassword()) {
            return response()->json([
                'message' => 'Set up your master password before rotating it.',
                'code' => 'master_password_required',
            ], 409);
        }

        // 2FA re-verification gate. We don't fire this when 2FA is
        // off — current_password is the lone factor in that case,
        // same as PUT /auth/password.
        if ($user->two_factor_enabled && ! $this->verification->verifiedRequest($request)) {
            return response()->json([
                'message' => 'Please verify your two-factor code before rotating the master password.',
                'code' => 'two_factor_verification_required',
            ], 403);
        }

        // Overwrite ONLY the four bundle columns. public_key,
        // master_password_salt → kept-public-key invariant lives in
        // the FormRequest's after-validator (rejects any public_key
        // field on the request). We don't touch master_password_set
        // / hasMasterPassword() — it stays derived-true because all
        // four columns remain non-null.
        DB::transaction(static function () use ($user, $request): void {
            $user->forceFill([
                'master_password_salt' => $request->string('master_password_salt')->toString(),
                'master_password_verifier' => $request->string('master_password_verifier')->toString(),
                'encrypted_private_key' => $request->string('encrypted_private_key')->toString(),
                'private_key_iv' => $request->string('private_key_iv')->toString(),
            ])->save();
        });

        return response()->json([
            'user' => new UserResource($user->refresh()),
        ]);
    }
}
