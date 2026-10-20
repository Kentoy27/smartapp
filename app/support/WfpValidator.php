<?php

namespace App\Support;

use App\Models\AccountClassification;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\WfpItem;
use App\Models\WfpPlan;
use Illuminate\Support\Collection;

/**
 * Checks a generated WFP against the Monthly Expenditure Matrix it came from
 * and against the account-code master list — the report behind
 * `php artisan wfp:validate` and the validator panel on the plan editor.
 *
 * It answers four questions, none of which the generator can answer alone:
 *
 *   - do the plan's month totals add up to the matrices'? (to the centavo)
 *   - does the grand total match?
 *   - does the plan charge any account code the master list does not know?
 *   - which rows are still missing their Major Output / Performance Indicator,
 *     and which activities read as near-duplicates of each other?
 *
 * Everything is derived on every call — nothing is stored — and the near-
 * duplicate check WARNS only: it never merges rows the SH typed.
 */
final class WfpValidator
{
    /** At most this many near-duplicate pairs are reported. */
    private const NEAR_DUPLICATE_LIMIT = 50;

    /**
     * @return array{
     *     passed: bool,
     *     months: array<int, array{month: int, label: string, matrix: float, plan: float, matches: bool}>,
     *     grand: array{matrix: float, plan: float, matches: bool},
     *     unknownCodes: array<int, array{code: string, count: int}>,
     *     missing: array{major_output: int, performance_indicator: int, count: int, items: array<int, array{id: int, activity: string}>},
     *     nearDuplicates: array<int, array{a: string, b: string, distance: int}>
     * }
     */
    public static function report(WfpPlan $plan): array
    {
        $items = $plan->items()->get();

        $planByMonth = [];

        foreach ($items as $item) {
            $month = (int) ($item->month ?? 0);
            $planByMonth[$month] = round(($planByMonth[$month] ?? 0.0) + (float) $item->financial_target, 2);
        }

        $matrixByMonth = self::matrixTotalsByMonth((int) $plan->school_id, (int) $plan->year);

        $months = [];
        $grandMatrix = 0.0;
        $grandPlan = 0.0;

        foreach (range(1, 12) as $month) {
            $matrix = $matrixByMonth[$month] ?? 0.0;
            $planned = $planByMonth[$month] ?? 0.0;
            $grandMatrix += $matrix;
            $grandPlan += $planned;

            $months[] = [
                'month' => $month,
                'label' => (string) (config('mem.months')[$month] ?? $month),
                'matrix' => $matrix,
                'plan' => $planned,
                'matches' => self::close($matrix, $planned),
            ];
        }

        $grandMatrix = round($grandMatrix, 2);
        $grandPlan = round($grandPlan, 2);
        $grandMatches = self::close($grandMatrix, $grandPlan);
        $monthsMatch = array_reduce($months, fn (bool $carry, array $row): bool => $carry && $row['matches'], true);

        $unknownCodes = self::unknownCodes($items);
        $missing = self::missingEnrichment($items);
        $nearDuplicates = self::nearDuplicates($items);

        return [
            'passed' => $monthsMatch && $grandMatches && $unknownCodes === [],
            'months' => $months,
            'grand' => [
                'matrix' => $grandMatrix,
                'plan' => $grandPlan,
                'matches' => $grandMatches,
            ],
            'unknownCodes' => $unknownCodes,
            'missing' => $missing,
            'nearDuplicates' => $nearDuplicates,
        ];
    }

    /**
     * Each month's matrix total (quantity × unit cost, recomputed as the rest
     * of the app does), for the plan's school and year.
     *
     * @return array<int, float>
     */
    private static function matrixTotalsByMonth(int $schoolId, int $year): array
    {
        $totals = [];

        $matrices = MonthlyExpenditureMatrix::query()
            ->where('school_id', $schoolId)
            ->where('year', $year)
            ->with('items')
            ->get();

        foreach ($matrices as $matrix) {
            $sum = 0.0;

            foreach ($matrix->items as $item) {
                $sum += round((float) ($item->quantity ?? 0) * (float) ($item->unit_cost ?? 0), 2);
            }

            $totals[(int) $matrix->month] = round($sum, 2);
        }

        return $totals;
    }

    /**
     * Account codes charged in the plan that the master list does not carry,
     * with how many plan lines each accounts for.
     *
     * @param  Collection<int, WfpItem>  $items
     * @return array<int, array{code: string, count: int}>
     */
    private static function unknownCodes(Collection $items): array
    {
        $known = [];

        foreach (AccountClassification::query()->pluck('uacs_object_code') as $code) {
            $key = self::codeKey((string) $code);

            if ($key !== '') {
                $known[$key] = true;
            }
        }

        $unknown = [];

        foreach ($items as $item) {
            $code = trim((string) $item->account_code);

            if ($code === '' || isset($known[self::codeKey($code)])) {
                continue;
            }

            $unknown[$code] = ($unknown[$code] ?? 0) + 1;
        }

        $rows = [];

        foreach ($unknown as $code => $count) {
            $rows[] = ['code' => (string) $code, 'count' => $count];
        }

        return $rows;
    }

    /**
     * Rows still missing a Major Output or a Performance Indicator — the two
     * enrichment fields the MEM line can supply, but this WFP row has not.
     *
     * @param  Collection<int, WfpItem>  $items
     * @return array{major_output: int, performance_indicator: int, count: int, items: array<int, array{id: int, activity: string}>}
     */
    private static function missingEnrichment(Collection $items): array
    {
        $missingMajor = 0;
        $missingIndicator = 0;
        $rows = [];

        foreach ($items as $item) {
            $noMajor = trim((string) $item->major_output) === '';
            $noIndicator = trim((string) $item->performance_indicator) === '';

            $missingMajor += $noMajor ? 1 : 0;
            $missingIndicator += $noIndicator ? 1 : 0;

            if ($noMajor || $noIndicator) {
                $rows[] = ['id' => (int) $item->id, 'activity' => (string) $item->program_activity];
            }
        }

        return [
            'major_output' => $missingMajor,
            'performance_indicator' => $missingIndicator,
            'count' => count($rows),
            'items' => array_slice($rows, 0, 50),
        ];
    }

    /**
     * Activities whose names are close but not identical — a likely duplicate
     * the SH typed twice with different wording. Levenshtein distance, within
     * 15% of the longer name (at least 2 edits); WARN only.
     *
     * @param  Collection<int, WfpItem>  $items
     * @return array<int, array{a: string, b: string, distance: int}>
     */
    private static function nearDuplicates(Collection $items): array
    {
        $names = [];

        foreach ($items as $item) {
            $normalized = self::normalizeName((string) $item->program_activity);

            if ($normalized !== '') {
                $names[] = ['name' => (string) $item->program_activity, 'normalized' => $normalized];
            }
        }

        $pairs = [];
        $count = count($names);

        for ($i = 0; $i < $count && count($pairs) < self::NEAR_DUPLICATE_LIMIT; $i++) {
            for ($j = $i + 1; $j < $count && count($pairs) < self::NEAR_DUPLICATE_LIMIT; $j++) {
                $a = $names[$i]['normalized'];
                $b = $names[$j]['normalized'];

                if ($a === $b) {
                    continue; // the same activity in another month — not a duplicate
                }

                $distance = levenshtein($a, $b);
                $threshold = max(2, (int) floor(max(strlen($a), strlen($b)) * 0.15));

                if ($distance <= $threshold) {
                    $pairs[] = [
                        'a' => $names[$i]['name'],
                        'b' => $names[$j]['name'],
                        'distance' => $distance,
                    ];
                }
            }
        }

        return $pairs;
    }

    /** Two money figures agree when they differ by less than half a centavo. */
    private static function close(float $a, float $b): bool
    {
        return abs($a - $b) < 0.005;
    }

    private static function codeKey(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }

    private static function normalizeName(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? '';
    }
}
