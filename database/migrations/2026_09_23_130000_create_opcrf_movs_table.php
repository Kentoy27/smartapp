<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opcrf_movs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opcrf_submission_id')->constrained('opcrf_submissions')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opcrf_movs');
    }
};
