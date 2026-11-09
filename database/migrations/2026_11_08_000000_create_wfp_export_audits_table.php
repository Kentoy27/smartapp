<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wfp_export_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 40);
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('school_division', 30);
            $table->unsignedSmallInteger('year');
            // Kept as an audit snapshot so deleting a plan does not erase its
            // identifier from the export history.
            $table->unsignedBigInteger('wfp_plan_id')->nullable();
            $table->string('template_version', 32);
            $table->string('export_type', 12);
            $table->timestamps();

            $table->index(['school_id', 'year', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfp_export_audits');
    }
};
