<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_buckets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->char('currency', 3)->default('USD');
            $table->string('color', 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index('project_id');
        });
        DB::statement('CREATE UNIQUE INDEX expense_buckets_default_unique ON expense_buckets (project_id) WHERE is_default');

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('bucket_id')->constrained('expense_buckets')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('vendor')->nullable();
            $table->date('next_due_date')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['project_id', 'next_due_date']);
        });

        DB::statement('ALTER TABLE expenses ADD COLUMN category expense_category NOT NULL');
        DB::statement('ALTER TABLE expenses ADD COLUMN billing_cycle expense_billing_cycle NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_buckets');
    }
};
