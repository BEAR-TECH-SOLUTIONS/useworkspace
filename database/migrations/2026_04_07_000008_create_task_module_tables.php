<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_boards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index('project_id');
        });
        DB::statement('CREATE UNIQUE INDEX task_boards_default_unique ON task_boards (project_id) WHERE is_default');

        Schema::create('task_columns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->double('position')->default(10000);
            $table->integer('wip_limit')->nullable();
            $table->timestamps();
            $table->index(['board_id', 'position']);
        });

        Schema::create('task_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 7);
            $table->timestamps();
            $table->index('project_id');
        });

        Schema::create('task_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('column_id')->constrained('task_columns')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->double('position')->default(10000);
            $table->date('due_date')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['project_id', 'is_archived']);
            $table->index(['column_id', 'position']);
        });

        DB::statement("ALTER TABLE task_items ADD COLUMN priority task_priority NOT NULL DEFAULT 'medium'");

        Schema::create('task_item_labels', function (Blueprint $table): void {
            $table->foreignId('task_item_id')->constrained('task_items')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('task_labels')->cascadeOnDelete();
            $table->primary(['task_item_id', 'label_id']);
        });

        Schema::create('task_assignees', function (Blueprint $table): void {
            $table->foreignId('task_item_id')->constrained('task_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['task_item_id', 'user_id']);
        });

        Schema::create('task_checklists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_item_id')->constrained('task_items')->cascadeOnDelete();
            $table->text('text');
            $table->boolean('is_checked')->default(false);
            $table->double('position')->default(10000);
            $table->timestamps();
            $table->index(['task_item_id', 'position']);
        });

        Schema::create('task_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_item_id')->constrained('task_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('task_comments')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['task_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_checklists');
        Schema::dropIfExists('task_assignees');
        Schema::dropIfExists('task_item_labels');
        Schema::dropIfExists('task_items');
        Schema::dropIfExists('task_labels');
        Schema::dropIfExists('task_columns');
        Schema::dropIfExists('task_boards');
    }
};
