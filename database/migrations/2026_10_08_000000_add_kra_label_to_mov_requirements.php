<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mov_requirements', function (Blueprint $table) {
            $table->string('kra_label')->nullable()->after('mov_number');
        });
    }

    public function down(): void
    {
        Schema::table('mov_requirements', function (Blueprint $table) {
            $table->dropColumn('kra_label');
        });
    }
};
