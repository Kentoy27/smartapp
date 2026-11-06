<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record when a plan was last submitted for the Superadmin's final
     * approval, and by whom. Approval itself stays on `approved_by` /
     * `approved_at`; this pair makes the pending queue explainable even
     * before a decision is recorded.
     */
    public function up(): void
    {
        Schema::table('wfp_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('wfp_plans', 'submitted_by')) {
                $table->foreignId('submitted_by')
                    ->nullable()
                    ->after('approved_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('wfp_plans', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wfp_plans', function (Blueprint $table) {
            if (Schema::hasColumn('wfp_plans', 'submitted_by')) {
                $table->dropConstrainedForeignId('submitted_by');
            }

            if (Schema::hasColumn('wfp_plans', 'submitted_at')) {
                $table->dropColumn('submitted_at');
            }
        });
    }
};
