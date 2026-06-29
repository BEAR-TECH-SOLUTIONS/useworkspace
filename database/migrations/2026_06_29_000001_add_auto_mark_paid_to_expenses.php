<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #220 — recurring expenses can opt into being marked paid automatically.
 * A daily `expenses:auto-pay` command records a payment for every elapsed
 * cycle of an expense with this flag set. Only meaningful for recurring
 * billing cycles; the API coerces it to false for one-time expenses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->boolean('auto_mark_paid')->default(false)->after('next_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn('auto_mark_paid');
        });
    }
};
