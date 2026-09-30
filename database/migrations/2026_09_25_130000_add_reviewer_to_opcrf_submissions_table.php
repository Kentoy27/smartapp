<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            // The superadmin the staff member chose to send this OPCR to:
            // only that account sees the submission in Review Opcrf (and may
            // download its workbook). NULL = sent before routing existed, or
            // the recipient's account was deleted — unassigned submissions
            // stay visible to every superadmin so they are never orphaned.
            $table->foreignId('reviewer_id')->nullable()->after('approved_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewer_id');
        });
    }
};
