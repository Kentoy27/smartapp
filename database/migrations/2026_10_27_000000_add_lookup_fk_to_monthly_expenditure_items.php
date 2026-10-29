<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stable lookup links on expenditure lines: instead of keeping resource
     * kind, unit, funding source and org classification as free text only,
     * record which administrator-managed lookup row (if any) a line was
     * encoded from. The text columns stay so a hand-typed value still saves
     * and historical lines keep their wording.
     */
    public function up(): void
    {
        Schema::table('monthly_expenditure_items', function (Blueprint $table) {
            $table->foreignId('resource_kind_id')
                ->nullable()
                ->after('resource_kind')
                ->constrained('wfp_resource_kinds')
                ->nullOnDelete();
            $table->foreignId('unit_id')
                ->nullable()
                ->after('unit')
                ->constrained('wfp_units')
                ->nullOnDelete();
            $table->foreignId('funding_source_id')
                ->nullable()
                ->after('funding_source')
                ->constrained('wfp_funding_sources')
                ->nullOnDelete();
            $table->foreignId('org_classification_id')
                ->nullable()
                ->after('organizational_classification')
                ->constrained('wfp_org_classifications')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monthly_expenditure_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resource_kind_id');
            $table->dropConstrainedForeignId('unit_id');
            $table->dropConstrainedForeignId('funding_source_id');
            $table->dropConstrainedForeignId('org_classification_id');
        });
    }
};
