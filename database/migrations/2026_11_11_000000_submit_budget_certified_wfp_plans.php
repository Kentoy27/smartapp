<?php

use App\Models\WfpPlan;
use App\Support\WfpReportSignoffs;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Budget's sign-offs were traditionally recorded from the Per Line Item
     * Expenditure and School Allotments pages, which left a fully reviewed
     * plan in `generated`. The submission transition now runs from every
     * sign-off path; this backfills the plans the old paths left behind so
     * they reach the Superadmin's queue without asking Budget to sign again
     * (a certification cannot be repeated).
     */
    public function up(): void
    {
        $plans = WfpPlan::query()
            ->whereIn('status', [
                WfpPlan::STATUS_DRAFT,
                WfpPlan::STATUS_GENERATED,
                WfpPlan::STATUS_RETURNED,
            ])
            ->get();

        foreach ($plans as $plan) {
            if ($plan->school_id === null) {
                continue;
            }

            WfpReportSignoffs::submitReadyPlan(
                (int) $plan->school_id,
                (int) $plan->year,
                $plan->schoolDivision(),
            );
        }
    }

    public function down(): void
    {
        // Additive workflow backfill. These plans were submitted, not deleted;
        // undoing it would silently pull them from the Superadmin's queue.
    }
};
