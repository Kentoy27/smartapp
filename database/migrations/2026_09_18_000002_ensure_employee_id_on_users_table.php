<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'employee_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('employee_id', 50)->nullable()->after('name');
            });
        }

        // Add the unique index separately so this also repairs databases
        // where the column exists but its index was never created.
        $indexes = Schema::getIndexes('users');
        $hasEmployeeIdIndex = collect($indexes)->contains(function (array $index): bool {
            return in_array('employee_id', $index['columns'] ?? [], true);
        });

        if (! $hasEmployeeIdIndex) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('employee_id');
            });
        }
    }

    public function down(): void
    {
        // The original employee_id migration owns the column lifecycle.
    }
};