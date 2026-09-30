<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opcrf_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('employee_name');
            $table->string('position');
            $table->string('review_period');
            $table->string('division_office');
            $table->text('objectives');
            $table->text('accomplishments');
            $table->decimal('self_rating', 4, 2);
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opcrf_submissions');
    }
};
