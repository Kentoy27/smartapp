<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The line's final performance entry: the major output it delivers and the
     * indicator that output is measured against. Both are captured on the MEM
     * line, so the WFP generated from it starts enriched instead of asking the
     * School Head to type the same wording again.
     */
    public function up(): void
    {
        Schema::table('monthly_expenditure_items', function (Blueprint $table) {
            $table->text('major_output')->nullable()->after('activity_movs');
            $table->text('performance_indicator')->nullable()->after('major_output');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_expenditure_items', function (Blueprint $table) {
            $table->dropColumn(['major_output', 'performance_indicator']);
        });
    }
};
