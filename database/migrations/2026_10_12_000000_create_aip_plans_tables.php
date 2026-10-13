<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Annual Implementation Plan (AIP): the school's one-year breakdown of
 * its School Improvement Plan.
 *
 * Three tables, in the shape the official form is written in (Annex 10 of
 * the enhanced SIP Guidebook, DepEd Order No. 44, s. 2015):
 *
 *   aip_plans ──< aip_projects ──< aip_activities
 *
 * aip_plans is the header: one plan per school per year (`unique(school_id,
 * year)`), carrying the workflow state (draft → pending → returned →
 * resubmitted → approved) and who submitted/reviewed/approved it. A plan is
 * owned by a SCHOOL, not a user, so a change of school head does not strand
 * it — authorization is by school (see AipPlan::managedBy()).
 *
 * aip_projects is one School Improvement Project: its title, its objective
 * and the output for the year. aip_activities are that project's activities,
 * each with the person(s) responsible, the schedule/venue, the budget per
 * activity and the budget source.
 *
 * `version` is bumped every time the plan's contents are written, and
 * `wfp_plan_id` links the plan to the school's Work and Financial Plan for
 * the same year where one exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aip_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('year');
            $table->string('title')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('wfp_plan_id')->nullable()->constrained('wfp_plans')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();

            // One plan per school per year: the editor reconciles the existing
            // plan in place rather than creating a second one.
            $table->unique(['school_id', 'year']);
            $table->index('status');
        });

        Schema::create('aip_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aip_plan_id')->constrained('aip_plans')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // SCHOOL IMPROVEMENT PROJECT TITLE
            $table->string('title');
            // PROJECT OBJECTIVE
            $table->text('objective')->nullable();
            // OUTPUT FOR THE YEAR
            $table->text('output_for_year')->nullable();
            $table->timestamps();

            $table->index(['aip_plan_id', 'position']);
        });

        Schema::create('aip_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aip_plan_id')->constrained('aip_plans')->cascadeOnDelete();
            $table->foreignId('aip_project_id')->nullable()->constrained('aip_projects')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // ACTIVITIES
            $table->text('activity');
            // PERSON(S) RESPONSIBLE
            $table->string('person_responsible')->nullable();
            // SCHEDULE/VENUE — the schedule as the form writes it, plus an
            // optional venue, so the two halves can be exported separately.
            $table->string('schedule')->nullable();
            $table->string('venue')->nullable();
            // BUDGET PER ACTIVITY
            $table->decimal('budget', 15, 2)->nullable();
            // BUDGET SOURCE
            $table->string('budget_source')->nullable();
            $table->timestamps();

            $table->index(['aip_plan_id', 'position']);
            $table->index('aip_project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aip_activities');
        Schema::dropIfExists('aip_projects');
        Schema::dropIfExists('aip_plans');
    }
};
