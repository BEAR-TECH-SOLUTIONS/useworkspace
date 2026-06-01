<?php

use App\Http\Controllers\Api\V1\PublicShareViewController;
use Illuminate\Support\Facades\Route;

// Audit M1: the API host should not serve Laravel's default Welcome
// Blade — it leaks framework + version banners and gives crawlers a
// "PHP/Laravel app here" tell. Return a minimal 404 instead; the
// public landing site lives on a different host entirely.
Route::get('/', function () {
    abort(404);
});

// Share-link viewer SPA. Same-origin route works for self-hosted +
// local dev. The cloud's dedicated `share.usework.space` host catches
// EVERY path and serves the same Blade — the SPA router takes over
// client-side from there. The `ShareHostOnly` middleware (registered
// globally in bootstrap/app.php) refuses non-share paths on that host
// so the rest of the API isn't reachable through it.
Route::middleware('share-link-headers')->group(function (): void {
    Route::get('/s/{tokenHash}', [PublicShareViewController::class, 'show'])
        ->where('tokenHash', '[a-f0-9]{64}')
        ->name('share.view');

    $shareHost = (string) config('teamcore.share.host', '');
    if ($shareHost !== '') {
        Route::domain($shareHost)->group(function (): void {
            Route::get('/{any?}', [PublicShareViewController::class, 'show'])
                ->where('any', '.*')
                ->name('share.host.catchall');
        });
    }
});
