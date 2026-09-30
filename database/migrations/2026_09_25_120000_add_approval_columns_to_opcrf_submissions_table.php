<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            // The superadmin's review outcome: when the submission was
            // approved and who approved it. NULL = still waiting for review.
            // (Approving may also replace the archived workbook with the
            // corrected/filled copy the superadmin reviewed — see the
            // file_* columns from the earlier migration.)
            $table->timestamp('approved_at')->nullable()->after('file_updated_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
