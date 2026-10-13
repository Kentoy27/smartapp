<?php

namespace App\Support;

use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;

/**
 * The annual MOOE allotment, derived: one school's year of Monthly Expenditure
 * Matrices read the two ways the printed report reads them — month by month,
 * and rolled up per account code with each line's share of the year's total.
 *
 * This is the single source of truth for both the page and the .xlsx export,
 * so the workbook a School Head downloads carries exactly the figures they
 * read on screen. Every amount is quantity × unit cost, recomputed here (never
 * a stored total that might predate the last edit), which is the same rule the
 * matrix editor, the MEM exporter and the WFP generator follow.
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
