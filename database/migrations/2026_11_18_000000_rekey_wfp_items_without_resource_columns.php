<?php

use App\Support\WfpGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `group_key` no longer includes the resource columns (unit and unit cost):
     * the several resources of one activity are one WFP row. Rows generated
     * under the old key are re-keyed here so the preview and the export combine
     * them immediately — a plan that is already pending or approved can never
     * be regenerated, and an editable plan should not need a manual
     * regeneration for a derived grouping value to be corrected.
     *
     * Only the grouping hash is rewritten; every target, activity and
     * enrichment field is left exactly as it stands.
     */
    public function up(): void
    {
        DB::table('wfp_items')->orderBy('id')->chunkById(500, function ($items): void {
            foreach ($items as $item) {
                DB::table('wfp_items')->where('id', $item->id)->update([
                    'group_key' => WfpGenerator::groupKey([
                        'section' => $item->section,
                        'objective' => $item->objective,
                        'program_activity' => $item->program_activity,
                        'account_title' => $item->account_title,
                        'account_code' => $item->account_code,
                    ]),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Not reversible: the old key also hashed the source MEM line's unit
        // cost, which a WFP row does not store, so it cannot be rebuilt.
    }
};
