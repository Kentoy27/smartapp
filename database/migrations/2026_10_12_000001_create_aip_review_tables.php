<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AIP's review trail and its preserved versions.
 *
 * aip_reviews is the audit trail — one row per action (submit, compliance/
 * approve, return-for-revision, resubmit) with the actor, their remarks and
 * the status transition. It is the record the review history renders.
 *
 * aip_plan_versions preserves every submitted copy of the plan as an
 * immutable JSON snapshot (Version 1 = the first submission), so a revision
 * never overwrites what was previously submitted and reviewed. Nothing here
 * is ever deleted by a revision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aip_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aip_plan_id')->constrained('aip_plans')->cascadeOnDelete();
            // The actor for every action type — the reviewer for a review, the
            // owner for a submission/resubmission (the same convention the
            // OPCRF review trail uses).
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('action'); // submit | compliance | return | resubmit
            $table->string('previous_status')->nullable();
            $table->string('new_status')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index(['aip_plan_id', 'reviewed_at']);
        });

        Schema::create('aip_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aip_plan_id')->constrained('aip_plans')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            // The status the snapshot was taken at (pending / resubmitted).
            $table->string('status')->nullable();
            // The plan's contents (projects + activities) at submission time.
            $table->json('snapshot');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['aip_plan_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aip_plan_versions');
        Schema::dropIfExists('aip_reviews');
    }
};
