<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. resource_keys — crypto plane for vaults and projects.
        //
        // Reuses the existing Postgres `resource_kind` enum. The CHECK constraint
        // pins the crypto plane to 'project' and 'vault' rows so any future
        // attempt to insert a 'board' or 'bucket' key is rejected by Postgres
        // itself, not just by application code.
        Schema::create('resource_keys', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('resource_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('encrypted_key');
            $table->unsignedInteger('key_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE resource_keys ADD COLUMN resource_type resource_kind NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE resource_keys
            ADD CONSTRAINT resource_keys_unique_grant
            UNIQUE (resource_type, resource_id, user_id, key_version)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE resource_keys
            ADD CONSTRAINT resource_keys_vault_or_project_only
            CHECK (resource_type IN ('project', 'vault'))
        SQL);

        DB::statement('CREATE INDEX resource_keys_user_idx ON resource_keys (user_id)');
        DB::statement('CREATE INDEX resource_keys_resource_idx ON resource_keys (resource_type, resource_id, key_version)');
        DB::statement('CREATE INDEX resource_keys_project_idx ON resource_keys (project_id)');

        // 2. vaults.migrated_at — every vault is born "migrated" (the
        // unmigrated state is no longer reachable from any released
        // client). Kept on the schema as the timestamp of vault
        // creation so that resources, audit, and the desktop client's
        // serialiser don't have to special-case its absence. The
        // /migrate-key endpoint then writes per-vault wrapped keys
        // into resource_keys; key existence is the source of truth
        // for "has this vault been keyed yet?".
        Schema::table('vaults', function (Blueprint $table): void {
            $table->timestampTz('migrated_at')->useCurrent();
        });

        // 3. Two missing indexes on resource_permissions. Short naming to
        // match the existing rp_* convention.
        DB::statement('CREATE INDEX IF NOT EXISTS rp_user_type_idx ON resource_permissions (user_id, resource_type)');
        DB::statement('CREATE INDEX IF NOT EXISTS rp_project_idx ON resource_permissions (project_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rp_project_idx');
        DB::statement('DROP INDEX IF EXISTS rp_user_type_idx');

        Schema::table('vaults', function (Blueprint $table): void {
            $table->dropColumn('migrated_at');
        });

        Schema::dropIfExists('resource_keys');
    }
};