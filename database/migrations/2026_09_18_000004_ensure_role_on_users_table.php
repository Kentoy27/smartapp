<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 20)->nullable()->after('is_superadmin');
            });
        }
    }

    public function down(): void
    {
        // The original role migration owns the column lifecycle.
    }
};