<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Bookkeeper's "Checked as to the Correctness of Account Titles and
     * Codes" sign-off on a school's annual MOOE allotment report.
     *
     * It belongs to the SCHOOL and year, not to the account: the report (and
     * the Monthly Expenditure Matrices it rolls up) belongs to the school, so
     * a change of Bookkeeper or School Head does not strand the check. One
     * check per school per year; a re-check replaces it.
     *
     * Kept separate from school_allotments because the check is about the
     * year's lines' account titles and codes, not about the allotment
     * amount: a school whose ceiling is not set yet still has lines to check,
     * and "no allotment row" must stay different from "allotment of 0.00".
     */
    public function up(): void
    {
        Schema::create('wfp_report_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'year']);
            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfp_report_checks');
    }
};
