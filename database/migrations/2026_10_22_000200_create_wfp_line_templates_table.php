<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Activity / Expenditure Templates: reusable combinations of the fields a
     * WFP matrix line normally repeats (objective, activity, resource kind,
     * unit, account, funding source, …). Selecting a template in the Add a
     * Line form fills the fields it has a value for; the saved line keeps its
     * own copy of every value, so editing a template later never rewrites a
     * saved plan.
     *
     * References to the lookup library are foreign keys — a template's
     * `unit_id` can only be a unit — while long-form text (objective, major
     * output, performance indicator, MOVs) is stored directly on the
     * template. `account_classification_id` points at the existing RCA /
     * UACS master table, never a copy of it.
     *
     * One resource row per template for now (kind, unit, suggested quantity
     * and unit cost on the template row itself). The schema is shaped so
     * multiple resource rows can be added later as a separate
     * `wfp_line_template_resources` table without touching this one.
     *
     * `suggested_quantity` / `suggested_unit_cost` are SUGGESTIONS, not
     * guaranteed current values: the Add a Line form treats them as defaults
     * and the School Head confirms them.
     */
    public function up(): void
    {
        Schema::create('wfp_line_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('line_type', 20)->default('operations'); // operations|project
            $table->text('objective')->nullable();
            $table->text('activity_expenditure');

            $table->foreignId('resource_kind_id')->nullable()->constrained('wfp_resource_kinds')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('wfp_units')->nullOnDelete();
            $table->decimal('suggested_quantity', 15, 2)->nullable();
            $table->decimal('suggested_unit_cost', 15, 2)->nullable();

            $table->foreignId('account_classification_id')->nullable()->constrained('account_classifications')->nullOnDelete();
            $table->foreignId('funding_source_id')->nullable()->constrained('wfp_funding_sources')->nullOnDelete();
            $table->foreignId('org_classification_id')->nullable()->constrained('wfp_org_classifications')->nullOnDelete();

            $table->text('major_output')->nullable();
            $table->text('performance_indicator')->nullable();
            $table->text('activity_movs')->nullable();

            // Free-form filter tags, comma-separated ("supplies, mooe").
            $table->string('tags')->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('name');
            $table->index('line_type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfp_line_templates');
    }
};
