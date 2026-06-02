<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $types = [
        'activity_action' => "'created', 'updated', 'completed', 'reopened', 'moved',
            'archived', 'unarchived',
            'checklist_added', 'checklist_checked', 'checklist_unchecked',
            'commented', 'assigned', 'unassigned', 'labeled', 'unlabeled',
            'column_created', 'column_renamed', 'column_deleted',
            'attached_credential', 'attached_expense_bucket', 'attached_expense',
            'detached_credential', 'detached_expense_bucket', 'detached_expense'",
        'expense_category' => "'hosting', 'domain', 'saas', 'software', 'hardware', 'service', 'other'",
        'expense_billing_cycle' => "'one_time', 'weekly', 'bi_weekly', 'monthly', 'bi_monthly', 'quarterly', 'semi_annual', 'yearly'",
        'entry_type' => "'login', 'ssh', 'ftp', 'database', 'api_key', 'note', 'software_license', 'env'",
        'task_priority' => "'low', 'medium', 'high', 'urgent'",
        'resource_kind' => "'project', 'board', 'vault', 'bucket'",
        'organisation_role' => "'admin', 'member'",
        'member_role' => "'owner', 'editor', 'viewer'",
    ];

    public function up(): void
    {
        // `migrate:fresh` drops tables but not Postgres enum types, so a fresh
        // run would trip over existing types left behind by the previous run.
        // Drop-then-create makes this migration idempotent.
        foreach (array_keys($this->types) as $type) {
            DB::statement("DROP TYPE IF EXISTS {$type} CASCADE");
        }

        foreach (array_reverse($this->types, preserve_keys: true) as $name => $values) {
            DB::statement("CREATE TYPE {$name} AS ENUM ({$values})");
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->types) as $type) {
            DB::statement("DROP TYPE IF EXISTS {$type} CASCADE");
        }
    }
};