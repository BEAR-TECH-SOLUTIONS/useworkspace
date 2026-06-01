<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task Resource Attachments spec §2. Lightweight cross-resource
 * references from a task to a credential / expense bucket / expense.
 * Metadata only — no ciphertext rides with the link; viewing the
 * target is gated at read time against the resource's own permission
 * plane. Deleting the target does NOT cascade this row (spec §8):
 * the link survives and surfaces as `has_access: false` until an
 * editor unlinks it.
 *
 * Also extends the `activity_action` enum with six new values so
 * attach/detach events write into the existing `task_activities`
 * table without schema churn.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE task_resource_link_kind AS ENUM ('credential', 'expense_bucket', 'expense')");

        Schema::create('task_resource_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_item_id')->constrained('task_items')->cascadeOnDelete();
            $table->unsignedBigInteger('resource_id');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('resource_id');
        });

        // resource_type lives on the enum type so Postgres rejects
        // junk values. Unique index is added after the column is in
        // place so it covers (task, type, id) together — same
        // credential and expense id can't trip the unique.
        DB::statement('ALTER TABLE task_resource_links ADD COLUMN resource_type task_resource_link_kind NOT NULL');
        DB::statement('CREATE UNIQUE INDEX task_resource_links_unique_pair ON task_resource_links (task_item_id, resource_type, resource_id)');

        // Extend activity_action with attach/detach values (spec §6).
        // Postgres ALTER TYPE ADD VALUE is non-transactional and
        // idempotent via IF NOT EXISTS.
        foreach ([
            'attached_credential',
            'attached_expense_bucket',
            'attached_expense',
            'detached_credential',
            'detached_expense_bucket',
            'detached_expense',
        ] as $value) {
            DB::statement("ALTER TYPE activity_action ADD VALUE IF NOT EXISTS '{$value}'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_resource_links');
        DB::statement('DROP TYPE IF EXISTS task_resource_link_kind');
        // Intentionally NOT removing the activity_action enum values —
        // Postgres doesn't support DROP VALUE, and any task_activities
        // rows referencing them would break on the re-create.
    }
};
