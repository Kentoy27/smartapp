<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per uploaded Work and Financial Plan workbook.
     *
     * The workbook itself lives on the `local` disk (paths like
     * wfp/{userId}/...) and is served through an authenticated route; the row
     * only keeps the metadata the WFP page displays. A staff member keeps a
     * single active plan — a new upload replaces the previous row (and its
     * file, removed by the model's deleting hook).
     */
    public function up(): void
    {
        Schema::create('wfp_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_file_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('school_year')->nullable();
            $table->string('school_name')->nullable();
            $table->string('status', 20)->default('uploaded');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'uploaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfp_submissions');
    }
};
