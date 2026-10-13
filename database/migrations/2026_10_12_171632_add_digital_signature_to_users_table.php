<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A digital signature belongs to one account: every user and every superadmin
 * manages their own, and it is stamped onto the documents the system generates
 * for them. One signature per account — the current one — so the columns live
 * on `users` rather than in their own table.
 *
 * `signature_uploaded_at` is the first upload (never rewritten while the
 * signature is merely replaced); `signature_updated_at` moves on every change.
 * The stored file itself is a private `local`-disk path, served back through
 * an authorised route rather than from the public directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('google_id');
            $table->string('signature_original_name')->nullable()->after('signature_path');
            $table->string('signature_mime_type')->nullable()->after('signature_original_name');
            $table->unsignedBigInteger('signature_size')->nullable()->after('signature_mime_type');
            $table->timestamp('signature_uploaded_at')->nullable()->after('signature_size');
            $table->timestamp('signature_updated_at')->nullable()->after('signature_uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'signature_path',
                'signature_original_name',
                'signature_mime_type',
                'signature_size',
                'signature_uploaded_at',
                'signature_updated_at',
            ]);
        });
    }
};
