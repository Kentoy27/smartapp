<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signature-stamped copy of a submission's workbook.
 *
 * A submission keeps two files: the workbook exactly as the staff member
 * uploaded it (file_path, never rewritten — it is their own document, and the
 * reviewer may need to see it untouched), and the copy the system produced by
 * encoding the account's digital signature into the signer block at the bottom
 * of every part that has one. Downloads serve the stamped copy; the columns
 * are separate so neither file can ever be mistaken for the other.
 *
 * Null when there was nothing to stamp: the account has no signature on file,
 * or the workbook carries no signer block, or it could not be read. The
 * submission is still perfectly valid then — it simply has no stamped copy,
 * and the archived upload is what downloads hand back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            $table->string('signed_file_path')->nullable()->after('file_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            $table->dropColumn('signed_file_path');
        });
    }
};
