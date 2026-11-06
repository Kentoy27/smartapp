<?php

use App\Support\SchoolDivision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Legacy NEP imports wrote `HIGH` (not `HIGH_SCHOOL`) and no sublevel.
     * The school-division backfill read that unrecognised level as
     * Elementary, so high schools were configured as Elementary-only and the
     * user form could not offer their high-school divisions.
     *
     * `HIGH` is unambiguous: these are high schools. Expose both high-school
     * divisions on the school's menu. This is configuration, not a grant —
     * each account's Division Access is still chosen by the administrator.
     * Elementary is kept as well, so accounts already working in it do not
     * lose access.
     */
    public function up(): void
    {
        $legacySchoolIds = DB::table('users')
            ->where('school_level', 'HIGH')
            ->whereNotNull('school_id')
            ->distinct()
            ->pluck('school_id');

        if ($legacySchoolIds->isEmpty()) {
            return;
        }

        DB::table('users')
            ->where('school_level', 'HIGH')
            ->update(['school_level' => SchoolDivision::LEVEL_HIGH_SCHOOL]);

        $now = now();

        foreach ($legacySchoolIds as $schoolId) {
            foreach (SchoolDivision::highSchoolDivisions() as $division) {
                DB::table('school_divisions')->insertOrIgnore([
                    'school_id' => (int) $schoolId,
                    'division_code' => $division,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Additive data backfill. High-school rows may have been edited by an
        // administrator since this ran, so there is nothing safe to remove.
    }
};
