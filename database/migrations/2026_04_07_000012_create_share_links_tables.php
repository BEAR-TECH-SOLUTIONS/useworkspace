<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credential_id')->constrained('credentials')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('token_hash')->unique();
            $table->text('encrypted_data');
            $table->text('iv');
            $table->timestampTz('expires_at');
            $table->integer('max_views')->nullable();
            $table->integer('view_count')->default(0);
            $table->boolean('require_password')->default(false);
            $table->text('password_hash')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('credential_id');
        });

        DB::statement('CREATE INDEX share_links_project_idx ON share_links (project_id) WHERE revoked_at IS NULL');
        DB::statement('CREATE INDEX share_links_active_idx  ON share_links (expires_at) WHERE revoked_at IS NULL');

        Schema::create('share_link_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('share_link_id')->constrained('share_links')->cascadeOnDelete();
            $table->text('ip_hash')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('viewed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_link_views');
        Schema::dropIfExists('share_links');
    }
};
