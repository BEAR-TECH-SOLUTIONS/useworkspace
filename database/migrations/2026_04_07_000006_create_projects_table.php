<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('name');
            $table->string('color', 7)->default('#6366f1');
            $table->string('icon', 50)->nullable();
            $table->boolean('is_personal')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->jsonb('modules_enabled')->default(DB::raw("'{\"vault\":true,\"tasks\":true,\"expenses\":true}'::jsonb"));
            $table->timestamps();
            $table->index('organisation_id');
            $table->index('owner_id');
        });

        Schema::create('project_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('encrypted_project_key')->nullable();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE project_members ADD COLUMN role member_role NOT NULL');
        DB::statement('ALTER TABLE project_members ADD COLUMN vault_role member_role');
        DB::statement('ALTER TABLE project_members ADD COLUMN tasks_role member_role');
        DB::statement('ALTER TABLE project_members ADD COLUMN expenses_role member_role');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
