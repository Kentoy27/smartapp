<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Budget office's certification that a school's annual allotment is
     * available. It is recorded on the allotment row it certifies: the
     * certification is about that school, that year and that amount, so when
     * the amount changes the certification is cleared and must be given again.
     */
    public function up(): void
    {
        Schema::table('school_allotments', function (Blueprint $table) {
            $table->foreignId('certified_by')->nullable()->after('set_by')->constrained('users')->nullOnDelete();
            $table->timestamp('certified_at')->nullable()->after('certified_by');
        });
    }

    public function down(): void
    {
        Schema::table('school_allotments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certified_by');
            $table->dropColumn('certified_at');
        });
    }
};
