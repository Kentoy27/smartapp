<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: skip if the column already exists (prevents
        // "duplicate column" errors on databases where it was added
        // before this migration was recorded in the migrations table).
        if (Schema::hasColumn('users', 'is_superadmin')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_superadmin')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_superadmin');
        });
    }
};
