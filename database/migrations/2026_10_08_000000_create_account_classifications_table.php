<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Account Classification master data behind the UACS / RCA reference
     * table (Account Title, RCA Code, UACS Sub-Object Code, UACS Object Code,
     * Covering PPAS).
     *
     * Every code is a string on purpose: the values can carry leading zeroes
     * and future code formats, which a numeric column would silently mangle.
     * Rows are retired with `is_active = false` rather than deleted, so a
     * classification a historical record points at never disappears.
     */
    public function up(): void
    {
        Schema::create('account_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('account_title');
            $table->string('rca_code', 32);
            $table->string('uacs_sub_object_code', 32);
            $table->string('uacs_object_code', 32);
            $table->string('covering_ppas')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Filtering and lookups run on the title, each code and the status.
            $table->index('account_title');
            $table->index('rca_code');
            $table->index('uacs_sub_object_code');
            $table->index('uacs_object_code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_classifications');
    }
};
