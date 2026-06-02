<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Docs — Notion-style rich-text documents inside projects. Same access
 * model as boards/buckets (no crypto, plaintext on the server) with
 * Pattern A cascade + Pattern B direct grants via resource_permissions.
 *
 * Extends three Postgres enums in one atomic migration:
 *   - resource_kind: add 'doc' so resource_permissions (Pattern B
 *     grants) and the access-map aggregation can reference docs.
 *   - task_resource_link_kind: add 'doc' so tasks can attach docs via
 *     the existing task_resource_links table.
 *
 * Postgres `ALTER TYPE ADD VALUE` is non-transactional but idempotent
 * with IF NOT EXISTS — same pattern used for activity_action in
 * 2026_04_15_000008_create_task_resource_links_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE resource_kind ADD VALUE IF NOT EXISTS 'doc'");
        DB::statement("ALTER TYPE task_resource_link_kind ADD VALUE IF NOT EXISTS 'doc'");

        Schema::create('docs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title', 500);
            $table->jsonb('content')->default(DB::raw("'{}'::jsonb"));

            // Plaintext extraction for full-text search. Populated on
            // write by the controller (Tiptap JSON → concatenated text)
            // so the client can search docs alongside tasks and
            // credentials via /projects/{p}/search without parsing
            // JSONB at read time.
            $table->text('content_text')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_archived')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->index(['project_id', 'is_archived', 'updated_at'], 'idx_docs_project');
        });

        // Postgres FTS index on content_text — GIN is the right choice
        // for tsvector columns; `to_tsvector` must be wrapped so the
        // index matches a plain text column.
        DB::statement(
            "CREATE INDEX idx_docs_search ON docs USING gin (to_tsvector('english', coalesce(content_text, '')))"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_docs_search');
        Schema::dropIfExists('docs');

        // No DROP VALUE on resource_kind / task_resource_link_kind —
        // Postgres has no such operation, and existing rows elsewhere
        // may reference the value.
    }
};
