<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Work and Financial Plan for one school and one year — generated from
     * that school's Monthly Expenditure Matrices.
     *
     * There is exactly one plan per school per year (`unique(school_id,
     * year)`): regenerating reconciles the existing plan in place rather than
     * creating a second one. `status` moves draft → generated → finalized;
     * `generated_by`/`approved_by` record who ran the generation and who
     * signed it off.
     */
    public function up(): void
    {
        Schema::create('wfp_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->string('template_version')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfp_plans');
    }
};
