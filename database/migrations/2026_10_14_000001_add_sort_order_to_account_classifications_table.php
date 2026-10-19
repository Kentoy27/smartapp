<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A UACS account's canonical order in a generated WFP.
     *
     * `sort_order` is the authoritative sequence; where it is null the object
     * code is the fallback (a fixed-width string sorts as the reader expects).
     * The generated WFP orders its rows by account code this way, so nobody
     * arranges the plan's rows by hand. It is append-only-safe: existing rows
     * get null and keep sorting by their code.
     */
    public function up(): void
    {
        Schema::table('account_classifications', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->nullable()->after('covering_ppas')->index();
        });
    }

    public function down(): void
    {
        Schema::table('account_classifications', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
