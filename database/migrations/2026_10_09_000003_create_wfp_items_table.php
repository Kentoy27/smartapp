<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One WFP line, month-grained: a WFP line maps 1:1 to the MEM line it came
     * from (`source_expenditure_item_id`), so "the target lands in the correct
     * month and is never duplicated" holds by construction — an item's month is
     * its source matrix's month, and the annual total is simply
     * SUM(financial_target).
     *
     * `source_matrix_version` records the MEM version this row was mapped from.
     * That is how out-of-date is DERIVED (item version != its matrix's current
     * version) — it is never stored as a flag.
     *
     * `group_key` is a stable hash of the grouping fields (section + objective +
     * activity + account title + account code + unit + unit cost); it lets the
     * preview and the annual-shaped export group rows without re-deriving the
     * same grouping on every render.
     *
     * `major_output`/`performance_indicator` are WFP-only (never carried from
     * the MEM); `needs_review`/`review_fields` record which of the flaggable
     * fields are still empty, so the editor can show — and clear — them.
     *
     * `source_expenditure_item_id` is deliberately NOT a foreign key: the id must
     * survive its MEM line's deletion so regeneration can still match the row and
     * remove it (a MEM line that is gone means its mapped WFP line is gone too). A
     * null source id therefore means the row is WFP-owned (e.g. added by hand).
     * `source_matrix_id` stays `nullOnDelete`, so deleting a whole matrix only
     * detaches the origin; its rows are then removed on the next regeneration.
     * (The matrix delete guard blocks the finalized case.)
     */
    public function up(): void
    {
        Schema::create('wfp_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wfp_plan_id')->constrained('wfp_plans')->cascadeOnDelete();
            $table->foreignId('source_expenditure_item_id')->nullable()->index();
            $table->foreignId('source_matrix_id')->nullable()->constrained('monthly_expenditure_matrices')->nullOnDelete();
            $table->unsignedInteger('source_matrix_version')->default(0);
            $table->string('group_key', 40);
            $table->unsignedInteger('position')->default(0);
            $table->tinyInteger('month')->nullable();
            $table->string('section', 20)->nullable();
            $table->text('objective')->nullable();
            $table->text('program_activity')->nullable();
            $table->text('major_output')->nullable();
            $table->text('performance_indicator')->nullable();
            $table->decimal('physical_target', 15, 2)->nullable();
            $table->string('physical_unit')->nullable();
            $table->decimal('financial_target', 15, 2)->nullable();
            $table->string('account_title')->nullable();
            $table->string('account_code')->nullable();
            $table->string('funding_source')->nullable();
            $table->string('organizational_classification')->nullable();
            $table->text('activity_movs')->nullable();
            $table->boolean('needs_review')->default(true);
            $table->json('review_fields')->nullable();
            $table->timestamps();

            $table->index(['wfp_plan_id', 'position']);
            $table->index('group_key');
            $table->index('source_matrix_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfp_items');
    }
};
