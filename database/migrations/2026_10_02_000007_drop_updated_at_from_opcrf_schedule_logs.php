<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The schedule audit trail is append-only.
 *
 * 000005 created opcrf_schedule_logs with a full timestamps() pair, which
 * gave the table an updated_at that nothing ever writes: the model stamps
 * created_at and never updates a row. This brings databases that already ran
 * 000005 in line with the corrected definition, which declares created_at
 * alone.
 *
 * Guarded by hasColumn so it is a no-op on a database created fresh from the
 * updated 000005.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('opcrf_schedule_logs', 'updated_at')) {
            Schema::table('opcrf_schedule_logs', function (Blueprint $table) {
                $table->dropColumn('updated_at');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('opcrf_schedule_logs', 'updated_at')) {
            Schema::table('opcrf_schedule_logs', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable();
            });
        }
    }
};
