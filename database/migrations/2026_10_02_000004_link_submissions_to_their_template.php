<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tie a submission to the template it was answered on, and give it a
 * human reference number.
 *
 * `reference` is the identity a person quotes when they talk about a form —
 * "OPCRF-2026-00025" — and is what the dashboard status panel shows.
 *
 * `template_id` points at the opcrf_templates row the account last
 * generated, recording which version of the personalized form the work
 * actually arrived on. Nullable: a submission is only rejected when its
 * contents disagree with the account, so an account that never downloaded a
 * template is not blocked — the link is recorded when there is one to
 * record, and left null honestly when there is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('opcrf_submissions', 'reference')) {
                $table->string('reference', 30)->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('opcrf_submissions', 'opcrf_template_id')) {
                $table->foreignId('opcrf_template_id')
                    ->nullable()
                    ->after('reference')
                    ->constrained('opcrf_templates')
                    ->nullOnDelete();
            }
        });

        // Submissions that predate this column still need a reference: the
        // staff member's dashboard and the review list both show it, and a
        // blank there would look like a broken record rather than an old one.
        // Numbered from each row's own id, exactly as new submissions are.
        $year = now()->format('Y');

        foreach (DB::table('opcrf_submissions')->whereNull('reference')->orderBy('id')->get() as $row) {
            $reference = 'OPCRF-'.$year.'-'.str_pad((string) $row->id, 5, '0', STR_PAD_LEFT);

            if (! DB::table('opcrf_submissions')->where('reference', $reference)->exists()) {
                DB::table('opcrf_submissions')->where('id', $row->id)->update(['reference' => $reference]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('opcrf_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('opcrf_submissions', 'opcrf_template_id')) {
                $table->dropConstrainedForeignId('opcrf_template_id');
            }

            if (Schema::hasColumn('opcrf_submissions', 'reference')) {
                $table->dropUnique(['reference']);
                $table->dropColumn('reference');
            }
        });
    }
};
