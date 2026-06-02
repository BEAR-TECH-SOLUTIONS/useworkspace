<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_personal')->default(false);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE organisations ALTER COLUMN slug TYPE CITEXT');
        DB::statement('CREATE UNIQUE INDEX organisations_slug_unique ON organisations (slug)');
        // Each user has at most one personal organisation.
        DB::statement('CREATE UNIQUE INDEX organisations_personal_owner_unique ON organisations (owner_id) WHERE is_personal');

        Schema::create('organisation_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();
            $table->unique(['organisation_id', 'user_id']);
        });

        DB::statement("ALTER TABLE organisation_members ADD COLUMN role organisation_role NOT NULL DEFAULT 'member'");
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_members');
        Schema::dropIfExists('organisations');
    }
};
