<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The review-and-routing workflow's audit trail: one row per review
        // action (compliance/approve, forward, return-for-revision) with the
        // reviewer, their remarks, and the routing (from → to). The original
        // uploaded workbook is never touched by any of these actions.
        Schema::create('opcrf_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opcrf_submission_id')->constrained('opcrf_submissions')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('action'); // compliance | forward | return
            $table->text('remarks')->nullable();
            $table->foreignId('from_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at');

            $table->timestamps();

            $table->index(['opcrf_submission_id', 'reviewed_at']);
        });

        // The submission's current workflow status, derived from the review
        // trail. Existing rows are backfilled from their approval columns so
        // no history is lost.
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('reviewer_id');
            $table->index('status');
        });

        DB::table('opcrf_submissions')
            ->whereNotNull('approved_at')
            ->update(['status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });

        Schema::dropIfExists('opcrf_reviews');
    }
};
