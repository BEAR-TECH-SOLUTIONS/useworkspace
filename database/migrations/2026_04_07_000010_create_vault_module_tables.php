<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaults', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->string('icon', 50)->nullable();
            $table->double('position')->default(10000);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index('project_id');
        });
        DB::statement('CREATE UNIQUE INDEX vaults_default_unique ON vaults (project_id) WHERE is_default');

        Schema::create('credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('vault_id')->nullable()->constrained('vaults')->nullOnDelete();
            $table->string('name');
            $table->string('url', 500)->nullable();
            $table->text('encrypted_data');
            $table->text('iv');
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'deleted_at']);
            $table->index('url');
        });

        DB::statement('ALTER TABLE credentials ADD COLUMN type entry_type NOT NULL');
        DB::statement("ALTER TABLE credentials ADD COLUMN tags TEXT[] NOT NULL DEFAULT '{}'");
        DB::statement('CREATE INDEX credentials_tags_gin ON credentials USING GIN (tags)');

        // E2E encryption invariant: iv must be a real 12-byte AES-GCM nonce.
        // Standard base64 of 12 bytes is exactly 16 chars (no padding). This
        // is a shape check — full base64 decoding lives in the FormRequest
        // validators (Store/UpdateCredentialRequest). Together they make
        // legacy "JSON in encrypted_data, iv = ''" payloads unreachable.
        DB::statement(<<<'SQL'
            ALTER TABLE credentials
            ADD CONSTRAINT credentials_iv_length_check
            CHECK (length(iv) = 16)
        SQL);

        Schema::create('credential_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credential_id')->constrained('credentials')->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users');
            $table->text('encrypted_data');
            $table->text('iv');
            $table->timestamp('created_at')->useCurrent();
            $table->index('credential_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_history');
        Schema::dropIfExists('credentials');
        Schema::dropIfExists('vaults');
    }
};
