<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track which account corrected a line's account code, so the Monthly
     * Expenditure Matrix and the Budget office's checking page can mark a
     * line as "corrected by bookkeeper". Only bookkeepers stamp this; a
     * School Head's own account-code edit leaves it null.
     */
    public function up(): void
    {
        Schema::table('monthly_expenditure_items', function (Blueprint $table): void {
            $table->foreignId('corrected_by')->nullable()
                ->constrained('users')->nullOnDelete()
                ->after('account_code');
            $table->timestamp('corrected_at')->nullable()
                ->after('corrected_by');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_expenditure_items', function (Blueprint $table): void {
            $table->dropForeign(['corrected_by']);
            $table->dropColumn(['corrected_by', 'corrected_at']);
        });
    }
};
