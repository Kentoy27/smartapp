<?php

namespace App\Support;

use App\Models\MonthlyExpenditureMatrix;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ensures a school has its twelve monthly matrices for a planning year.
 *
 * Selecting a year is the primary way to view the Monthly Expenditure Matrix
 * page, so a year's months are made to exist lazily rather than one at a time.
 * This is idempotent: a month that already exists is left exactly as it is —
 * its expenditure lines, status, version and audit columns are never touched —
 * and only the missing months are inserted, as blank Drafts.
 *
 * Ownership is by school. The caller passes the school id and the year, and
 * nothing outside that school/year is read or written.
 *
 * Concurrency: the insert is a single multi-row `insertOrIgnore` inside a
 * transaction, so the `unique(school_id, year, month)` constraint makes two
 * requests that race on the same year safe — the loser's rows are quietly
 * skipped instead of raising a duplicate-key error.
 */
final class MemYearInitializer
{
    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    /**
     * Create any missing month (1..12) for the school and year, then return
     * every stored matrix for that school and year in calendar order.
     *
     * @return Collection<int, MonthlyExpenditureMatrix>
     */
    public function initialize(int $schoolId, int $year, ?int $userId = null): Collection
    {
        $existing = MonthlyExpenditureMatrix::query()
            ->where('school_id', $schoolId)
            ->where('year', $year)
            ->pluck('month')
            ->all();

        $missing = array_values(array_diff(range(1, 12), $existing));

        if ($missing !== []) {
            $now = now();

            $rows = array_map(fn (int $month): array => [
                'school_id' => $schoolId,
                'year' => $year,
                'month' => $month,
                'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
                'version' => 1,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $missing);

            // One statement, conflict-safe (INSERT IGNORE / ON CONFLICT DO
            // NOTHING): a month another request created a moment ago is
            // skipped rather than colliding.
            DB::transaction(function () use ($rows): void {
                MonthlyExpenditureMatrix::query()->insertOrIgnore($rows);
            });
        }

        return MonthlyExpenditureMatrix::query()
            ->where('school_id', $schoolId)
            ->where('year', $year)
            ->orderBy('month')
            ->get();
    }
}
