<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separate financial management for Elementary, Junior High School and
     * Senior High School without duplicating the existing tables.
     *
     * Every existing row is carried over as ELEMENTARY: the school division
     * columns default to Elementary, and the old unique keys are replaced by
     * keys that include the division so one High School can hold a Junior
     * High and a Senior High row for the same year. Existing records are not
     * guessed into a high-school division — that is an explicit administrator
     * action (see the School Division Mapping page).
     *
     * The key replacement is guarded rather than a plain drop/add: on MySQL
     * the old (school_id, year) unique key is what supports the school_id
     * foreign key, so it cannot be dropped until a replacement index exists.
     * The guards also let a migration that stopped half-way (a failed drop,
     * an aborted deploy) be re-run safely — it resumes instead of colliding
     * with the columns and keys it already created.
     */
    public function up(): void
    {
        $this->addUserClassification();

        $this->addDivisionColumn('school_allotments', 'school_id');
        $this->replaceUnique(
            'school_allotments',
            'school_allotments_school_id_year_unique',
            ['school_id', 'school_division', 'year'],
            'school_allotments_division_unique',
        );

        $this->addDivisionColumn('monthly_expenditure_matrices', 'school_id');
        $this->replaceUnique(
            'monthly_expenditure_matrices',
            'monthly_expenditure_matrices_school_id_year_month_unique',
            ['school_id', 'school_division', 'year', 'month'],
            'monthly_expenditure_matrices_division_unique',
        );

        $this->addDivisionColumn('wfp_plans', 'school_id');
        $this->replaceUnique(
            'wfp_plans',
            'wfp_plans_school_id_year_unique',
            ['school_id', 'school_division', 'year'],
            'wfp_plans_division_unique',
        );

        $this->addDivisionColumn('wfp_report_checks', 'school_id');
        $this->replaceUnique(
            'wfp_report_checks',
            'wfp_report_checks_school_id_year_unique',
            ['school_id', 'school_division', 'year'],
            'wfp_report_checks_division_unique',
        );

        $this->addDivisionColumn('wfp_submissions', 'user_id');

        if (! Schema::hasIndex('wfp_submissions', 'wfp_submissions_division_index')) {
            Schema::table('wfp_submissions', function (Blueprint $table) {
                $table->index(['user_id', 'school_division', 'uploaded_at'], 'wfp_submissions_division_index');
            });
        }

        $this->addDivisionColumn('school_allotment_audits', 'school_id');

        if (! Schema::hasIndex('school_allotment_audits', 'school_allotment_audits_division_index')) {
            Schema::table('school_allotment_audits', function (Blueprint $table) {
                $table->index(['school_id', 'school_division', 'year'], 'school_allotment_audits_division_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('school_allotment_audits', 'school_allotment_audits_division_index')) {
            Schema::table('school_allotment_audits', function (Blueprint $table) {
                $table->dropIndex('school_allotment_audits_division_index');
            });
        }

        $this->dropDivisionColumn('school_allotment_audits');

        if (Schema::hasIndex('wfp_submissions', 'wfp_submissions_division_index')) {
            Schema::table('wfp_submissions', function (Blueprint $table) {
                $table->dropIndex('wfp_submissions_division_index');
            });
        }

        $this->dropDivisionColumn('wfp_submissions');

        $this->replaceUnique(
            'wfp_report_checks',
            'wfp_report_checks_division_unique',
            ['school_id', 'year'],
            'wfp_report_checks_school_id_year_unique',
        );
        $this->dropDivisionColumn('wfp_report_checks');

        $this->replaceUnique(
            'wfp_plans',
            'wfp_plans_division_unique',
            ['school_id', 'year'],
            'wfp_plans_school_id_year_unique',
        );
        $this->dropDivisionColumn('wfp_plans');

        $this->replaceUnique(
            'monthly_expenditure_matrices',
            'monthly_expenditure_matrices_division_unique',
            ['school_id', 'year', 'month'],
            'monthly_expenditure_matrices_school_id_year_month_unique',
        );
        $this->dropDivisionColumn('monthly_expenditure_matrices');

        $this->replaceUnique(
            'school_allotments',
            'school_allotments_division_unique',
            ['school_id', 'year'],
            'school_allotments_school_id_year_unique',
        );
        $this->dropDivisionColumn('school_allotments');

        foreach (['division_access', 'school_sublevel', 'school_level'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /** The classification and division access a school-side account carries. */
    private function addUserClassification(): void
    {
        if (! Schema::hasColumn('users', 'school_level')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('school_level', 20)->default('ELEMENTARY')->after('school_id');
            });
        }

        if (! Schema::hasColumn('users', 'school_sublevel')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('school_sublevel', 20)->default('NONE')->after('school_level');
            });
        }

        if (! Schema::hasColumn('users', 'division_access')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('division_access')->nullable()->after('school_sublevel');
            });
        }
    }

    private function addDivisionColumn(string $table, string $after): void
    {
        if (Schema::hasColumn($table, 'school_division')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($after) {
            $blueprint->string('school_division', 20)->default('ELEMENTARY')->after($after);
        });
    }

    private function dropDivisionColumn(string $table): void
    {
        if (Schema::hasColumn($table, 'school_division')) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('school_division');
            });
        }
    }

    /**
     * Replace one unique key with a wider one, in an order the foreign keys
     * tolerate.
     *
     * The old key is dropped only after a plain school_id index exists to
     * carry the foreign key, and that temporary index is removed once the new
     * unique key (whose leftmost column is school_id) carries it instead. A
     * table that no longer holds the old key — already replaced by an earlier
     * run — is left as it is.
     *
     * @param  array<int, string>  $newColumns
     */
    private function replaceUnique(string $table, string $oldName, array $newColumns, string $newName): void
    {
        if (! Schema::hasIndex($table, $oldName)) {
            return;
        }

        $support = $table.'_school_id_support_index';
        $addedSupport = false;

        if (! Schema::hasIndex($table, $support)) {
            Schema::table($table, function (Blueprint $blueprint) use ($support) {
                $blueprint->index('school_id', $support);
            });

            $addedSupport = true;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($oldName) {
            $blueprint->dropUnique($oldName);
        });

        if (! Schema::hasIndex($table, $newName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($newColumns, $newName) {
                $blueprint->unique($newColumns, $newName);
            });
        }

        if ($addedSupport && Schema::hasIndex($table, $support)) {
            Schema::table($table, function (Blueprint $blueprint) use ($support) {
                $blueprint->dropIndex($support);
            });
        }
    }
};
