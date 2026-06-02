<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Universal Share Links — schema reshape (CLAUDE.md §10, plan §1).
 *
 * Generalises share_links from credential-only to polymorphic
 * (board / task / credential / doc / expense).
 *
 * Token-hash storage is preserved (DB-dump leaks no usable URLs),
 * project_id is kept as a denormalised column for fast project-scoped
 * admin queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_links', function (Blueprint $table): void {
            $table->string('resource_type', 32)->nullable()->after('id');
            $table->unsignedBigInteger('resource_id')->nullable()->after('resource_type');
            $table->jsonb('snapshot_payload')->nullable()->after('resource_id');
            $table->char('auth_proof_hash', 64)->nullable()->after('password_hash');
        });

        Schema::table('share_links', function (Blueprint $table): void {
            $table->string('resource_type', 32)->nullable(false)->change();
            $table->unsignedBigInteger('resource_id')->nullable(false)->change();
            $table->jsonb('snapshot_payload')->nullable(false)->change();
        });

        // Drop credential-specific columns. encrypted_data + iv are now
        // inside snapshot_payload; require_password is derived from
        // (auth_proof_hash !== null OR password_hash !== null).
        Schema::table('share_links', function (Blueprint $table): void {
            $table->dropForeign(['credential_id']);
            $table->dropIndex(['credential_id']);
            $table->dropColumn(['credential_id', 'encrypted_data', 'iv', 'require_password']);
        });

        // Drop the now-stale project_id partial index. Replace it with
        // an owner-scoped index that powers GET /me/share-links.
        DB::statement('DROP INDEX IF EXISTS share_links_project_idx');
        DB::statement('CREATE INDEX share_links_resource_idx ON share_links (resource_type, resource_id)');
        DB::statement('CREATE INDEX share_links_owner_idx ON share_links (created_by, revoked_at, expires_at)');

        // Postgres CHECK constraint guards the morph map at the row level.
        DB::statement(<<<'SQL'
            ALTER TABLE share_links
            ADD CONSTRAINT share_links_resource_type_check
            CHECK (resource_type IN ('board','task','credential','doc','expense'))
        SQL);
    }

    public function down(): void
    {
        // Reshape is one-way: snapshot_payload contains JSONB that cannot
        // be safely projected back into the old encrypted_data/iv text
        // columns without risking data loss for non-credential rows.
        // Roll forward instead.
        throw new \RuntimeException('Reshape of share_links is irreversible. Roll forward.');
    }
};
