<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The districts an account is assigned to — the PSDS's monitoring reach.
     *
     * A PSDS account (Public Schools District Supervisor) monitors the AIP,
     * OPCRF and WFP submission status of the schools in its districts, and
     * this pivot is that assignment list: one row per account and district,
     * written by an administrator. District membership is the whole rule — a
     * school is in reach while it sits in an assigned district, so a school
     * transferred to another district follows its current district
     * automatically and nothing per school is ever assigned.
     *
     * No backfill: no account predates this list with a district assignment,
     * and `users` has no district column to backfill from.
     */
    public function up(): void
    {
        Schema::create('user_district_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One row per account and district — a re-run can never double an
            // assignment.
            $table->unique(['user_id', 'district_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_district_assignments');
    }
};
