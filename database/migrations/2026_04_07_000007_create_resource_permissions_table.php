<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('resource_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE resource_permissions ADD COLUMN resource_type resource_kind NOT NULL');
        DB::statement('ALTER TABLE resource_permissions ADD COLUMN role member_role NOT NULL');

        DB::statement('CREATE UNIQUE INDEX rp_unique ON resource_permissions (user_id, resource_type, resource_id)');
        DB::statement('CREATE INDEX rp_lookup_idx ON resource_permissions (user_id, project_id)');
        DB::statement('CREATE INDEX rp_resource_idx ON resource_permissions (resource_type, resource_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_permissions');
    }
};
