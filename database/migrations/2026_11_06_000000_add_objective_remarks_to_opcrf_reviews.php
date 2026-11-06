<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcrf_reviews', function (Blueprint $table): void {
            $table->json('objective_remarks')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('opcrf_reviews', function (Blueprint $table): void {
            $table->dropColumn('objective_remarks');
        });
    }
};
