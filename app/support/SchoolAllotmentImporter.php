<?php

namespace App\Support;

use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\SchoolAllotmentAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Parses and validates bulk School Allotment rows — a pasted list or a CSV
 * file — so the Budget office can set many schools' annual amounts in one
 * action.
 *
 * The flow is deliberately two-phase, like AccountClassificationImporter:
 * `analyze*()` validates every row against the school registry and the year's
 * current allotments and writes nothing, returning the per-row preview the
 * Budget officer confirms; `commit()` then re-reads each row's current state
 * inside one transaction and writes the change plus its audit entry.
 *
 * School ID is the key (exact match, case-insensitive). A duplicate School ID
 * within one input is not an error: the last occurrence wins and the earlier
 * ones are reported as superseded. Changing an amount always clears the row's
 * certification — the same rule the single-row Save follows — and re-stating
 * the same figure keeps it.
 */
final class SchoolAllotmentImporter
{
    /** Upload size ceiling (KB) for a bulk file. */
    public const MAX_SIZE_KB = 2048;

    /** The most rows one bulk input may carry. */
    public const MAX_ROWS = 5000;

    /** Accepted upload extensions. */
    public const EXTENSIONS = 'csv,txt';

    /** The largest amount the single-row Save accepts; the same ceiling here. */
    public const AMOUNT_MAX = 9999999999999.99;

    /** The columns the file may carry, in the legacy order. */
    private const ID_HEADERS = ['schoolid', 'school', 'id', 'schoolcode'];

    private const AMOUNT_HEADERS = ['amount', 'allottedbudget', 'allotment', 'budget', 'allottedamount'];

    /**
     * Read and validate a pasted list. Each line is "School ID, Amount" —
     * split by a tab, a comma, or a semicolon, whichever the line carries —
     * and a header row is recognised (and skipped) like the CSV's.
     *
     * @return array{rows: array<int, array<string, mixed>>, readyCount: int, invalidCount: int, supersededCount: int, clearsCertificationCount: int}
     */
    public static function analyzeText(string $text, int $year, bool $blankClears): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = ['line' => $index + 1, 'cells' => self::splitLine($line)];
        }

        return self::analyzeRows(self::rowsFromCells($rows), $year, $blankClears);
    }

    /**
     * Read and validate a CSV file. Nothing is written here.
     *
     * @return array{rows: array<int, array<string, mixed>>, readyCount: int, invalidCount: int, supersededCount: int, clearsCertificationCount: int}
     */
    public static function analyzeCsv(string $path, int $year, bool $blankClears): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;

            // The first cell of the first row may carry a UTF-8 BOM.
            if ($line === 1 && isset($cells[0])) {
                $cells[0] = self::stripBom((string) $cells[0]);
            }

            if (self::isBlankCells($cells)) {
                continue;
            }

            $rows[] = [
                'line' => $line,
                'cells' => array_map(static fn ($cell): string => trim((string) $cell), $cells),
            ];
        }

        fclose($handle);

        return self::analyzeRows(self::rowsFromCells($rows), $year, $blankClears);
    }

    /**
     * Validate rows already reduced to School ID + amount (the analyzed shape).
     * Nothing is written here.
     *
     * @param  array<int, array{line: int, school_id: string, amount: string}>  $rows
     * @return array{rows: array<int, array<string, mixed>>, readyCount: int, invalidCount: int, supersededCount: int, clearsCertificationCount: int}
     */
    public static function analyzeRows(array $rows, int $year, bool $blankClears): array
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException(
                'The list has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller batches.'
            );
        }

        // The registry and the year's allotments are read once for the whole
        // batch, however long it is — not once per row.
        $schools = self::schoolIndex();

        $allotments = SchoolAllotment::query()
            ->where('year', $year)
            ->get()
            ->keyBy(fn (SchoolAllotment $allotment): int => (int) $allotment->school_id);

        $analyzed = [];
        $lastIndexByKey = [];

        foreach ($rows as $row) {
            $schoolId = trim($row['school_id']);
            $key = mb_strtolower($schoolId);
            $amountRaw = trim($row['amount']);
            $school = $key !== '' ? ($schools[$key] ?? null) : null;
            $record = $school !== null ? $allotments->get($school->id) : null;

            $errors = [];
            $warnings = [];

            if ($schoolId === '') {
                $errors[] = 'School ID is required.';
            } elseif ($school === null) {
                $errors[] = 'School not found.';
            }

            $amount = null;
            $clears = false;

            if ($amountRaw === '') {
                if ($blankClears) {
                    $clears = true;
                } else {
                    $errors[] = 'Amount is required.';
                }
            } else {
                $parsed = self::parseAmount($amountRaw);

                if ($parsed['error'] !== null) {
                    $errors[] = $parsed['error'];
                } else {
                    $amount = $parsed['amount'];

                    if ($parsed['rounded']) {
                        $warnings[] = 'Rounded to 2 decimals.';
                    }
                }
            }

            $previousAmount = $record !== null ? (float) $record->amount : null;
            $certified = $record?->isCertified() ?? false;

            if ($errors === []) {
                if ($clears) {
                    $action = 'clear';
                } elseif ($previousAmount === null) {
                    $action = 'create';
                } elseif ($previousAmount !== $amount) {
                    $action = 'update';
                } else {
                    $action = 'unchanged';
                }
            } else {
                $action = null;
            }

            // Certification follows the amount: a clear always ends it, and a
            // changed figure makes the old certificate untrue. The same figure
            // re-stated keeps it, exactly as the single-row Save does.
            $clearsCertification = $certified && ($clears || ($action === 'update'));

            $analyzed[] = [
                'line' => (int) $row['line'],
                'school_id' => $schoolId,
                'school_db_id' => $school?->id,
                'school_name' => $school?->name,
                'amount' => $amount,
                'amount_raw' => $amountRaw,
                'previous_amount' => $previousAmount,
                'certified' => $certified,
                'action' => $action,
                'clears_certification' => $clearsCertification,
                'errors' => $errors,
                'warnings' => $warnings,
                'superseded' => false,
                'superseded_line' => null,
                'valid' => false,
            ];

            if ($key !== '') {
                $lastIndexByKey[$key] = count($analyzed) - 1;
            }
        }

        // Duplicate School ID: the last occurrence wins; every earlier one is
        // reported as superseded rather than applied twice.
        foreach ($analyzed as $index => $row) {
            $key = mb_strtolower($row['school_id']);

            if ($key === '' || $index === $lastIndexByKey[$key]) {
                continue;
            }

            $analyzed[$index]['superseded'] = true;
            $analyzed[$index]['superseded_line'] = $analyzed[$lastIndexByKey[$key]]['line'];
        }

        $readyCount = 0;
        $invalidCount = 0;
        $supersededCount = 0;
        $clearsCertificationCount = 0;

        foreach ($analyzed as $index => $row) {
            $valid = $row['errors'] === [] && ! $row['superseded'];
            $analyzed[$index]['valid'] = $valid;

            if ($row['superseded']) {
                $supersededCount++;
            } elseif (! $valid) {
                $invalidCount++;
            } else {
                $readyCount++;

                if ($row['clears_certification']) {
                    $clearsCertificationCount++;
                }
            }
        }

        return [
            'rows' => $analyzed,
            'readyCount' => $readyCount,
            'invalidCount' => $invalidCount,
            'supersededCount' => $supersededCount,
            'clearsCertificationCount' => $clearsCertificationCount,
        ];
    }

    /**
     * Apply the rows the Budget officer confirmed. Every row's current state
     * is re-read inside one transaction — the preview's figures are never
     * trusted — and each applied change appends its audit entry. A row whose
     * school no longer exists is reported, not fatal.
     *
     * @param  array<int, array<string, mixed>>  $rows  the analyzed rows
     * @param  array<int, int>  $excludedLines  lines the officer unchecked
     * @return array{processed: int, created: int, updated: int, cleared: int, unchanged: int, cleared_certification: int, skipped: int, errors: array<int, array{line: int, school_id: string, error: string}>}
     */
    public static function commit(array $rows, array $excludedLines, int $year, ?User $user): array
    {
        return DB::transaction(function () use ($rows, $excludedLines, $year, $user): array {
            $excluded = array_map('intval', $excludedLines);

            $created = 0;
            $updated = 0;
            $cleared = 0;
            $unchanged = 0;
            $clearedCertification = 0;
            $skipped = 0;
            $errors = [];

            foreach ($rows as $row) {
                if (! $row['valid'] || $row['superseded']) {
                    $skipped++;

                    continue;
                }

                if (in_array((int) $row['line'], $excluded, true)) {
                    $skipped++;

                    continue;
                }

                // The school existed at preview time; if it is gone by now,
                // the row is reported rather than written against nothing.
                $school = $row['school_db_id'] === null
                    ? null
                    : School::query()->find($row['school_db_id']);

                if ($school === null) {
                    $errors[] = [
                        'line' => (int) $row['line'],
                        'school_id' => (string) $row['school_id'],
                        'error' => 'SCHOOL_NOT_FOUND — the school no longer exists.',
                    ];
                    $skipped++;

                    continue;
                }

                $record = SchoolAllotment::query()
                    ->where('school_id', $school->id)
                    ->where('year', $year)
                    ->first();

                $previousAmount = $record !== null ? (float) $record->amount : null;

                if ($row['amount'] === null) {
                    // A clear with nothing on record changed nothing.
                    if ($record === null) {
                        $unchanged++;

                        continue;
                    }

                    $wasCertified = $record->isCertified();

                    $record->delete();

                    SchoolAllotmentAudit::record(
                        (int) $school->id,
                        $year,
                        $user,
                        SchoolAllotmentAudit::ACTION_CLEAR,
                        $previousAmount,
                        null,
                        SchoolAllotmentAudit::SOURCE_BULK,
                    );

                    $cleared++;

                    if ($wasCertified) {
                        $clearedCertification++;
                    }

                    continue;
                }

                $amount = (float) $row['amount'];
                $amountChanged = $previousAmount === null || $previousAmount !== $amount;
                $wasCertified = $record?->isCertified() ?? false;

                $attributes = ['amount' => $amount, 'set_by' => $user?->id];

                if ($amountChanged) {
                    $attributes['certified_by'] = null;
                    $attributes['certified_at'] = null;
                }

                SchoolAllotment::query()->updateOrCreate(
                    ['school_id' => $school->id, 'year' => $year],
                    $attributes,
                );

                if (! $amountChanged) {
                    $unchanged++;

                    continue;
                }

                SchoolAllotmentAudit::record(
                    (int) $school->id,
                    $year,
                    $user,
                    SchoolAllotmentAudit::ACTION_SET,
                    $previousAmount,
                    $amount,
                    SchoolAllotmentAudit::SOURCE_BULK,
                );

                if ($previousAmount === null) {
                    $created++;
                } else {
                    $updated++;
                }

                if ($wasCertified) {
                    $clearedCertification++;
                }
            }

            return [
                'processed' => $created + $updated + $cleared,
                'created' => $created,
                'updated' => $updated,
                'cleared' => $cleared,
                'unchanged' => $unchanged,
                'cleared_certification' => $clearedCertification,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        });
    }

    /**
     * The rows a confirmation would apply, and what they would do — read from
     * a fresh analysis, so the counts the modal restates are never the
     * browser's.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $excludedLines
     * @return array{selected: int, clears_certification: int, superseded: int, invalid: int}
     */
    public static function selectionCounts(array $rows, array $excludedLines): array
    {
        $excluded = array_map('intval', $excludedLines);

        $selected = 0;
        $clearsCertification = 0;
        $superseded = 0;
        $invalid = 0;

        foreach ($rows as $row) {
            if ($row['superseded']) {
                $superseded++;

                continue;
            }

            if (! $row['valid']) {
                $invalid++;

                continue;
            }

            if (in_array((int) $row['line'], $excluded, true)) {
                continue;
            }

            $selected++;

            if ($row['clears_certification']) {
                $clearsCertification++;
            }
        }

        return [
            'selected' => $selected,
            'clears_certification' => $clearsCertification,
            'superseded' => $superseded,
            'invalid' => $invalid,
        ];
    }

    /**
     * The downloadable error report for a finished apply: one row per school
     * that could not be applied, with the reason it could not — commit-time
     * failures and every skipped preview row alike.
     *
     * @param  array<int, array{line: int, school_id: string, error: string}>  $errors
     */
    public static function errorReportCsv(array $errors): string
    {
        $lines = [self::csvLine(['Row', 'School ID', 'Reason'])];

        foreach ($errors as $error) {
            $lines[] = self::csvLine([
                (string) $error['line'],
                (string) $error['school_id'],
                (string) $error['error'],
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /* ------------------------------------------------------------- parsing */

    /**
     * Reduce raw cells to School ID + amount pairs.
     *
     * A recognised header row names each column, and every row after it is
     * read by those names — a third column (notes, say) is simply ignored.
     * Without a header the columns are positional, and anything after the
     * second is folded back into the amount, so "12345, 2,000,000.00"
     * survives the comma that is both a separator and a thousands separator.
     *
     * @param  array<int, array{line: int, cells: array<int, string>}>  $rows
     * @return array<int, array{line: int, school_id: string, amount: string}>
     */
    private static function rowsFromCells(array $rows): array
    {
        $pairs = [];
        $headerResolved = false;
        $headerMap = null;

        foreach ($rows as $row) {
            $cells = $row['cells'];

            if (! $headerResolved) {
                $headerResolved = true;
                $headerMap = self::headerMap($cells);

                if ($headerMap !== null) {
                    continue;
                }
            }

            if ($headerMap !== null) {
                $schoolId = self::cellAt($cells, $headerMap['school_id']);
                $amount = self::cellAt($cells, $headerMap['amount']);
            } else {
                // Excel pastes often carry empty trailing cells; an empty
                // second cell is kept, because "12345," means "this school's
                // amount is blank" and the blank-amount rule should decide
                // what that means.
                while (count($cells) > 2 && trim((string) end($cells)) === '') {
                    array_pop($cells);
                }

                $schoolId = (string) ($cells[0] ?? '');
                $amount = count($cells) > 1
                    ? implode(',', array_slice($cells, 1))
                    : '';
            }

            $pairs[] = [
                'line' => (int) $row['line'],
                'school_id' => $schoolId,
                'amount' => $amount,
            ];
        }

        return $pairs;
    }

    /**
     * The column each field sits in, when the first row is a header row:
     * ['school_id' => index, 'amount' => index]. Null when the row is not a
     * header — both names must be present, because a single stray word is far
     * more likely to be a data value than half a header.
     *
     * @param  array<int, string>  $cells
     * @return array{school_id: int, amount: int}|null
     */
    private static function headerMap(array $cells): ?array
    {
        $schoolIdIndex = null;
        $amountIndex = null;

        foreach ($cells as $index => $cell) {
            $normalized = self::normalizeHeader((string) $cell);

            if ($schoolIdIndex === null && in_array($normalized, self::ID_HEADERS, true)) {
                $schoolIdIndex = $index;

                continue;
            }

            if ($amountIndex === null && in_array($normalized, self::AMOUNT_HEADERS, true)) {
                $amountIndex = $index;
            }
        }

        if ($schoolIdIndex === null || $amountIndex === null) {
            return null;
        }

        return ['school_id' => $schoolIdIndex, 'amount' => $amountIndex];
    }

    /**
     * @param  array<int, string>  $cells
     */
    private static function cellAt(array $cells, ?int $index): string
    {
        if ($index === null || ! array_key_exists($index, $cells)) {
            return '';
        }

        return (string) $cells[$index];
    }

    /**
     * Split one pasted line on the separator it carries: a tab first (the
     * Excel paste), then a comma, then a semicolon.
     *
     * @return array<int, string>
     */
    private static function splitLine(string $line): array
    {
        foreach (["\t", ',', ';'] as $delimiter) {
            if (str_contains($line, $delimiter)) {
                return array_map(static fn ($cell): string => trim($cell), explode($delimiter, $line));
            }
        }

        return [trim($line)];
    }

    /**
     * Read one amount as a float. Currency symbols, spaces and thousands
     * separators are tolerated; a negative or oversized figure is refused,
     * and more than two decimals are rounded — the preview shows the rounded
     * figure that will be stored, with a note saying so.
     *
     * @return array{amount: ?float, error: ?string, rounded: bool}
     */
    private static function parseAmount(string $raw): array
    {
        $cleaned = str_ireplace(['₱', 'php', '$'], '', $raw);
        $cleaned = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], '', $cleaned);
        $cleaned = trim($cleaned);

        if ($cleaned === '' || ! preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $cleaned)) {
            return ['amount' => null, 'error' => 'Enter the amount as a number.', 'rounded' => false];
        }

        $amount = (float) $cleaned;

        if ($amount < 0) {
            return ['amount' => null, 'error' => 'The allotment cannot be negative.', 'rounded' => false];
        }

        if ($amount > self::AMOUNT_MAX) {
            return ['amount' => null, 'error' => 'The allotment is too large to record.', 'rounded' => false];
        }

        $rounded = round($amount, 2);

        return [
            'amount' => $rounded,
            'error' => null,
            'rounded' => abs($rounded - $amount) > 0.0000001,
        ];
    }

    /**
     * Every school that carries a School ID, keyed by its lower-cased id. The
     * column is unique, so the last one wins only for the rows that cannot be
     * addressed by bulk anyway (a blank id).
     *
     * @return array<string, School>
     */
    private static function schoolIndex(): array
    {
        $index = [];

        foreach (School::query()->select(['id', 'name', 'school_id'])->get() as $school) {
            $key = mb_strtolower(trim((string) $school->school_id));

            if ($key !== '') {
                $index[$key] = $school;
            }
        }

        return $index;
    }

    private static function normalizeHeader(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($value))) ?? '';
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private static function isBlankCells(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    /**
     * @param  array<int, string>  $cells
     */
    private static function csvLine(array $cells): string
    {
        return implode(',', array_map(static function (string $cell): string {
            if ($cell === '') {
                return '';
            }

            return preg_match('/[",\r\n]/', $cell) === 1
                ? '"'.str_replace('"', '""', $cell).'"'
                : $cell;
        }, $cells));
    }
}
