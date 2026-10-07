<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The District → School → User hierarchy, built on the EXISTING
     * districts/schools tables:
     *
     *   schools.school_id — the DepEd school ID ("123456"): unique, shown
     *     in the district modal and auto-filled (read-only) on Add User.
     *   users.school_id   — the account's school (foreign key). The old
     *     free-text users.employee_id is superseded by it and dropped: no
     *     user row ever pointed anywhere with it, and the Add User form
     *     no longer asks for it (the School ID comes from the school).
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('school_id', 20)->nullable()->after('name');

            // Two schools may share a name; a DepEd school ID may not.
            $table->unique('school_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('school_id')->nullable()->after('role')->constrained('schools')->nullOnDelete();
            $table->index('school_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'employee_id')) {
                // The legacy column ships with a unique index (SQLite names
                // it users_employee_id_unique); it must go BEFORE the
                // column — dropping the column alone breaks the index.
                $indexName = 'users_employee_id_unique';

                $hasIndex = collect(Schema::getIndexListing('users'))
                    ->contains(fn ($name) => str_contains($name, 'employee_id'));

                if ($hasIndex) {
                    Schema::table('users', fn (Blueprint $t) => $t->dropIndex($indexName));
                }
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'employee_id')) {
                $table->dropColumn('employee_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'employee_id')) {
                $table->string('employee_id')->nullable();
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'school_id')) {
                $table->dropConstrainedForeignId('school_id');
            }
        });

        Schema::table('schools', function (Blueprint $table): void {
            if (Schema::hasColumn('schools', 'school_id')) {
                $table->dropUnique(['school_id']);
                $table->dropColumn('school_id');
            }
        });
    }
};
