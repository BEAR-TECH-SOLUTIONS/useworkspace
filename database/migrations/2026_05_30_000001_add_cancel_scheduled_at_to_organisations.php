<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the effective date of a scheduled subscription cancellation.
 *
 * Paddle's "cancel at next billing period" leaves the subscription
 * active until period end, so until that webhook fires the customer
 * retains access. The billing UI needs to show "Subscription ends on
 * X" during that window; we pin X here so a page reload doesn't need
 * to round-trip to Paddle.
 *
 * NULL means no cancellation is pending — either there's no
 * subscription, the customer hasn't asked to cancel, or the cancel
 * already took effect (the subscription.canceled webhook clears this
 * column when it flips tier back to Free).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->timestampTz('cancel_scheduled_at')->nullable()->after('plan_renews_at');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('cancel_scheduled_at');
        });
    }
};
