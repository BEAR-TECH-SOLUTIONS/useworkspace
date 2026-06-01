<?php

use App\Http\Middleware\EnsureMasterPasswordSet;
use App\Http\Middleware\Require2FA;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetShareLinkHeaders;
use App\Http\Middleware\ShareHostOnly;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // `withBroadcasting` both loads the channel auth callbacks from
    // channels.php AND registers the broadcast-auth HTTP route with
    // the prefix/middleware we actually want. Passing
    // `channels: ...` to `withRouting` as well causes Laravel to
    // register a SECOND, unprefixed `/broadcasting/auth` route under
    // the `web` middleware stack — pusher-js's default authEndpoint
    // (`/broadcasting/auth`) hits that one, the web-guard
    // authentication fails, and the exception handler tries to
    // redirect to a `login` named route this API-only app doesn't
    // define, producing `500 Route [login] not defined`.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api/v1', 'middleware' => ['auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Refuse non-share routes on the dedicated share host (cloud).
        // No-op when SHARE_UI_HOST is unset (self-hosted / local dev).
        $middleware->prepend(ShareHostOnly::class);

        // Append security headers to every response. Specific
        // middleware (e.g. SetShareLinkHeaders for the viewer Blade)
        // can pin a more permissive CSP before this layer runs.
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'master-password.set' => EnsureMasterPasswordSet::class,
            '2fa' => Require2FA::class,
            'share-link-headers' => SetShareLinkHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-only backend: explicitly render every
        // AuthenticationException as a JSON 401, regardless of the
        // request's Accept header. This sidesteps Laravel's default
        // unauthenticated() handler which uses `$request->expectsJson()`
        // directly (NOT shouldRenderJsonWhen) and falls back to
        // `redirect()->guest(route('login'))`. Since this app doesn't
        // define a `login` named route, that redirect throws
        // RouteNotFoundException → 500 on any browser-issued request
        // (`Accept: text/html,…`) hitting an authenticated endpoint.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Unauthenticated.',
            ], 401);
        });

        // Belt-and-braces for every OTHER exception type — if anything
        // on an `api/*` route would otherwise render HTML, force JSON.
        // (Doesn't fix unauthenticated specifically — that's the
        // render() closure above — but catches misc. exceptions on
        // the API surface.)
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $e): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
