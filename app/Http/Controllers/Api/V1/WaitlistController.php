<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Waitlist\StoreWaitlistRequest;
use App\Models\WaitlistSignup;
use App\Support\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Public landing-page waitlist signup. Single-opt-in for v1 — the
 * landing collects an email and we file it. No auth, but layered spam
 * defences are in place (see below).
 *
 * Spam protection layers, by order of effectiveness:
 *  1. Per-IP throttle (`waitlist` rate limiter): 5/min, 20/hour.
 *  2. Honeypot (`website` field): bots fill it; we silently accept
 *     and return the same 201 a real signup would so they can't tell.
 *  3. Email validation: `email:rfc,dns` rejects obviously-bogus
 *     addresses (no MX record etc.) before any DB write.
 *  4. Idempotency on `email`: duplicates are swallowed by the unique
 *     index. The response shape is identical regardless of whether
 *     the email was new or already on the list, so attackers can't
 *     enumerate registered addresses by probing.
 *
 * Out of scope for v1 (track in product backlog): CAPTCHA / Turnstile,
 * disposable-email blocklist, double-opt-in confirmation flow,
 * unsubscribe endpoint.
 */
class WaitlistController extends Controller
{
    public function store(StoreWaitlistRequest $request): JsonResponse
    {
        // Honeypot tripped → look identical to a successful signup
        // from the bot's perspective. Don't write anything.
        if ($request->isHoneypotTripped()) {
            return $this->okResponse();
        }

        $email = strtolower(trim($request->string('email')->toString()));
        $source = $request->filled('source') ? $request->string('source')->toString() : null;
        $metadata = $request->input('metadata');

        // INSERT … ON CONFLICT DO NOTHING keeps the response uniform —
        // the unique index swallows duplicates without ever raising a
        // 409. Returning bool from insert() lets us avoid a SELECT
        // round-trip just to know whether the row existed.
        DB::table('waitlist_signups')->insertOrIgnore([
            'email' => $email,
            'source' => $source,
            'metadata' => $metadata !== null ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            'ip_hash' => IpHasher::hash($request->ip()),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return $this->okResponse();
    }

    private function okResponse(): JsonResponse
    {
        return response()->json([
            'message' => "You're on the waitlist. We'll be in touch.",
        ], 201);
    }
}
