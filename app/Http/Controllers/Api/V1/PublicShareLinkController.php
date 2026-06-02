<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sharing\UnlockShareLinkRequest;
use App\Http\Resources\Sharing\PublicShareLinkResource;
use App\Events\Sharing\ShareLinkViewed;
use App\Models\Vault\ShareLink;
use App\Models\Vault\ShareLinkView;
use App\Services\Sharing\BruteForceGuard;
use App\Services\Sharing\ShareLinkPasswordHasher;
use App\Support\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Unauthenticated endpoints for the universal share-link flow.
 *
 * The server only ever sees `token_hash`, `auth_proof_hash`, and the
 * snapshot — never the plaintext token, never the password, never the
 * recipient's `enc_key` (for credentials). DB compromise leaks no
 * usable URLs and no decryptable credentials.
 *
 * Plan §6 (controllers) + §"Zero-knowledge crypto for credential shares".
 */
class PublicShareLinkController extends Controller
{
    public function __construct(
        private readonly ShareLinkPasswordHasher $passwords,
        private readonly BruteForceGuard $bruteForce,
    ) {}

    /**
     * GET /api/v1/share-links/{tokenHash}
     *
     * Returns the metadata + auth_scheme. For open links, also returns
     * the snapshot directly and increments view_count. For password /
     * auth_proof links, the snapshot is withheld until /unlock.
     */
    public function show(Request $request, string $tokenHash): JsonResponse
    {
        $link = $this->lookup($tokenHash);

        if ($link === null || ! $link->isActive()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($link->max_views !== null && $link->view_count >= $link->max_views) {
            return response()->json(['message' => 'This share link has reached its view limit.'], 410);
        }

        $scheme = $link->authScheme();

        $body = [
            'share_link' => (new PublicShareLinkResource($link))->resolve(),
            'auth_scheme' => $scheme,
        ];

        if ($scheme === 'auth_proof') {
            // Recipient client needs the salt to derive auth_proof
            // locally — without it, no second round-trip can succeed.
            $body['key_salt'] = $link->snapshot_payload['key_salt'] ?? null;
        }

        if ($scheme === 'open') {
            // Same lockForUpdate dance the /unlock path runs (audit H12).
            // Without this, two concurrent GETs against a max_views=N
            // link both pass the pre-check, both increment, and the
            // link can be viewed N+1 times. Re-read inside the lock,
            // re-check, then commit.
            $result = DB::transaction(function () use ($link, $request): array {
                $locked = ShareLink::query()->whereKey($link->id)->lockForUpdate()->first();

                if ($locked === null) {
                    return ['__error' => 'gone'];
                }

                if ($locked->max_views !== null && $locked->view_count >= $locked->max_views) {
                    return ['__error' => 'view_limit'];
                }

                $locked->increment('view_count');
                $this->recordView($locked, $request, wasUnlocked: true);

                if ($locked->max_views !== null && $locked->view_count >= $locked->max_views) {
                    $locked->update(['revoked_at' => Carbon::now()]);
                }

                return ['snapshot_payload' => $locked->snapshot_payload];
            });

            if (isset($result['__error']) && $result['__error'] === 'view_limit') {
                return response()->json(['message' => 'This share link has reached its view limit.'], 410);
            }

            if (isset($result['__error']) && $result['__error'] === 'gone') {
                return response()->json(['message' => 'Not found.'], 404);
            }

            ShareLinkViewed::dispatch($link->refresh(), false);
            $body['snapshot_payload'] = $result['snapshot_payload'];
        }

        return response()->json($body);
    }

    /**
     * POST /api/v1/share-links/{tokenHash}/unlock
     *
     * Branches on auth_scheme:
     *  - password   → Hash::driver('share_link_argon')->check
     *  - auth_proof → sha256(base64_decode(auth_proof)) === auth_proof_hash
     */
    public function unlock(UnlockShareLinkRequest $request, string $tokenHash): JsonResponse
    {
        $link = $this->lookup($tokenHash);

        if ($link === null || ! $link->isActive()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($link->max_views !== null && $link->view_count >= $link->max_views) {
            return response()->json(['message' => 'This share link has reached its view limit.'], 410);
        }

        $candidate = hash('sha256', $request->string('token')->toString());
        if (! hash_equals($link->token_hash, $candidate)) {
            return response()->json(['message' => 'Invalid token.'], 403);
        }

        $valid = $this->verifyAuth($link, $request);
        if (! $valid) {
            $autoRevoked = $this->bruteForce->recordFailure($link);

            return response()->json([
                'message' => $autoRevoked
                    ? 'This share link has been auto-revoked after repeated failed attempts.'
                    : 'Invalid password.',
                'code' => $autoRevoked ? 'auto_revoked' : 'invalid_password',
            ], $autoRevoked ? 410 : 401);
        }

        // Successful unlock — clear the per-token brute-force counter
        // so an honest mis-type doesn't carry over into a tripping
        // total later.
        $this->bruteForce->reset($link);

        $payload = DB::transaction(function () use ($link, $request): array {
            // SELECT FOR UPDATE prevents the well-known concurrent-view
            // overcount on max_views=N links — re-read inside the lock,
            // re-check, then commit. Plan test §15.
            $locked = ShareLink::query()->whereKey($link->id)->lockForUpdate()->first();

            if ($locked === null) {
                return ['__error' => 'gone'];
            }

            if ($locked->max_views !== null && $locked->view_count >= $locked->max_views) {
                return ['__error' => 'view_limit'];
            }

            $locked->increment('view_count');
            $this->recordView($locked, $request, wasUnlocked: true);

            if ($locked->max_views !== null && $locked->view_count >= $locked->max_views) {
                $locked->update(['revoked_at' => Carbon::now()]);
            }

            return ['snapshot_payload' => $locked->snapshot_payload];
        });

        if (isset($payload['__error']) && $payload['__error'] === 'view_limit') {
            return response()->json(['message' => 'This share link has reached its view limit.'], 410);
        }

        if (isset($payload['__error']) && $payload['__error'] === 'gone') {
            return response()->json(['message' => 'Not found.'], 404);
        }

        ShareLinkViewed::dispatch($link->refresh(), true);

        return response()->json($payload);
    }

    private function verifyAuth(ShareLink $link, UnlockShareLinkRequest $request): bool
    {
        return match ($link->authScheme()) {
            'open' => true,
            'password' => $this->verifyPassword($link, $request->string('password')->toString()),
            'auth_proof' => $this->verifyAuthProof($link, $request->string('auth_proof')->toString()),
        };
    }

    private function verifyPassword(ShareLink $link, string $supplied): bool
    {
        if ($supplied === '' || $link->password_hash === null) {
            return false;
        }

        return $this->passwords->verify($supplied, $link->password_hash);
    }

    private function verifyAuthProof(ShareLink $link, string $supplied): bool
    {
        if ($supplied === '' || $link->auth_proof_hash === null) {
            return false;
        }

        $decoded = base64_decode($supplied, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            return false;
        }

        return hash_equals($link->auth_proof_hash, hash('sha256', $decoded));
    }

    private function recordView(ShareLink $link, Request $request, bool $wasUnlocked): void
    {
        ShareLinkView::create([
            'share_link_id' => $link->id,
            'ip_hash' => IpHasher::hash($request->ip()),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        // For auth_scheme=open, increment is done here (no separate
        // unlock step). For password/auth_proof flows, the unlock
        // transaction increments inside lockForUpdate.
        if (! $wasUnlocked) {
            $link->increment('view_count');

            if ($link->max_views !== null && $link->view_count >= $link->max_views) {
                $link->update(['revoked_at' => Carbon::now()]);
            }
        }
    }

    private function lookup(string $tokenHash): ?ShareLink
    {
        if (strlen($tokenHash) !== 64 || ! ctype_xdigit($tokenHash)) {
            return null;
        }

        return ShareLink::query()->where('token_hash', $tokenHash)->first();
    }
}
