<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_submissions', function (Blueprint $table): void {
            $table->index('school_id', 'sip_submissions_school_id_index');
            $table->dropUnique('sip_submissions_school_id_division_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sip_submissions', function (Blueprint $table): void {
            $table->unique(['school_id', 'division']);
            $table->dropIndex('sip_submissions_school_id_index');
        });
    }
};
