<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One expenditure line inside a Monthly Expenditure Matrix: the objective,
     * the activity, the quantity/unit/unit-cost (from which
     * `calculated_total` is ALWAYS recomputed server-side — qty × unit-cost),
     * the account title/code, and the budget metadata the WFP carries forward.
     *
     * Money is `decimal(15,2)`. `position` preserves the SH's own ordering of
     * the lines. `account_classification_id` is an optional link into the RCA /
     * UACS master data; a line whose account code is not in that list still
     * saves (the code is kept as free text) — the link is only recorded when a
     * classification was actually picked.
     */
    public function up(): void
    {
        Schema::create('monthly_expenditure_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matrix_id')->constrained('monthly_expenditure_matrices')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('section', 20); // operations|project
            $table->text('objective')->nullable();
            $table->text('activity_description');
            $table->string('resource_kind')->nullable();
            $table->decimal('quantity', 15, 2)->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->decimal('calculated_total', 15, 2)->nullable();
            $table->string('account_title')->nullable();
            $table->string('account_code')->nullable();
            $table->foreignId('account_classification_id')->nullable()->constrained('account_classifications')->nullOnDelete();
            $table->string('funding_source')->nullable();
            $table->string('organizational_classification')->nullable();
            $table->text('activity_movs')->nullable();
            $table->timestamps();

            $table->index(['matrix_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_expenditure_items');
    }
};
