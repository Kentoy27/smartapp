<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff member's position/designation ("Teacher III").
 *
 * The personalised OPCRF template writes a Position into the form's
 * "Position/Designation" box, and the only position-like attribute the
 * accounts table carried was `role` — which holds the permission flag
 * ("user", "superadmin"), not the job. Writing that into a box labelled
 * "Position/Designation" would have put "user" on every teacher's form.
 *
 * Nullable and unindexed: accounts created before this have no position,
 * and the staff member types theirs into the form in that case.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'position')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('position', 150)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
