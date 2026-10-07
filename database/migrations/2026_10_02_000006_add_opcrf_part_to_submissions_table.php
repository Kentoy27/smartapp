<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Part of the OPCRF form a submission answers.
 *
 * Every submission the app has ever recorded belongs to Part 1 — that is the
 * only Part staff could reach before schedules existed — so the column
 * defaults to 1 and the existing rows are already correct. Nothing is
 * backfilled or rewritten: a historical submission keeps saying what it said.
 *
 * With this, "did you finish Part 2?" is a question the dashboard can answer
 * from the submissions that already exist, which is what drives a Part's
 * Completed badge without introducing a second, parallel progress store.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('opcrf_submissions', 'opcrf_part')) {
            Schema::table('opcrf_submissions', function (Blueprint $table) {
                $table->unsignedTinyInteger('opcrf_part')
                    ->default(1)
                    ->after('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('opcrf_submissions', 'opcrf_part')) {
                $table->dropColumn('opcrf_part');
            }
        });
    }
};