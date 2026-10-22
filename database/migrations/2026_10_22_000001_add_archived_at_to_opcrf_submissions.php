<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
