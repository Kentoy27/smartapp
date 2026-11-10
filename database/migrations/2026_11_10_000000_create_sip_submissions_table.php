<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sip_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('division', 20);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_file_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 127)->nullable();
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->unique(['school_id', 'division']);
            $table->index(['division', 'school_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sip_submissions');
    }
};
