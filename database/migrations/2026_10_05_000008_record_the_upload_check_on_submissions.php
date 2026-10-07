<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record what the upload check found, so the reviewer can see it.
 *
 * Nothing about the account/file comparison refuses an upload — staff file
 * their OPCR freely, whatever the workbook says or does not say about who it
 * belongs to. But the information must not be thrown away with the upload
 * window, so two columns carry it to the reviewer:
 *
 *   upload_check   what the comparison found (match / mismatch /
 *                  unreadable / account_unnamed / stamp_mismatch), or null
 *                  when the file carried no stamp and no name was readable;
 *   account_name   the account's registered name as it stood at upload
 *                  time, so the comparison still means something after
 *                  somebody corrects the name in Users.
 *
 * Both nullable and additive: every row filed before this migration reads
 * as "not checked", which is exactly what it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            $table->string('upload_check')->nullable()->after('employee_name');
            $table->string('account_name')->nullable()->after('upload_check');
        });
    }

    public function down(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            $table->dropColumn(['upload_check', 'account_name']);
        });
    }
};