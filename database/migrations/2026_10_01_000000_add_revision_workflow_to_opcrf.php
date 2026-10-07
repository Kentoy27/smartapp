<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The review trail records the status transition each action made:
        // "Previous status → New status" (e.g. Pending Review → Returned
        // for Revision) right next to the reviewer, remarks and timestamp.
        Schema::table('opcrf_reviews', function (Blueprint $table): void {
            $table->string('previous_status')->nullable()->after('action');
            $table->string('new_status')->nullable()->after('previous_status');
        });

        // File versions: every resubmission archives the workbook it
        // replaces (Version 1 = the original upload, Version 2 = the first
        // revision, …) so no submitted copy is ever overwritten or lost.
        Schema::create('opcrf_submission_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opcrf_submission_id')->constrained('opcrf_submissions')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('stored_path');
            $table->string('original_name')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();

            $table->index(['opcrf_submission_id', 'version_number']);
        });

        // Laravel's database notifications (the "OPCRF Returned" alert).
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('opcrf_submission_versions');
        Schema::table('opcrf_reviews', function (Blueprint $table): void {
            $table->dropColumn(['previous_status', 'new_status']);
        });
    }
};
