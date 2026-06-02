<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials already carry encrypted_data + iv. Add key_version so the
 * rotation protocol (Step B) can discriminate between credentials wrapped
 * under v1 of a vault key and credentials wrapped under v2+.
 *
 * Nullable for the transition. Once every credential has been re-wrapped
 * during the migrate-key flow, a follow-up migration can flip it to NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->unsignedInteger('key_version')->nullable()->after('iv');
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropColumn('key_version');
        });
    }
};