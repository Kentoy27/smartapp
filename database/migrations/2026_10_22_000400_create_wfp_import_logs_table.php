<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per committed bulk import of WFP master data (lookup records or
     * activity templates): who imported, when, the category, the import mode,
     * the file name, and the result counts.
     *
     * `errors` keeps the row-level failures (line, values, reasons) so the
     * administrator can download the error report after the modal is gone —
     * that is the ONLY thing stored from the uploaded file; the file itself
     * is never kept.
     *
     * Append-only: no updated_at (see ApplicationSmokeTest's fillable/cast
     * check).
     */
    public function up(): void
    {
        Schema::create('wfp_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 40);
            $table->string('mode', 20);
            $table->string('filename')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfp_import_logs');
    }
};
