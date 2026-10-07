<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn the WFP row from "a file on disk" into a review workflow record:
     *
     *   - file_type + the review lifecycle (uploaded → processing →
     *     for_review → edited → submitted) alongside the reviewer's own
     *     verdict (pending / returned / approved) and their remarks,
     *   - sheet_data: the workbook converted to editable cell data (the
     *     Excel-like review grid reads and writes this — the ORIGINAL file
     *     is never touched),
     *   - analysis: what the analyzer found (headers, activities, dates,
     *     targets, indicators, budget cells, responsible offices …),
     *   - validation: the warnings shown in the review panel,
     *   - edited_file_path: the generated updated .xlsx for download — a
     *     separate file from the original, which stays as uploaded.
     */
    public function up(): void
    {
        Schema::table('wfp_submissions', function (Blueprint $table) {
            $table->string('file_type', 10)->nullable()->after('mime_type');
            $table->string('edited_file_path')->nullable()->after('file_path');

            // The editable grid: one entry per sheet (dense cell matrix,
            // merges, header row). JSON so the reader/writer stay flexible.
            $table->json('sheet_data')->nullable()->after('status');
            $table->json('analysis')->nullable()->after('sheet_data');
            $table->json('validation')->nullable()->after('analysis');

            // Lifecycle: the file's journey. The reviewer's verdict is kept
            // separately so "Submitted + Returned" stays representable.
            $table->string('review_status', 20)->nullable()->after('validation');
            $table->text('review_remarks')->nullable()->after('review_status');
            $table->foreignId('reviewer_id')->nullable()->after('review_remarks')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('reviewer_id');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('wfp_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewer_id');
            $table->dropColumn([
                'file_type', 'edited_file_path', 'sheet_data', 'analysis',
                'validation', 'review_status', 'review_remarks',
                'submitted_at', 'reviewed_at',
            ]);
        });
    }
};
