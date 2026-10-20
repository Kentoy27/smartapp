<?php

namespace App\Support;

use App\Models\AccountClassification;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\WfpItem;
use App\Models\WfpPlan;
use Illuminate\Support\Facades\DB;

/**
 * Maps a school's Monthly Expenditure Matrices onto its WFP plan.
 *
 * A MEM line maps 1:1 to a WFP line, so the target lands in the correct month
 * and is never duplicated by construction. Generation RECONCILES the plan in
 * place, matched by `source_expenditure_item_id`:
 *
 *   - MEM-derived columns are refreshed from the current MEM line;
 *   - the enrichment columns (`major_output`, `performance_indicator`,
 *     `funding_source`, `organizational_classification`, `activity_movs`) take
 *     the MEM value when the MEM now carries one, and the existing
 *     WFP-entered value otherwise;
 *   - lines whose MEM line is gone are deleted; new lines are inserted;
 *   - every line records the MEM version it was mapped from.
 *
 * WFP-owned lines (source id null — e.g. a line the SH added by hand) are left
 * alone.
 */
class WfpGenerator
{
    /**
     * Generate (or regenerate) a plan from the given matrix, or, when none is
     * given, from every matrix of the plan's school and year.
     *
     * The desired lines are ordered by their UACS account code (the account
     * classifications' sort order, with the object code as the fallback), then
     * by the first month they appear in, then by activity — so the generated
     * WFP reads in account-code order and nobody rearranges its rows by hand.
     * A code the master list does not know sorts after the known ones (and is
     * surfaced by the validator).
     *
     * @return array{created: int, updated: int, removed: int, flagged: int}
     */
    public static function generate(WfpPlan $plan, ?MonthlyExpenditureMatrix $matrix = null): array
    {
        $matrices = $matrix !== null
            ? [$matrix]
            : MonthlyExpenditureMatrix::query()
                ->where('school_id', $plan->school_id)
                ->where('year', $plan->year)
                ->orderBy('month')
                ->orderBy('id')
                ->get()
                ->all();

        $desired = [];

        foreach ($matrices as $source) {
            /** @var MonthlyExpenditureMatrix $source */
            foreach ($source->items()->orderBy('position')->orderBy('id')->get() as $item) {
                /** @var MonthlyExpenditureItem $item */
                $desired[$item->id] = self::payload($item, $source, 0);
            }
        }

        // Order the rows by account code, then assign the stored positions.
        $desired = self::orderByAccountCode($desired);

        $position = 0;

        foreach (array_keys($desired) as $itemId) {
            $desired[$itemId]['position'] = ++$position;
        }

        return DB::transaction(function () use ($plan, $desired): array {
            $existing = $plan->items()
                ->whereNotNull('source_expenditure_item_id')
                ->get()
                ->keyBy('source_expenditure_item_id');

            $created = 0;
            $updated = 0;

            foreach ($desired as $sourceId => $payload) {
                $wfp = $existing->pull($sourceId) ?? new WfpItem([
                    'wfp_plan_id' => $plan->id,
                    'source_expenditure_item_id' => $sourceId,
                ]);

                $isNew = ! $wfp->exists;

                $wfp->fill($payload['fields']);

                // Enrichment fields: the MEM value wins when it now carries
                // one, so a value the SH typed on the WFP survives regeneration
                // until the MEM supplies its own.
                if ($isNew) {
                    $wfp->major_output = $payload['major_output'];
                    $wfp->performance_indicator = $payload['performance_indicator'];
                    $wfp->funding_source = $payload['funding_source'];
                    $wfp->organizational_classification = $payload['organizational_classification'];
                    $wfp->activity_movs = $payload['activity_movs'];
                } else {
                    $wfp->major_output = self::prefer($payload['major_output'], $wfp->major_output);
                    $wfp->performance_indicator = self::prefer($payload['performance_indicator'], $wfp->performance_indicator);
                    $wfp->funding_source = self::prefer($payload['funding_source'], $wfp->funding_source);
                    $wfp->organizational_classification = self::prefer($payload['organizational_classification'], $wfp->organizational_classification);
                    $wfp->activity_movs = self::prefer($payload['activity_movs'], $wfp->activity_movs);
                }

                $wfp->group_key = $payload['group_key'];
                $wfp->source_matrix_id = $payload['source_matrix_id'];
                $wfp->source_matrix_version = $payload['source_matrix_version'];
                $wfp->position = $payload['position'];

                $wfp->refreshFlags();
                $wfp->save();

                $isNew ? $created++ : $updated++;
            }

            // Anything left over was in the plan but is no longer in the MEM.
            $removed = 0;

            foreach ($existing as $orphan) {
                $orphan->delete();
                $removed++;
            }

            $plan->status = WfpPlan::STATUS_GENERATED;
            $plan->generated_at = now();
            $plan->template_version = (string) config('wfp.template.version', '2026.1');
            // Regeneration changes the content, so any prior approval is void.
            $plan->approved_by = null;
            $plan->approved_at = null;
            $plan->save();

            return [
                'created' => $created,
                'updated' => $updated,
                'removed' => $removed,
                'flagged' => $plan->items()->where('needs_review', true)->count(),
            ];
        });
    }

    /**
     * The rows the Excel export writes into the template.
     *
     * Annual-shaped (the default): group by `group_key` and emit one row per
     * group, with the target being the group's sum. Month-shaped (the template
     * carries a column per month): emit one row per WFP line, keeping its month
     * so the filler writes the target into the right column.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function buildExportRows(WfpPlan $plan, bool $monthly): array
    {
        $items = $plan->items()->get();

        if ($monthly) {
            return $items->map(fn (WfpItem $item): array => array_merge(self::exportRow($item), ['month' => $item->month]))->all();
        }

        return $items
            ->groupBy('group_key')
            ->map(static function ($group): array {
                /** @var WfpItem $first */
                $first = $group->first();

                return array_merge(self::exportRow($first), [
                    'physical_target' => round((float) $group->sum(fn (WfpItem $i): float => (float) $i->physical_target), 2),
                    'financial_target' => round((float) $group->sum(fn (WfpItem $i): float => (float) $i->financial_target), 2),
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function exportRow(WfpItem $item): array
    {
        return [
            'section' => $item->section,
            'objective' => $item->objective,
            'program_activity' => $item->program_activity,
            'major_output' => $item->major_output,
            'performance_indicator' => $item->performance_indicator,
            'physical_target' => $item->physical_target,
            'physical_unit' => $item->physical_unit,
            'financial_target' => $item->financial_target,
            'account_title' => $item->account_title,
            'account_code' => $item->account_code,
            'funding_source' => $item->funding_source,
            'organizational_classification' => $item->organizational_classification,
            'activity_movs' => $item->activity_movs,
        ];
    }

    /**
     * A stable grouping key for a mapped line, from the fields that define a
     * WFP row's grouping: section + objective + activity + account title +
     * account code + unit + unit cost, each normalised.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function groupKey(array $fields): string
    {
        $parts = [
            (string) ($fields['section'] ?? ''),
            (string) ($fields['objective'] ?? ''),
            (string) ($fields['activity_description'] ?? $fields['program_activity'] ?? ''),
            (string) ($fields['account_title'] ?? ''),
            (string) ($fields['account_code'] ?? ''),
            (string) ($fields['unit'] ?? $fields['physical_unit'] ?? ''),
            self::normalizeCost($fields['unit_cost'] ?? $fields['financial_unit_cost'] ?? ''),
        ];

        return sha1(implode('|', array_map([self::class, 'normalize'], $parts)));
    }

    /**
     * @return array{fields: array<string, mixed>, major_output: ?string, performance_indicator: ?string, funding_source: ?string, organizational_classification: ?string, activity_movs: ?string, group_key: string, source_matrix_id: int, source_matrix_version: int, position: int}
     */
    private static function payload(MonthlyExpenditureItem $item, MonthlyExpenditureMatrix $matrix, int $position): array
    {
        return [
            'fields' => [
                'month' => (int) $matrix->month,
                'section' => $item->section,
                'objective' => $item->objective,
                'program_activity' => $item->activity_description,
                'account_title' => $item->account_title,
                'account_code' => $item->account_code,
                'physical_target' => $item->quantity,
                'physical_unit' => $item->unit,
                // The stored total is the server's own quantity × unit cost, but a
                // line that reached the table without it (created outside the
                // editor/importer) still has its money — money must never
                // silently become an empty WFP cell.
                'financial_target' => $item->calculated_total ?? $item->recalculateTotal(),
            ],
            'major_output' => $item->major_output,
            'performance_indicator' => $item->performance_indicator,
            'funding_source' => $item->funding_source,
            'organizational_classification' => $item->organizational_classification,
            'activity_movs' => $item->activity_movs,
            'group_key' => self::groupKey([
                'section' => $item->section,
                'objective' => $item->objective,
                'activity_description' => $item->activity_description,
                'account_title' => $item->account_title,
                'account_code' => $item->account_code,
                'unit' => $item->unit,
                'unit_cost' => $item->unit_cost,
            ]),
            'source_matrix_id' => (int) $matrix->id,
            'source_matrix_version' => (int) $matrix->version,
            'position' => $position,
        ];
    }

    /** The MEM value when it carries one, otherwise whatever was already there. */
    private static function prefer(?string $incoming, ?string $existing): ?string
    {
        return ($incoming !== null && trim($incoming) !== '') ? $incoming : $existing;
    }

    /* ------------------------------------------------------ account ordering */

    /**
     * Sort the desired payloads into WFP order: by the account code's UACS
     * sort order (the object code as fallback, unknown codes last), then the
     * first month the line appears in, then the activity A→Z. Keys are kept
     * (uasort), so the reconcile still matches a line to its source id.
     *
     * @param  array<int, array<string, mixed>>  $desired
     * @return array<int, array<string, mixed>>
     */
    private static function orderByAccountCode(array $desired): array
    {
        if ($desired === []) {
            return $desired;
        }

        $order = self::accountSortOrder();

        uasort($desired, function (array $a, array $b) use ($order): int {
            $fa = is_array($a['fields'] ?? null) ? $a['fields'] : [];
            $fb = is_array($b['fields'] ?? null) ? $b['fields'] : [];

            return self::compareFields($fa, $fb, $order);
        });

        return $desired;
    }

    /**
     * Order two lines: known account codes before unknown ones, then by the
     * master list's sort order, then by the code itself, then by the first
     * month the line appears in, then by activity A→Z.
     *
     * The code is compared with strcmp rather than PHP's `<`, because two
     * numeric-looking code strings ("5020301000") would otherwise be compared
     * as numbers, losing the fixed-width order the UACS codes are built on.
     *
     * @param  array<string, mixed>  $fa
     * @param  array<string, mixed>  $fb
     * @param  array<string, int|null>  $order
     */
    private static function compareFields(array $fa, array $fb, array $order): int
    {
        $ka = self::accountSortKey($fa, $order);
        $kb = self::accountSortKey($fb, $order);

        return ((int) ! $ka['known'] <=> (int) ! $kb['known'])
            ?: ($ka['order'] <=> $kb['order'])
            ?: strcmp($ka['code'], $kb['code'])
            ?: ((int) ($fa['month'] ?? 0) <=> (int) ($fb['month'] ?? 0))
            ?: strcasecmp((string) ($fa['program_activity'] ?? ''), (string) ($fb['program_activity'] ?? ''));
    }

    /**
     * A line's account ordering: whether its code is on the master list, the
     * list's sort order (PHP_INT_MAX when unset) and the raw code.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, int|null>  $order
     * @return array{known: bool, order: int, code: string}
     */
    private static function accountSortKey(array $fields, array $order): array
    {
        $code = trim((string) ($fields['account_code'] ?? ''));
        $key = self::codeKey($code);
        $known = $code !== '' && array_key_exists($key, $order);

        return [
            'known' => $known,
            'order' => $known ? (int) ($order[$key] ?? PHP_INT_MAX) : PHP_INT_MAX,
            'code' => $code,
        ];
    }

    /**
     * The master list's account order, keyed by normalised object code. The
     * smallest non-null `sort_order` wins when a code appears twice; a code
     * with no `sort_order` is still "known" and sorts by its code.
     *
     * @return array<string, int|null>
     */
    private static function accountSortOrder(): array
    {
        $order = [];

        foreach (AccountClassification::query()->get(['uacs_object_code', 'sort_order']) as $account) {
            $key = self::codeKey((string) $account->uacs_object_code);

            if ($key === '') {
                continue;
            }

            $current = $order[$key] ?? null;
            $candidate = $account->sort_order;

            if (! array_key_exists($key, $order) || ($candidate !== null && ($current === null || $candidate < $current))) {
                $order[$key] = $candidate;
            }
        }

        return $order;
    }

    /** The digits-only form of an account code, so formatting cannot split a match. */
    private static function codeKey(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }

    private static function normalize(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    private static function normalizeCost(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
