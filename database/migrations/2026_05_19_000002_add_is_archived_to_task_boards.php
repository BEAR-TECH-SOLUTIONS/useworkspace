<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit M8: the existing TaskBoardController::archive endpoint
 * recorded the activity row but did not actually flip any state, so
 * "archived" boards continued to appear in the sidebar. Add the
 * is_archived column + the archived_at timestamp so the endpoint
 * can do what its name claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_boards', function (Blueprint $table): void {
            $table->boolean('is_archived')->default(false)->index();
            $table->timestampTz('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_boards', function (Blueprint $table): void {
            $table->dropColumn(['is_archived', 'archived_at']);
        });
    }
};
