<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OPCRF auto-routing: the same submission row travels Eve → SY.
 *
 * `reviewer_id` keeps its historical meaning on the surface but its role is
 * now "the original reviewer the submission was routed to first" (Eve) — it
 * never changes again once set. `assigned_to` is the *current* holder of the
 * review step: after Eve's Approve/Compliance auto-routes onward, the row is
 * assigned to SY (status 'forwarded'), and Eve's records still show the
 * submission because her reviewer_id stays untouched. One row, one ID, one
 * workbook, complete history — no duplicates.
 *
 * Backfill: every existing submission sits with its reviewer_id, so
 * assigned_to starts as a copy of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table): void {
            $table->foreignId('assigned_to')
                ->nullable()
                ->after('reviewer_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        // Existing rows are sitting with their original reviewer — that is
        // who currently holds the review step.
        DB::table('opcrf_submissions')
            ->whereNull('assigned_to')
            ->whereNotNull('reviewer_id')
            ->update(['assigned_to' => DB::raw('reviewer_id')]);
    }

    public function down(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
