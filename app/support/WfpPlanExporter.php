<?php

namespace App\Support;

use App\Models\MonthlyExpenditureMatrix;
use App\Models\User;
use App\Models\WfpPlan;
use RuntimeException;

/**
 * A plan's WFP as a workbook: the official template patched with the plan's
 * rows and stamped with the school's name.
 *
 * One implementation, two entry points — the plan's own download (the plan
 * editor's `/wfp/plans/{plan}/export`) and the "Generate and Download WFP"
 * button, which prefers the WFP the school's Monthly Expenditure Matrices add
 * up to and only falls back to the blank account-code form when the school has
 * no matrix data yet. Both must hand back the same workbook, so neither owns
 * the patching.
 */
final class WfpPlanExporter
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * The plan as workbook bytes.
     *
     * The plan is not consulted for its "shape": the template's own layout
     * decides whether the targets are written per month or into a single annual
     * column (see WfpTemplateFiller), so a plan stays portable across template
     * revisions.
     *
     * @throws RuntimeException when the template cannot be read
     */
    public static function bytes(WfpPlan $plan): string
    {
        $layout = WfpTemplate::inspect();

        $bytes = WfpTemplateFiller::fill(
            WfpTemplate::bytes(),
            WfpGenerator::buildExportRows($plan, ($layout['months'] ?? []) !== []),
            $layout,
        );

        // The form identifies itself: the plan's school and planning year go
        // into the template's own title block.
        return WfpTemplateFiller::writeCells(
            $bytes,
            WfpTemplateFiller::titleCells((string) ($plan->school?->name ?? ''), (int) $plan->year),
        );
    }

    /**
     * The plan the SH's own download should come from, for the year they
     * picked, brought up to date with the MEM first — or null when the school
     * has nothing to build from, in which case the caller falls back to the
     * blank account-code form.
     *
     * Generation happens HERE rather than leaving a stale workbook to be
     * downloaded: the button says "Generate and Download", and a plan whose
     * matrices have moved on since it was generated would hand the SH numbers
     * the MEM no longer has. A FINALIZED plan is never regenerated behind the
     * approver's back — regeneration voids an approval (see WfpGenerator) — so
     * it is exported exactly as it was approved.
     *
     * The year is a PREFERENCE, not an instruction: a year the school has no
     * plan or matrix lines for resolves to one it does have (see dataYearFor),
     * so a crafted value cannot generate from another year's data.
     */
    public static function exportableFor(?User $user, ?int $preferredYear = null): ?WfpPlan
    {
        $year = self::dataYearFor($user, $preferredYear);

        if ($year === null) {
            return null;
        }

        /** @var User $user */
        $plan = WfpPlan::firstOrCreate(
            ['school_id' => $user->school_id, 'year' => $year],
            ['status' => WfpPlan::STATUS_DRAFT],
        );

        if (! $plan->isFinalized() && (! $plan->items()->exists() || $plan->isOutOfDate())) {
            $plan->generated_by = $user->id;
            WfpGenerator::generate($plan); // reconciles in place, and saves the plan
        }

        return $plan;
    }

    /**
     * The year the SH's generated WFP belongs to: the year they picked when
     * the school has data in it, otherwise the newest year it has data for.
     * Null when it has none: an empty year of matrices is not data (the
     * twelve months exist as soon as a year is opened), so the blank form is
     * then the only thing that can be generated.
     *
     * Read-only on purpose: the card renders it to say which workbook is
     * coming, and rendering must never create a plan.
     */
    public static function dataYearFor(?User $user, ?int $preferredYear = null): ?int
    {
        $years = self::dataYears($user);

        if ($years === []) {
            return null;
        }

        return $preferredYear !== null && in_array($preferredYear, $years, true)
            ? $preferredYear
            : $years[0];
    }

    /**
     * Every year the school has something to generate from — a plan it has
     * already generated, or matrix lines to generate one from — newest first.
     * Empty when it has neither; the caller decides what to offer then (the
     * generate button still offers the current year, for the blank form).
     *
     * @return array<int, int>
     */
    public static function dataYears(?User $user): array
    {
        if ($user === null || ! $user->canAccessWfp() || $user->school_id === null) {
            return [];
        }

        $schoolId = (int) $user->school_id;

        $planYears = WfpPlan::query()
            ->where('school_id', $schoolId)
            ->pluck('year');

        $matrixYears = MonthlyExpenditureMatrix::query()
            ->where('school_id', $schoolId)
            ->whereHas('items')
            ->pluck('year');

        return $planYears
            ->merge($matrixYears)
            ->map(static fn ($year): int => (int) $year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }
}
