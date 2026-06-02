<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expense Payments spec §1. Each row is one "I paid this" event.
 * `amount` + `currency` are snapshotted from the expense at payment
 * time so the historical record stays accurate even if the expense
 * amount changes later.
 *
 * Also extends `expense_billing_cycle` with the four new cycles the
 * spec's date-advance table requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Extend billing_cycle with new values. ALTER TYPE ADD VALUE is
        // non-transactional and idempotent via IF NOT EXISTS.
        foreach (['weekly', 'bi_weekly', 'bi_monthly', 'semi_annual'] as $value) {
            DB::statement("ALTER TYPE expense_billing_cycle ADD VALUE IF NOT EXISTS '{$value}'");
        }

        Schema::create('expense_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->date('paid_at');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['expense_id', 'paid_at'], 'idx_expense_payments_paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
        // Intentionally NOT removing the billing_cycle enum values —
        // Postgres doesn't support DROP VALUE.
    }
};
