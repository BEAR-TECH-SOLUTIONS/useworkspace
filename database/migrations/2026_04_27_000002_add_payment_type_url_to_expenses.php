<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds payment_type, payment_method_other, and url to expenses.
 *
 * payment_type is a CHECK-bounded VARCHAR (kept as VARCHAR rather than
 * a Postgres ENUM because we expect to add new payment methods over
 * time — extending a CHECK list is cheaper than ALTER TYPE).
 *
 * payment_method_other is required exactly when payment_type='other'
 * — enforced at the DB layer so direct SQL writes can't drift.
 *
 * No labels here. The previous label-table spec was withdrawn; tag
 * coloring lives on the client (deterministic hash of the tag string).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('payment_type', 32)->nullable()->after('vendor');
            $table->string('payment_method_other', 120)->nullable()->after('payment_type');
            $table->string('url', 500)->nullable()->after('payment_method_other');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expenses
            ADD CONSTRAINT expenses_payment_type_check
            CHECK (payment_type IS NULL OR payment_type IN (
                'card','bank_transfer','paypal','crypto',
                'cash','check','direct_debit','other'
            ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE expenses
            ADD CONSTRAINT expenses_payment_method_other_only_for_other
            CHECK (
                (payment_type = 'other' AND payment_method_other IS NOT NULL AND length(payment_method_other) > 0)
                OR (payment_type IS DISTINCT FROM 'other' AND payment_method_other IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_payment_method_other_only_for_other');
        DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_payment_type_check');

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn(['payment_type', 'payment_method_other', 'url']);
        });
    }
};
