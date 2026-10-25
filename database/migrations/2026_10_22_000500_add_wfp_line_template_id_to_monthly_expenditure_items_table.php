<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Activity Template a matrix line was encoded from, when it was.
     *
     * Provenance only: every value the template contributed is copied onto
     * the line itself (the account link, the resource text, the quantities),
     * so the saved line is a snapshot that later template edits never touch.
     * The id is kept so the editor can show "filled from template" and so a
     * template that falls out of use can be traced — never to re-read values.
     *
     * Nullable and null-on-delete: a line without a template is the normal
     * case (manual entry), and removing a template must not touch the lines
     * it once helped write.
     */
    public function up(): void
    {
        Schema::table('monthly_expenditure_items', function (Blueprint $table) {
            $table->foreignId('wfp_line_template_id')
                ->nullable()
                ->after('account_classification_id')
                ->constrained('wfp_line_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monthly_expenditure_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wfp_line_template_id');
        });
    }
};
