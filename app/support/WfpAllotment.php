<?php

namespace App\Support;

use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\WfpReportCheck;
use Carbon\CarbonInterface;

/**
 * The annual MOOE allotment: one school's year of Monthly Expenditure Matrices
 * read the two ways the printed report reads them — month by month, and rolled
 * up per account code with each line's share of the year's total — alongside
 * the allotment budget the Budget office set for the School Head to consume.
 *
 * This is the single source of truth for both the page and the .xlsx export,
 * so the workbook a School Head downloads carries exactly the figures they
 * read on screen. Every SPENT amount is quantity × unit cost, recomputed here
 * (never a stored total that might predate the last edit), which is the same
 * rule the matrix editor, the MEM exporter and the WFP generator follow. Only
 * the allotted amount itself is stored (school_allotments), because it is a
 * decision, not a derived figure.
 */
final class WfpAllotment
{
    /**
     * Every year this school has a matrix for, newest first. A school with
     * none still gets one entry — the current year — so a year select is
     * never empty and a report is never unavailable.
     *
     * @return array<int, string>
     */
    public static function years(?int $schoolId): array
    {
        $years = $schoolId === null
            ? []
            : MonthlyExpenditureMatrix::query()
                ->where('school_id', $schoolId)
                ->distinct()
                ->orderByDesc('year')
                ->pluck('year')
                ->map(fn ($year) => (string) $year)
                ->all();

        if ($years === []) {
            $years[] = (string) now()->year;
        }

        return $years;
    }

    /**
     * The year asked for when the school has a matrix for it, and otherwise
     * the newest year it does. Both the page and the export resolve the year
     * through this, so a crafted `?year=` on the download can never report on
     * a year the school has nothing in.
     */
    public static function resolveYear(?int $schoolId, int $requested): int
    {
        $years = array_map(intval(...), self::years($schoolId));

        return in_array($requested, $years, true) ? $requested : (int) ($years[0] ?? now()->year);
    }

    /**
     * The allotment budget the Budget office set for this school and year, or
     * null when none has been set. Null and 0.00 are different answers: an
     * allotment explicitly set to zero is a decision, while no row at all
     * means the office has not set one yet.
     */
    public static function allotment(?int $schoolId, int $year): ?float
    {
        if ($schoolId === null) {
            return null;
        }

        $amount = SchoolAllotment::query()
            ->where('school_id', $schoolId)
            ->where('year', $year)
            ->value('amount');

        return $amount === null ? null : round((float) $amount, 2);
    }

    /**
     * The Budget office's certification that this school's allotment is
     * available, or null when it has not been certified. The return shape is
     * the one the report's signatory block needs, so the page and the export
     * cannot describe the signer differently.
     *
     * A row whose certifier account no longer exists is not certified: the
     * signature block must never carry a name it cannot produce.
     *
     * @return array{name: string, position: string, certified_at: CarbonInterface}|null
     */
    public static function certification(?int $schoolId, int $year): ?array
    {
        if ($schoolId === null) {
            return null;
        }

        $allotment = SchoolAllotment::query()
            ->with('certifier')
            ->where('school_id', $schoolId)
            ->where('year', $year)
            ->first();

        if ($allotment === null || ! $allotment->isCertified() || $allotment->certifier === null) {
            return null;
        }

        return [
            'name' => $allotment->certifier->name ?: (string) $allotment->certifier->username,
            'position' => $allotment->certifier->position ?: 'Budget Officer III',
            'certified_at' => $allotment->certified_at,
        ];
    }

    /**
     * The Bookkeeper's check of this school's and year's account titles and
     * codes, or null when the report has not been checked. The return shape
     * is the one the report's signatory block needs, so the page and the
     * export cannot describe the signer differently.
     *
     * A row whose checker account no longer exists is not checked: the
     * signature block must never carry a name it cannot produce.
     *
     * @return array{name: string, position: string, checked_at: CarbonInterface}|null
     */
    public static function checking(?int $schoolId, int $year): ?array
    {
        if ($schoolId === null) {
            return null;
        }

        $check = WfpReportCheck::query()
            ->with('checker')
            ->where('school_id', $schoolId)
            ->where('year', $year)
            ->first();

        if ($check === null || ! $check->isChecked() || $check->checker === null) {
            return null;
        }

        return [
            'name' => $check->checker->name ?: (string) $check->checker->username,
            'position' => $check->checker->position ?: 'Senior Bookkeeper',
            'checked_at' => $check->checked_at,
        ];
    }

    /**
     * The year's allotments and their consumption at a glance: the monitoring
     * strip on the Budget office's page and dashboard.
     *
     * Consumption is read only for schools that HAVE an allotment, so the
     * remaining figure is the remainder of money the office actually set. A
     * school with no ceiling contributes an expenditure figure that would
     * make "remaining" describe money that was never allotted.
     *
     * @return array{
     *     schools: int,
     *     allotted_schools: int,
     *     certified_schools: int,
     *     allotted_total: float,
     *     consumed_total: float,
     *     remaining_total: float
     * }
     */
    public static function overview(int $year): array
    {
        $allotments = SchoolAllotment::query()->where('year', $year);

        $allottedSchools = (clone $allotments)->count();
        $certifiedSchools = (clone $allotments)
            ->whereNotNull('certified_by')
            ->whereNotNull('certified_at')
            ->count();
        $allottedTotal = round((float) (clone $allotments)->sum('amount'), 2);

        $consumed = MonthlyExpenditureItem::query()
            ->join('monthly_expenditure_matrices as mem', 'mem.id', '=', 'monthly_expenditure_items.matrix_id')
            ->join('school_allotments as sa', function ($join) use ($year): void {
                $join->on('sa.school_id', '=', 'mem.school_id')
                    ->where('sa.year', '=', $year);
            })
            ->where('mem.year', $year)
            ->selectRaw(
                'SUM(ROUND(COALESCE(monthly_expenditure_items.quantity, 0) '
                .'* COALESCE(monthly_expenditure_items.unit_cost, 0), 2)) as consumed'
            )
            ->first();

        $consumedTotal = round((float) ($consumed?->consumed ?? 0), 2);

        return [
            'schools' => School::query()->count(),
            'allotted_schools' => $allottedSchools,
            'certified_schools' => $certifiedSchools,
            'allotted_total' => $allottedTotal,
            'consumed_total' => $consumedTotal,
            'remaining_total' => round($allottedTotal - $consumedTotal, 2),
        ];
    }

    /**
     * The allotment budget read against a consumed figure — the balance, the
     * percentage used and the "over" flag. Both the School Head's annual
     * report and the matrix editor derive their figures through this, so the
     * two surfaces can never disagree about what is left.
     *
     * With no allotment there is no balance to report: `remaining` stays 0.0
     * rather than showing a negative figure the Budget office never set.
     *
     * @return array{allotted: ?float, consumed: float, remaining: float, percent: float, is_over: bool}
     */
    public static function balance(?float $allotted, float $consumed): array
    {
        $consumed = round($consumed, 2);

        return [
            'allotted' => $allotted,
            'consumed' => $consumed,
            'remaining' => $allotted === null ? 0.0 : round($allotted - $consumed, 2),
            'percent' => $allotted !== null && $allotted > 0
                ? round($consumed / $allotted * 100, 2)
                : 0.0,
            'is_over' => $allotted !== null && $consumed > $allotted,
        ];
    }

    /**
     * The year's consumed total per school, for the schools on one page of the
     * allotment manager — one query rather than one per row.
     *
     * The rule is the same as lineItems(): quantity × unit cost, rounded per
     * line. The ROUND inside SUM is what keeps the manager's figure equal to
     * the School Head's report to the cent.
     *
     * @param  array<int, int>  $schoolIds
     * @return array<int, float>
     */
    public static function consumedBySchool(array $schoolIds, int $year): array
    {
        if ($schoolIds === []) {
            return [];
        }

        return MonthlyExpenditureItem::query()
            ->join('monthly_expenditure_matrices as mem', 'mem.id', '=', 'monthly_expenditure_items.matrix_id')
            ->whereIn('mem.school_id', $schoolIds)
            ->where('mem.year', $year)
            ->groupBy('mem.school_id')
            ->selectRaw(
                'mem.school_id as school_id, '
                .'SUM(ROUND(COALESCE(monthly_expenditure_items.quantity, 0) '
                .'* COALESCE(monthly_expenditure_items.unit_cost, 0), 2)) as consumed'
            )
            ->pluck('consumed', 'school_id')
            ->map(fn ($consumed): float => round((float) $consumed, 2))
            ->all();
    }

    /**
     * The MONTH / BUDGET column: the calendar year, each month against the
     * total of its own matrix. A month with no matrix reads as zero rather
     * than vanishing — the report is a year, and a gap in it is information
     * the reader wants to see.
     *
     * @return array<int, array{label: string, budget: float, has_matrix: bool}>
     */
    public static function months(?int $schoolId, int $year): array
    {
        $matrices = $schoolId === null
            ? collect()
            : MonthlyExpenditureMatrix::query()
                ->where('school_id', $schoolId)
                ->where('year', $year)
                ->with('items')
                ->get()
                ->keyBy('month');

        $months = [];

        foreach (config('mem.months', []) as $number => $name) {
            $matrix = $matrices->get($number);

            $months[] = [
                'label' => $name,
                'budget' => $matrix === null
                    ? 0.0
                    : round($matrix->items->sum(fn (MonthlyExpenditureItem $item): float => self::lineTotal($item)), 2),
                'has_matrix' => $matrix !== null,
            ];
        }

        return $months;
    }

    /**
     * The No. / Account Title / Account Code / Total Amount / % table: the
     * year's lines rolled up per account code — or per account title when a
     * line carries no code — in account-code order, each with its share of the
     * grand total.
     *
     * Grouping by code rather than by line is what makes this the allotment
     * per line item: a code the school spent from in March and again in
     * September is one row with the year's total, not two.
     *
     * @return array<int, array{title: string, code: ?string, total: float, percent: float}>
     */
    public static function lineItems(?int $schoolId, int $year): array
    {
        if ($schoolId === null) {
            return [];
        }

        $items = MonthlyExpenditureItem::query()
            ->whereHas('matrix', fn ($query) => $query
                ->where('school_id', $schoolId)
                ->where('year', $year))
            ->get(['account_title', 'account_code', 'quantity', 'unit_cost']);

        /** @var array<string, array{title: string, code: ?string, total: float}> $grouped */
        $grouped = [];
        $sum = 0.0;

        foreach ($items as $item) {
            $code = trim((string) $item->account_code);
            $title = trim((string) $item->account_title);

            // No code is not no line: an uncoded line is grouped by its title
            // so two spellings of the same account do not each get a row.
            $key = $code !== '' ? 'code:'.$code : 'title:'.mb_strtolower($title);

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'title' => $title !== '' ? $title : 'Unclassified',
                    'code' => $code !== '' ? $code : null,
                    'total' => 0.0,
                ];
            }

            $total = self::lineTotal($item);
            $grouped[$key]['total'] = round($grouped[$key]['total'] + $total, 2);
            $sum = round($sum + $total, 2);
        }

        $rows = array_values($grouped);

        // Account-code order, with the code as text (the codes are fixed-width,
        // so text order is the order a reader expects). A row with no code
        // belongs at the end, where the reader is done looking for one.
        usort($rows, function (array $a, array $b): int {
            $aCoded = $a['code'] !== null;
            $bCoded = $b['code'] !== null;

            if ($aCoded !== $bCoded) {
                return $aCoded ? -1 : 1;
            }

            return strnatcasecmp($a['code'] ?? $a['title'], $b['code'] ?? $b['title'])
                ?: strnatcasecmp($a['title'], $b['title']);
        });

        foreach ($rows as &$row) {
            $row['percent'] = $sum > 0.0 ? round($row['total'] / $sum * 100, 2) : 0.0;
        }

        unset($row);

        return $rows;
    }

    /**
     * The year's total: the figure the percentages are shares of. It is the
     * sum of the line-item rows, which is the same money as the sum of the
     * twelve months — one year's lines, read two ways.
     *
     * @param  array<int, array{total: float}>  $lineItems
     */
    public static function total(array $lineItems): float
    {
        return round(array_sum(array_map(
            fn (array $row): float => (float) $row['total'],
            $lineItems
        )), 2);
    }

    /**
     * Quantity × unit cost, rounded to two decimals. A line missing either
     * factor contributes nothing rather than a wrong figure — an incomplete
     * line has no total.
     */
    private static function lineTotal(MonthlyExpenditureItem $item): float
    {
        return round((float) ($item->quantity ?? 0) * (float) ($item->unit_cost ?? 0), 2);
    }
}
