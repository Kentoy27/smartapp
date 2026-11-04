<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aip_plans', function (Blueprint $table): void {
            $table->json('form_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('aip_plans', function (Blueprint $table): void {
            $table->dropColumn('form_data');
        });
    }
};
