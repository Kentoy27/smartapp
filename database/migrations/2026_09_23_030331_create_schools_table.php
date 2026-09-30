<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            // A school name only needs to be unique within its district
            // (two districts can each have a "San Isidro Elementary").
            $table->unique(['district_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
