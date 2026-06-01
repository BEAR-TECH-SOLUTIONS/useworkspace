<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('board_id')->nullable()->constrained('task_boards')->cascadeOnDelete();
            $table->foreignId('task_item_id')->nullable()->constrained('task_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->text('field')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE task_activities ADD COLUMN action activity_action NOT NULL');
        DB::statement('CREATE INDEX task_activities_task_idx  ON task_activities (task_item_id, created_at DESC)');
        DB::statement('CREATE INDEX task_activities_board_idx ON task_activities (board_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
    }
};
