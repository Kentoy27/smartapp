<?php

use App\Support\SchoolDivision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The divisions a school operates — the list the user form offers and
     * against which a user's Division Access is validated.
     *
     * A school may operate any combination of Elementary, Junior High and
     * Senior High; before this table the "kind of school" lived on the
     * account (`users.school_level`), so an integrated school could not be
     * described at all. The account classification is kept for compatibility
     * (its broad label and default division) but is no longer the source of
     * truth for what the school offers.
     *
     * Existing schools are seeded from what their accounts already show:
     * the union of the divisions those accounts can read. A school with no
     * account to read keeps the historical Elementary default. Nothing is
     * granted to a user by this migration — only the school's own menu is
     * described.
     */
    public function up(): void
    {
        Schema::create('school_divisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('division_code', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'division_code']);
        });

        $this->seedExistingSchools();
    }

    public function down(): void
    {
        Schema::dropIfExists('school_divisions');
    }

    private function seedExistingSchools(): void
    {
        $now = now();

        DB::table('schools')->orderBy('id')->chunkById(100, function ($schools) use ($now): void {
            foreach ($schools as $school) {
                $rows = array_map(fn (string $division): array => [
                    'school_id' => (int) $school->id,
                    'division_code' => $division,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $this->divisionsFor((int) $school->id));

                DB::table('school_divisions')->insertOrIgnore($rows);
            }
        });
    }

    /**
     * The divisions a school already demonstrates through its accounts.
     *
     * @return array<int, string>
     */
    private function divisionsFor(int $schoolId): array
    {
        $divisions = [];

        $users = DB::table('users')
            ->where('school_id', $schoolId)
            ->get(['school_level', 'school_sublevel', 'division_access']);

        foreach ($users as $user) {
            $assigned = SchoolDivision::validAccessList($user->division_access);

            // Rows that predate the explicit access list carry their
            // classification only; read it the same way the model does.
            if ($assigned === []) {
                $assigned = SchoolDivision::authorizedFor(
                    (string) ($user->school_level ?: SchoolDivision::LEVEL_ELEMENTARY),
                    (string) ($user->school_sublevel ?: SchoolDivision::SUBLEVEL_NONE),
                    null,
                );
            }

            $divisions = array_merge($divisions, $assigned);
        }

        $divisions = SchoolDivision::validAccessList($divisions);

        // No account carries a signal: Elementary is the historical default.
        return $divisions !== [] ? $divisions : [SchoolDivision::ELEMENTARY];
    }
};
