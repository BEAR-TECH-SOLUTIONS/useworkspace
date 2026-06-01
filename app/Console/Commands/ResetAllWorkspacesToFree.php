<?php

namespace App\Console\Commands;

use App\Enums\PlanTier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sandbox / dev helper: blow every workspace back to the Free
 * plan in one shot so the Paddle upgrade flow can be re-tested
 * from a clean slate without manually cancelling each subscription
 * through the portal.
 *
 * Scope of "reset":
 *   • tier                      → 'free'
 *   • seat_cap                  → PlanTier::Free->defaultSeatCap()
 *   • billing_status            → null
 *   • billing_subscription_id   → null
 *   • plan_started_at           → null
 *   • plan_renews_at            → null
 *   • plan_limits               → null  (any per-workspace overrides)
 *
 * What we keep:
 *   • billing_customer_id  — the Paddle customer record still exists
 *     on Paddle's side; we'd just have to recreate it on the next
 *     checkout if we wiped this. Keeping it preserves invoice
 *     history and lets the same customer hit /portal afterwards.
 *
 * Safety:
 *   • Refuses to run when APP_ENV=production unless --force.
 *     Forgetting that guard means a single fat-fingered cron entry
 *     downgrades every paying customer.
 *   • Does NOT call Paddle's API to cancel live subscriptions —
 *     this only resets our DB. If Paddle still thinks the
 *     subscription is active, the next webhook would re-set the
 *     tier. Use this command in concert with cancelling the
 *     corresponding sandbox subscriptions in Paddle's dashboard,
 *     or accept the resync.
 */
class ResetAllWorkspacesToFree extends Command
{
    protected $signature = 'tc:billing:reset-all-to-free
        {--force : Bypass the production guard. Required when APP_ENV=production.}';

    protected $description = 'Reset every workspace to the Free plan. Sandbox/dev helper — refuses to run in production without --force.';

    public function handle(): int
    {
        $env = (string) config('app.env', 'production');
        $force = (bool) $this->option('force');

        if ($env === 'production' && ! $force) {
            $this->error('Refusing to reset workspaces in production. Pass --force if you genuinely mean this.');

            return self::FAILURE;
        }

        $defaults = [
            'tier' => PlanTier::Free->value,
            'seat_cap' => PlanTier::Free->defaultSeatCap(),
            'billing_status' => null,
            'billing_subscription_id' => null,
            'plan_started_at' => null,
            'plan_renews_at' => null,
            'plan_limits' => null,
        ];

        $count = DB::transaction(static function () use ($defaults): int {
            return DB::table('organisations')->update($defaults);
        });

        $this->info("Reset {$count} workspace(s) to {$defaults['tier']} (seat_cap={$defaults['seat_cap']}).");

        if ($env === 'production') {
            $this->warn('You just reset workspaces in PRODUCTION. Paying customers are now on Free. Was that intentional?');
        }

        return self::SUCCESS;
    }
}
