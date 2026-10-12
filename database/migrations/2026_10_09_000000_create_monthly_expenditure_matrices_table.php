<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Monthly Expenditure Matrix: one school's operating budget lines for one
     * calendar month (year + month). The MEM is the source of truth the WFP is
     * generated from, so it is bound to a school (`school_id`) — an SH may only
     * manage the matrices of their own school.
     *
     * `status` moves draft → validated → finalized; `version` is bumped every
     * time the line items are written. A generated WFP records the matrix
     * version its rows came from, which is how "out of date" is derived
     * (never stored): an item whose recorded version no longer matches its
     * matrix's current version is out of date.
     *
     * A school has at most one matrix per month — `unique(school_id, year,
     * month)`.
     */
    public function up(): void
    {
        Schema::create('monthly_expenditure_matrices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->tinyInteger('month');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'year', 'month']);
            $table->index(['school_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_expenditure_matrices');
    }
};
