<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'employee_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 50)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};