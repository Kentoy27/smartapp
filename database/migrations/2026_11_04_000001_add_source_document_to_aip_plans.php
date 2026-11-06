<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aip_plans', function (Blueprint $table): void {
            $table->string('source_file_path')->nullable();
            $table->string('original_file_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('aip_plans', function (Blueprint $table): void {
            $table->dropColumn(['source_file_path', 'original_file_name']);
        });
    }
};
