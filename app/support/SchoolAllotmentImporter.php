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
 * ones are reported as superseded.
 *
 * A row may name its own DIVISION — the financial division the amount belongs
 * to (Elementary, Junior High School or Senior High School) — so one file can
 * carry a High School's Junior High and Senior High figures at once. The
 * division column is optional: a row without one goes to the division the
 * import was started from (the page's active division), and a School ID may
 * appear once per division (two rows are duplicates only when both the school
 * and the division repeat).
 *
 * A CERTIFIED allotment is locked: a bulk row that would change or clear it
 * is refused, with its reason, exactly as the single-row Save refuses it.
 * Re-stating the same figure changes nothing and is still accepted, so an
 * export re-uploaded unchanged never trips the lock.
 */
final class SchoolAllotmentImporter
{
    /** Upload size ceiling (KB) for a bulk file. */
    public const MAX_SIZE_KB = 2048;

    /** Why a bulk row targeting a certified allotment is refused. */
    public const LOCKED_MESSAGE = 'Certified by the Budget Office — this allotment is read-only and cannot be changed.';

    /** The most rows one bulk input may carry. */
    public const MAX_ROWS = 5000;

    /** Accepted upload extensions. */
    public const EXTENSIONS = 'csv,txt';

    /** The largest amount the single-row Save accepts; the same ceiling here. */
    public const AMOUNT_MAX = 9999999999999.99;

    /** The columns the file may carry, in the legacy order. */
    private const ID_HEADERS = ['schoolid', 'school', 'id', 'schoolcode'];

    private const AMOUNT_HEADERS = ['amount', 'allottedbudget', 'allotment', 'budget', 'allottedamount'];

    /** The column naming the row's own division; optional in every input. */
    private const DIVISION_HEADERS = ['division', 'schooldivision', 'sublevel', 'schoolsublevel'];

    /**
     * Read and validate a pasted list. Each line is "School ID, Amount" or
     * "School ID, Division, Amount" — split by a tab, a comma, or a
     * semicolon, whichever the line carries — and a header row is recognised
     * (and skipped) like the CSV's.
     *
     * `$division` is the division a row without one of its own belongs to.
     * `$allowedDivisions` is the list the caller may write to; a row naming
     * another division is refused rather than written. Null means no
     * restriction, which only a caller with no user to check against passes.
     *
     * @param  array<int, string>|null  $allowedDivisions
     * @return array{rows: array<int, array<string, mixed>>, readyCount: int, invalidCount: int, supersededCount: int, lockedCount: int}
     */
    public static function analyzeText(
        string $text,
        int $year,
        bool $blankClears,
        ?string $division = SchoolDivision::ELEMENTARY,
        ?array $allowedDivisions = null,
    ): array {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = ['line' => $index + 1, 'cells' => self::splitLine($line)];
        }

        return self::analyzeRows(self::rowsFromCells($rows), $year, $blankClears, $division, $allowedDivisions);
    }

    /**
     * Read and validate a CSV file. Nothing is written here.
     *
     * @param  array<int, string>|null  $allowedDivisions
     * @return array{rows: array<int, array<string, mixed>>, readyCount: int, invalidCount: int, supersededCount: int, lockedCount: int}
     */
    public static function analyzeCsv(
        string $path,
        int $year,
        bool $blankClears,
        ?string $division = SchoolDivision::ELEMENTARY,
        ?array $allowedDivisions = null,
    ): array {
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

        return self::analyzeRows(self::rowsFromCells($rows), $year, $blankClears, $division, $allowedDivisions);
    }

    /**
     * Validate rows already reduced to School ID + amount (the analyzed
     * shape), each optionally naming its own division. Nothing is written
     * here.
     *
     * @param  array<int, array{line: int, school_id: string, amount: string, division?: ?string}>  $rows
     * @param  array<int, string>|null  $allowedDivisions
     * @return array{rows: array<int, array<string, mixed>>, readyCount: int, invalidCount: int, supersededCount: int, lockedCount: int}
     */
    public static function analyzeRows(
        array $rows,
        int $year,
        bool $blankClears,
        ?string $division = SchoolDivision::ELEMENTARY,
        ?array $allowedDivisions = null,
    ): array {
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException(
                'The list has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller batches.'
            );
        }

        $defaultDivision = $division ?? SchoolDivision::ELEMENTARY;

        // The registry and the year's allotments are read once for the whole
        // batch, however long it is — not once per row. Every division the
        // input names is read in that one pass.
        $schools = self::schoolIndex();

        $allotments = SchoolAllotment::query()
            ->where('year', $year)
            ->get()
            ->keyBy(fn (SchoolAllotment $allotment): string => self::recordKey(
                (int) $allotment->school_id,
                $allotment->schoolDivision(),
            ));

        $analyzed = [];
        $lastIndexByKey = [];

        foreach ($rows as $row) {
            $schoolId = trim($row['school_id']);
            $amountRaw = trim($row['amount']);
            $divisionRaw = trim((string) ($row['division'] ?? ''));
            $school = $schoolId !== '' ? ($schools[mb_strtolower($schoolId)] ?? null) : null;

            $errors = [];
            $warnings = [];

            if ($schoolId === '') {
                $errors[] = 'School ID is required.';
            } elseif ($school === null) {
                $errors[] = 'School not found.';
            }

            // The row's division: the one it names, or the import's default.
            // A row may only name a division the caller is authorized for —
            // never one that merely appears in the file.
            $rowDivision = $defaultDivision;

            if ($divisionRaw !== '') {
                $parsedDivision = SchoolDivision::parse($divisionRaw);

                if ($parsedDivision === null) {
                    $errors[] = 'Division must be '.SchoolDivision::acceptedSpellings().'.';
                } elseif ($allowedDivisions !== null && ! in_array($parsedDivision, $allowedDivisions, true)) {
                    $errors[] = 'You cannot set allotments for the '
                        .SchoolDivision::label($parsedDivision).' division.';
                } else {
                    $rowDivision = $parsedDivision;
                }
            }

            // A duplicate is the same school AND the same division: a High
            // School may legitimately appear once per division in one file.
            $key = $schoolId === '' ? '' : self::recordKey($schoolId, $rowDivision);
            $record = $school !== null ? $allotments->get(self::recordKey((int) $school->id, $rowDivision)) : null;

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

            // A certified allotment is locked: the Budget office's certificate
            // covers the amount on the row, so a bulk row may neither change
            // nor clear it. Re-stating the same figure changes nothing and is
            // still accepted, so a re-uploaded export never trips the lock.
            $locked = $certified && in_array($action, ['update', 'clear'], true);

            if ($locked) {
                $errors[] = self::LOCKED_MESSAGE;
                $action = null;
            }

            $analyzed[] = [
                'line' => (int) $row['line'],
                'school_id' => $schoolId,
                'school_db_id' => $school?->id,
                'school_name' => $school?->name,
                'school_division' => $rowDivision,
                'division_raw' => $divisionRaw,
                'amount' => $amount,
                'amount_raw' => $amountRaw,
                'previous_amount' => $previousAmount,
                'certified' => $certified,
                'locked' => $locked,
                'action' => $action,
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

        // Duplicate School ID (within one division): the last occurrence wins;
        // every earlier one is reported as superseded rather than applied
        // twice.
        foreach ($analyzed as $index => $row) {
            $key = $row['school_id'] === ''
                ? ''
                : self::recordKey($row['school_id'], $row['school_division']);

            if ($key === '' || $index === $lastIndexByKey[$key]) {
                continue;
            }

            $analyzed[$index]['superseded'] = true;
            $analyzed[$index]['superseded_line'] = $analyzed[$lastIndexByKey[$key]]['line'];
        }

        $readyCount = 0;
        $invalidCount = 0;
        $supersededCount = 0;
        $lockedCount = 0;

        foreach ($analyzed as $index => $row) {
            $valid = $row['errors'] === [] && ! $row['superseded'];
            $analyzed[$index]['valid'] = $valid;

            if ($row['superseded']) {
                $supersededCount++;
            } elseif ($row['locked']) {
                // Counted on its own, not as an "error": the row is sound,
                // its target is simply read-only.
                $lockedCount++;
            } elseif (! $valid) {
                $invalidCount++;
            } else {
                $readyCount++;
            }
        }

        return [
            'rows' => $analyzed,
            'readyCount' => $readyCount,
            'invalidCount' => $invalidCount,
            'supersededCount' => $supersededCount,
            'lockedCount' => $lockedCount,
        ];
    }

    /**
     * Apply the rows the Budget officer confirmed. Every row's current state
     * is re-read inside one transaction — the preview's figures are never
     * trusted — and each applied change appends its audit entry. A row whose
     * school no longer exists is reported, not fatal.
     *
     * Each row is written to the division it carries (resolved and authorized
     * at analysis time); `$division` is only the fallback for a row that
     * carries none.
     *
     * @param  array<int, array<string, mixed>>  $rows  the analyzed rows
     * @param  array<int, int>  $excludedLines  lines the officer unchecked
     * @param  array<int, string>|null  $allowedDivisions  null = no restriction
     * @return array{processed: int, created: int, updated: int, cleared: int, unchanged: int, skipped: int, errors: array<int, array{line: int, school_id: string, school_division: string, error: string}>}
     */
    public static function commit(
        array $rows,
        array $excludedLines,
        int $year,
        ?User $user,
        ?string $division = SchoolDivision::ELEMENTARY,
        ?array $allowedDivisions = null,
    ): array {
        $division = $division ?? SchoolDivision::ELEMENTARY;

        return DB::transaction(function () use ($rows, $excludedLines, $year, $user, $division, $allowedDivisions): array {
            $excluded = array_map('intval', $excludedLines);

            $created = 0;
            $updated = 0;
            $cleared = 0;
            $unchanged = 0;
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

                // The row's own division, re-checked here rather than trusted:
                // a row carrying an unknown or unauthorized division is
                // reported, never written.
                $rowDivision = SchoolDivision::parse((string) ($row['school_division'] ?? '')) ?? $division;

                if ($allowedDivisions !== null && ! in_array($rowDivision, $allowedDivisions, true)) {
                    $errors[] = [
                        'line' => (int) $row['line'],
                        'school_id' => (string) $row['school_id'],
                        'school_division' => $rowDivision,
                        'error' => 'DIVISION_NOT_ALLOWED — you cannot set allotments for the '
                            .SchoolDivision::label($rowDivision).' division.',
                    ];
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
                        'school_division' => $rowDivision,
                        'error' => 'SCHOOL_NOT_FOUND — the school no longer exists.',
                    ];
                    $skipped++;

                    continue;
                }

                $record = SchoolAllotment::query()
                    ->where('school_id', $school->id)
                    ->where('school_division', $rowDivision)
                    ->where('year', $year)
                    ->first();

                $previousAmount = $record !== null ? (float) $record->amount : null;

                if ($row['amount'] === null) {
                    // A clear with nothing on record changed nothing.
                    if ($record === null) {
                        $unchanged++;

                        continue;
                    }

                    // A certified allotment is locked: it is never cleared.
                    if ($record->isCertified()) {
                        $errors[] = [
                            'line' => (int) $row['line'],
                            'school_id' => (string) $row['school_id'],
                            'school_division' => $rowDivision,
                            'error' => self::LOCKED_MESSAGE,
                        ];
                        $skipped++;

                        continue;
                    }

                    $record->delete();

                    SchoolAllotmentAudit::record(
                        (int) $school->id,
                        $year,
                        $user,
                        SchoolAllotmentAudit::ACTION_CLEAR,
                        $previousAmount,
                        null,
                        SchoolAllotmentAudit::SOURCE_BULK,
                        $rowDivision,
                    );

                    $cleared++;

                    continue;
                }

                $amount = (float) $row['amount'];
                $amountChanged = $previousAmount === null || $previousAmount !== $amount;
                $certified = $record?->isCertified() ?? false;

                // A certified allotment is locked: the certificate covers the
                // figure on the row, so a changed amount is refused here too.
                // The analysis already refuses such rows; this second check
                // holds even when commit() is handed rows from elsewhere.
                if ($certified && $amountChanged) {
                    $errors[] = [
                        'line' => (int) $row['line'],
                        'school_id' => (string) $row['school_id'],
                        'school_division' => $rowDivision,
                        'error' => self::LOCKED_MESSAGE,
                    ];
                    $skipped++;

                    continue;
                }

                if (! $amountChanged) {
                    // Re-stating the same figure writes nothing — and on a
                    // certified row not even a re-stamped "set by".
                    if (! $certified) {
                        SchoolAllotment::query()->updateOrCreate(
                            [
                                'school_id' => $school->id,
                                'school_division' => $rowDivision,
                                'year' => $year,
                            ],
                            ['amount' => $amount, 'set_by' => $user?->id],
                        );
                    }

                    $unchanged++;

                    continue;
                }

                SchoolAllotment::query()->updateOrCreate(
                    [
                        'school_id' => $school->id,
                        'school_division' => $rowDivision,
                        'year' => $year,
                    ],
                    [
                        'amount' => $amount,
                        'set_by' => $user?->id,
                        // A certified row never reaches a write; clearing both
                        // fields keeps an uncertified row whose certifier
                        // account was deleted from carrying a dangling date.
                        'certified_by' => null,
                        'certified_at' => null,
                    ],
                );

                SchoolAllotmentAudit::record(
                    (int) $school->id,
                    $year,
                    $user,
                    SchoolAllotmentAudit::ACTION_SET,
                    $previousAmount,
                    $amount,
                    SchoolAllotmentAudit::SOURCE_BULK,
                    $rowDivision,
                );

                if ($previousAmount === null) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            return [
                'processed' => $created + $updated + $cleared,
                'created' => $created,
                'updated' => $updated,
                'cleared' => $cleared,
                'unchanged' => $unchanged,
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
     * The divisions the selected rows would write to are named too, in the
     * canonical order, so the confirmation can say exactly which divisions
     * the change lands in.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $excludedLines
     * @return array{selected: int, locked: int, superseded: int, invalid: int, divisions: array<int, string>}
     */
    public static function selectionCounts(array $rows, array $excludedLines): array
    {
        $excluded = array_map('intval', $excludedLines);

        $selected = 0;
        $locked = 0;
        $superseded = 0;
        $invalid = 0;
        $divisions = [];

        foreach ($rows as $row) {
            if ($row['superseded']) {
                $superseded++;

                continue;
            }

            if ($row['locked']) {
                $locked++;

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
            $divisions[(string) ($row['school_division'] ?? SchoolDivision::ELEMENTARY)] = true;
        }

        return [
            'selected' => $selected,
            'locked' => $locked,
            'superseded' => $superseded,
            'invalid' => $invalid,
            'divisions' => array_values(array_intersect(SchoolDivision::all(), array_keys($divisions))),
        ];
    }

    /**
     * The readable names of a set of divisions, in the canonical order —
     * "Senior High School", or "Elementary and Senior High School" when a
     * change spans two. Empty when the list is empty, so the caller decides
     * what an empty set means.
     *
     * @param  array<int, string>  $divisions
     */
    public static function divisionSummary(array $divisions): string
    {
        $valid = array_values(array_intersect(
            SchoolDivision::all(),
            array_map(strval(...), $divisions),
        ));

        $labels = array_map(
            fn (string $division): string => SchoolDivision::label($division),
            $valid,
        );

        if ($labels === []) {
            return '';
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' and '.$last;
    }

    /**
     * The downloadable error report for a finished apply: one row per school
     * that could not be applied, with the division it was headed for and the
     * reason it could not be applied — commit-time failures and every skipped
     * preview row alike.
     *
     * @param  array<int, array{line: int, school_id: string, error: string, school_division?: string}>  $errors
     */
    public static function errorReportCsv(array $errors): string
    {
        $lines = [self::csvLine(['Row', 'School ID', 'Division', 'Reason'])];

        foreach ($errors as $error) {
            $division = (string) ($error['school_division'] ?? '');

            $lines[] = self::csvLine([
                (string) $error['line'],
                (string) $error['school_id'],
                SchoolDivision::isValidDivision($division) ? SchoolDivision::label($division) : '',
                (string) $error['error'],
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The downloadable view of one division's year, in the exact column
     * names this importer reads back — "School ID, School Name, Division,
     * Allotted Budget". A blank amount means "not set", so the file doubles
     * as the bulk template: export it, fill the blanks in a spreadsheet, and
     * upload the same file again.
     *
     * @param  array<int, array{school_id: string, school_name: string, school_division: string, amount: ?float}>  $rows
     */
    public static function exportCsv(array $rows): string
    {
        $lines = [self::csvLine(['School ID', 'School Name', 'Division', 'Allotted Budget'])];

        foreach ($rows as $row) {
            $division = (string) ($row['school_division'] ?? '');

            $lines[] = self::csvLine([
                (string) $row['school_id'],
                (string) $row['school_name'],
                SchoolDivision::isValidDivision($division) ? SchoolDivision::label($division) : '',
                $row['amount'] === null ? '' : number_format((float) $row['amount'], 2, '.', ''),
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /* ------------------------------------------------------------- parsing */

    /**
     * Reduce raw cells to School ID + amount pairs, each with the division it
     * names (empty when the row does not name one).
     *
     * A recognised header row names each column, and every row after it is
     * read by those names — a notes column, say, is simply ignored. Without a
     * header the columns are positional: a second cell that names a division
     * ("12345, Senior High, 2000000") is the division column, and anything
     * else after the School ID is folded back into the amount, so
     * "12345, 2,000,000.00" survives the comma that is both a separator and a
     * thousands separator.
     *
     * @param  array<int, array{line: int, cells: array<int, string>}>  $rows
     * @return array<int, array{line: int, school_id: string, amount: string, division: string}>
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

            $division = '';

            if ($headerMap !== null) {
                $schoolId = self::cellAt($cells, $headerMap['school_id']);
                $amount = self::cellAt($cells, $headerMap['amount']);
                $division = self::cellAt($cells, $headerMap['division']);
            } else {
                // Excel pastes often carry empty trailing cells; an empty
                // second cell is kept, because "12345," means "this school's
                // amount is blank" and the blank-amount rule should decide
                // what that means.
                while (count($cells) > 2 && trim((string) end($cells)) === '') {
                    array_pop($cells);
                }

                $schoolId = (string) ($cells[0] ?? '');
                $amount = '';

                if (count($cells) > 1) {
                    $second = trim((string) $cells[1]);

                    if (SchoolDivision::parse($second) !== null) {
                        // "12345, Senior High, 2000000" — a line that stops
                        // after the division simply carries no amount.
                        $division = $second;
                        $amount = count($cells) > 2
                            ? implode(',', array_slice($cells, 2))
                            : '';
                    } else {
                        $amount = implode(',', array_slice($cells, 1));

                        // "12345, College, 100": the line after the School ID
                        // does not read as an amount, but the cells after the
                        // second one do — the second cell was meant as a
                        // division, so the row is refused for the division it
                        // misspells rather than for an amount that looks fine.
                        if (count($cells) > 2) {
                            $rest = implode(',', array_slice($cells, 2));

                            if (self::parseAmount($amount)['error'] !== null
                                && self::parseAmount($rest)['error'] === null) {
                                $division = $second;
                                $amount = $rest;
                            }
                        }
                    }
                }
            }

            $pairs[] = [
                'line' => (int) $row['line'],
                'school_id' => $schoolId,
                'amount' => $amount,
                'division' => $division,
            ];
        }

        return $pairs;
    }

    /**
     * The column each field sits in, when the first row is a header row:
     * ['school_id' => index, 'amount' => index, 'division' => ?index]. Null
     * when the row is not a header — both the School ID and the amount names
     * must be present, because a single stray word is far more likely to be a
     * data value than half a header. The division column is optional: a file
     * without one writes every row to the import's own division.
     *
     * @param  array<int, string>  $cells
     * @return array{school_id: int, amount: int, division: ?int}|null
     */
    private static function headerMap(array $cells): ?array
    {
        $schoolIdIndex = null;
        $amountIndex = null;
        $divisionIndex = null;

        foreach ($cells as $index => $cell) {
            $normalized = self::normalizeHeader((string) $cell);

            if ($schoolIdIndex === null && in_array($normalized, self::ID_HEADERS, true)) {
                $schoolIdIndex = $index;

                continue;
            }

            if ($amountIndex === null && in_array($normalized, self::AMOUNT_HEADERS, true)) {
                $amountIndex = $index;

                continue;
            }

            if ($divisionIndex === null && in_array($normalized, self::DIVISION_HEADERS, true)) {
                $divisionIndex = $index;
            }
        }

        if ($schoolIdIndex === null || $amountIndex === null) {
            return null;
        }

        return ['school_id' => $schoolIdIndex, 'amount' => $amountIndex, 'division' => $divisionIndex];
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
     * The key one allotment is addressed by: a school and the division the
     * money belongs to. Used both to look up the year's existing rows and to
     * decide whether two input rows describe the same allotment.
     */
    private static function recordKey(int|string $schoolId, string $division): string
    {
        return mb_strtolower(trim((string) $schoolId)).'|'.$division;
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
