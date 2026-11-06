<?php

use App\Support\SchoolDivision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The division backfill could only see the divisions the imported
     * accounts carried. An Integrated School whose sole imported account was
     * a high-school account was therefore configured as high-school-only,
     * hiding Elementary from its user form even though the school operates
     * it.
     *
     * The `IS` / `Integrated School` designation is the school's own name,
     * so this adds Elementary to those schools' menus. It is configuration,
     * not a grant: the administrator still chooses each account's access.
     */
    public function up(): void
    {
        $schoolIds = DB::table('schools')
            ->where(function ($query): void {
                $query->where('name', 'like', '% IS')
                    ->orWhere('name', 'like', '%Integrated%');
            })
            ->pluck('id');

        $now = now();

        foreach ($schoolIds as $schoolId) {
            DB::table('school_divisions')->insertOrIgnore([
                'school_id' => (int) $schoolId,
                'division_code' => SchoolDivision::ELEMENTARY,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Additive data backfill; an administrator may have edited the menu
        // since, so there is nothing safe to remove automatically.
    }
};
