<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            // The staff member's original uploaded .xlsx — archived so the
            // superadmin can download the literal submitted file and replace
            // it with a corrected copy during review.
            $table->string('file_path')->nullable()->after('remarks');
            $table->string('file_original_name')->nullable()->after('file_path');
            $table->timestamp('file_updated_at')->nullable()->after('file_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_original_name', 'file_updated_at']);
        });
    }
};
