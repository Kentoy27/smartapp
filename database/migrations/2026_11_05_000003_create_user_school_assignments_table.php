<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The schools an account is assigned to — the many-to-many behind the
     * Bookkeeper's multi-school assignment.
     *
     * `users.school_id` stays as the account's ONE base school (the SH's own
     * school; the Bookkeeper's station), because the division classification
     * and the rest of the app read it. This pivot is the Bookkeeper's readable
     * roster: the checking pages (Monthly Expenditure, Per Line Item
     * Expenditure) and the report's correctness check are limited to these
     * schools — see User::canAccessSchool().
     *
     * Every account that already carries a base school is backfilled here, so
     * no existing access changes when the scoping starts reading the pivot.
     * A deleted school takes its assignment rows with it (cascade), which is
     * the safe handling for an assignment to a school that no longer exists.
     */
    public function up(): void
    {
        Schema::create('user_school_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One row per account and school — a re-run can never double an
            // assignment.
            $table->unique(['user_id', 'school_id']);
        });

        // Backfill: each account's current school becomes its first (or only)
        // assignment. Chunked and insertOrIgnore so a large users table is
        // safe to migrate and duplicates are impossible.
        DB::table('users')
            ->whereNotNull('school_id')
            ->select(['id', 'school_id'])
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                $now = now();

                $rows = $users->map(fn ($user): array => [
                    'user_id' => (int) $user->id,
                    'school_id' => (int) $user->school_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('user_school_assignments')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_school_assignments');
    }
};
