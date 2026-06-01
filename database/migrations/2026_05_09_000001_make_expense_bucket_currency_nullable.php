<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_buckets', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('expense_buckets', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable(false)->default('USD')->change();
        });
    }
};
