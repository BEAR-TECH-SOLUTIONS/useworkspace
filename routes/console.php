<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('shares:prune')->hourly();
Schedule::command('shares:auto-revoke-stale')->weeklyOn(1, '04:00');
Schedule::command('tasks:auto-archive')->hourlyAt(15);
Schedule::command('expenses:roll-due-dates')->hourlyAt(45);

// Daily FX rate refresh. One upstream call to exchangeratesapi.io
// produces the full cross-rate matrix for every supported currency
// pair, cached for 25 hours so reads survive small clock skew on
// the next day's run.
Schedule::command('fx:fetch')->dailyAt('02:30');

// Daily notification sweeps. Run at staggered minutes so they don't
// contend for DB connections with each other or with hourly jobs.
Schedule::command('notifications:task-due-soon')->dailyAt('08:00');
Schedule::command('notifications:task-overdue')->dailyAt('08:10');
Schedule::command('notifications:expense-due-soon')->dailyAt('08:20');
Schedule::command('notifications:cleanup')->dailyAt('03:00');

// Purge expired 2FA login challenges — lightweight inline closure
// since the logic is a single DELETE with no domain dependencies.
Schedule::call(function (): void {
    DB::table('two_factor_challenges')->where('expires_at', '<', now())->delete();
})->hourlyAt(30)->name('purge-2fa-challenges');

// Sandbox/dev only: nightly reset of every workspace to Free so
// the Paddle upgrade flow can be retested from a clean slate
// without manually cancelling each subscription through the
// portal. Guarded by APP_ENV so the entry doesn't exist at all
// in production — safer than relying on the command's own
// --force guard, since the entry simply isn't there for the
// scheduler to pick up.
if ((string) config('app.env', 'production') !== 'production') {
    Schedule::command('tc:billing:reset-all-to-free')
        ->dailyAt('04:00')
        ->name('billing-reset-all-to-free')
        ->onOneServer();
}
