<?php

namespace App\Support;

use App\Models\MonthlyExpenditureItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Parses a single Monthly Expenditure Matrix workbook whose worksheets are the
 * calendar months ("JAN".."DEC") — the file the School Head keeps by hand —
 * into the twelve stored matrices.
 *
 * Two-phase, exactly like MemImporter: `analyzeWorkbook()` reads every monthly
 * sheet, validates each line and writes nothing, returning a per-month preview
 * plus the valid rows; `commit()` then REPLACES each present month's lines
 * inside one transaction (a workbook import is a whole-month replace, not an
 * append, so a re-import never doubles the lines).
 *
 * The reader is fill-aware (see WfpSpreadsheet): the workbook marks its
 * section banners with a gray fill, so a filled row is read as a section
 * heading and never as a line. `calculated_total` is never trusted — it is
 * recomputed server-side by MonthlyExpenditureItem::syncTotal().
 */
final class MemWorkbookImporter
{
    /**
     * Read and validate a multi-sheet workbook. Nothing is written.
     *
     * @return array{
     *     months: array<int, array{month: int, name: string, rows: array<int, array<string, mixed>>, validCount: int, invalidCount: int, total: float}>,
     *     valid: array<int, array<int, array<string, mixed>>>,
     *     validCount: int,
     *     invalidCount: int,
     *     warnings: array<int, string>
     * }
     *
     * @throws RuntimeException when the file is not a readable workbook.
     */
    public static function analyzeWorkbook(string $path, int $schoolId, int $year): array
    {
        try {
            $book = new WfpSpreadsheet($path);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('The workbook could not be read: '.$exception->getMessage());
        }

        $codeWidth = (int) config('wfp.matrix.code_width', 10);
        $gray = config('wfp.matrix.gray_fill');
        $gray = is_string($gray) && $gray !== '' ? $gray : null;

        $months = [];
        $valid = [];
        $warnings = [];
        $validCount = 0;
        $invalidCount = 0;

        foreach ($book->sheetNames() as $name) {
            $month = self::monthForSheet($name);

            if ($month === null) {
                continue; // a "uacs MOOE partial" tab, or anything not a month
            }

            $header = self::headerRow($book, $name);

            if ($header === null) {
                $warnings[] = "Sheet “{$name}” has no recognisable header row and was skipped.";

                continue;
            }

            [$headerRow, $fields] = $header;
            $cells = $book->cellsFor($name);
            $maxRow = self::tableEnd($cells, $fields, $headerRow, self::maxRow($cells));

            $raw = [];
            $currentSection = 'operations';
            $carriedObjective = null;
            $carriedActivity = null;

            for ($row = $headerRow + 1; $row <= $maxRow; $row++) {
                $ordered = self::rowCells($cells, $row);

                if ($ordered === [] || self::isBlank($ordered) || self::isTotalRow($ordered)) {
                    continue;
                }

                // A section banner, not a line: read the section off it, then
                // carry it onto the lines below.
                if (self::isBannerRow($book, $name, $row, $ordered, $gray)) {
                    $section = self::sectionFromRow($ordered);

                    if ($section !== null) {
                        $currentSection = $section;
                    }

                    continue;
                }

                $assoc = [];

                foreach ($fields as $column => $field) {
                    if ($field !== null && array_key_exists($column, $ordered)) {
                        $assoc[$field] = $ordered[$column];
                    }
                }

                /*
                 * Objective and Project/Activity are merged over the resource
                 * lines that share them in the SH's own sheet, so the words sit
                 * in the first cell of the region and every line under it has
                 * none of its own. Such a continuation row is the same line as
                 * the one above, not an empty one: carry the words down, or the
                 * row would be rejected for a missing Activity Description.
                 */
                $objective = trim((string) ($assoc['objective'] ?? ''));
                $activity = trim((string) ($assoc['activity_description'] ?? ''));

                if ($objective !== '' || $activity !== '') {
                    $carriedObjective = $objective;
                    $carriedActivity = $activity;
                } elseif (self::carriesResourceData($assoc)) {
                    $assoc['objective'] = $carriedObjective;
                    $assoc['activity_description'] = $carriedActivity;
                }

                // Nothing but the sheet's own arithmetic (a Total column
                // evaluating to 0) is not a line to report as broken.
                if (
                    trim((string) ($assoc['objective'] ?? '')) === ''
                    && trim((string) ($assoc['activity_description'] ?? '')) === ''
                    && ! self::carriesResourceData($assoc)
                ) {
                    continue;
                }

                self::deriveUnitCost($assoc);

                if (trim((string) ($assoc['section'] ?? '')) === '') {
                    $assoc['section'] = $currentSection;
                }

                $code = self::normalizeCode((string) ($assoc['account_code'] ?? ''), $codeWidth);

                if ($code['value'] !== null) {
                    $assoc['account_code'] = $code['value'];
                }

                if ($code['warning'] !== null) {
                    $warnings[] = "{$name} row {$row}: ".$code['warning'];
                }

                $raw[] = ['line' => $row, 'data' => $assoc];
            }

            $analysis = MemImporter::analyzeRows($raw, null);

            $monthTotal = 0.0;

            foreach ($analysis['valid'] as $stored) {
                $monthTotal += round((float) ($stored['quantity'] ?? 0) * (float) ($stored['unit_cost'] ?? 0), 2);
            }

            $months[$month] = [
                'month' => $month,
                'name' => $name,
                'rows' => $analysis['rows'],
                'validCount' => $analysis['validCount'],
                'invalidCount' => $analysis['invalidCount'],
                'total' => round($monthTotal, 2),
            ];
            $valid[$month] = $analysis['valid'];
            $validCount += $analysis['validCount'];
            $invalidCount += $analysis['invalidCount'];
        }

        ksort($months);
        ksort($valid);

        if ($months === []) {
            $warnings[] = 'No monthly sheets (JAN…DEC) were found in the workbook.';
        }

        return [
            'months' => $months,
            'valid' => $valid,
            'validCount' => $validCount,
            'invalidCount' => $invalidCount,
            'warnings' => $warnings,
        ];
    }

    /**
     * Replace each present month's lines with the workbook's. The twelve
     * matrices are made to exist first (MemYearInitializer), then the months
     * the workbook carries have their lines deleted and re-inserted inside one
     * transaction, and each touched matrix's version is bumped.
     *
     * A month with no sheet is left exactly as it was.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $valid  month => stored rows
     * @return array{imported: int, months: int}
     */
    public static function commit(array $valid, int $schoolId, int $year, ?User $user = null): array
    {
        $matrices = (new MemYearInitializer)
            ->initialize($schoolId, $year, $user?->id)
            ->keyBy('month');

        $imported = 0;
        $touched = 0;

        DB::transaction(function () use ($valid, $matrices, $user, &$imported, &$touched): void {
            foreach ($valid as $month => $rows) {
                $matrix = $matrices->get((int) $month);

                if ($matrix === null) {
                    continue;
                }

                // Whole-month replace: the workbook is the source of truth for
                // a month it carries.
                $matrix->items()->delete();

                $position = 0;

                foreach ($rows as $row) {
                    $position++;

                    $item = new MonthlyExpenditureItem([
                        'matrix_id' => $matrix->id,
                        'position' => $position,
                        ...array_intersect_key($row, array_flip(MemImporter::DATA_FIELDS)),
                    ]);
                    $item->syncTotal();
                    $item->save();

                    $imported++;
                }

                $matrix->updated_by = $user?->id;
                $matrix->save();
                $matrix->bumpVersion();

                $touched++;
            }
        });

        return ['imported' => $imported, 'months' => $touched];
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * The month a worksheet name stands for: its label in config/wfp.php's
     * `matrix.months` (JAN..DEC), or a tolerant full-name match ("January").
     * Null for a sheet that is not a month.
     */
    public static function monthForSheet(string $name): ?int
    {
        $needle = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

        if ($needle === '') {
            return null;
        }

        foreach ((array) config('wfp.matrix.months', []) as $number => $label) {
            $label = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $label) ?? '');

            if ($label !== '' && $needle === $label) {
                return (int) $number;
            }
        }

        return MemImporter::parseMonth($name);
    }

    /**
     * The sheet's header row and the column-index → field map it yields, or
     * null when none of the top rows looks like a header.
     *
     * The header is read as a BLOCK, not a single row: the SH's own workbook
     * spreads it over two — the parent labels ("Objectives",
     * "Project/Activity/Expenditure", "Resources Needed", "Account Title",
     * "Account Code") on one row and the "Resources Needed" sub-labels
     * ("Kind", "Quantity", "Unit", "Unit Cost") on the row beneath. Stopping
     * at the first row that merely looks like a header would therefore latch
     * onto the sub-label row alone, leave Objectives / Project-Activity /
     * Account Title / Account Code unmapped, and reject every line for a
     * missing Activity Description.
     *
     * The anchor is the first row strong enough to prove a header (see
     * {@see MemImporter::detectHeader()}); the block is then grown over the
     * rows directly above and below it that carry a recognised label, and a
     * column keeps the first field recognised for it, top to bottom. The
     * returned row is the block's BOTTOM, so the data band starts after every
     * header row.
     *
     * @return array{0: int, 1: array<int, string|null>}|null
     */
    private static function headerRow(WfpSpreadsheet $book, string $sheet): ?array
    {
        $scan = (int) config('mem.workbook.header_scan_rows', 15);
        $byRow = [];

        foreach ($book->cellsFor($sheet) as $ref => $value) {
            if (preg_match('/^([A-Z]+)(\d+)$/', $ref, $m) !== 1) {
                continue;
            }

            $row = (int) $m[2];

            if ($row > $scan) {
                continue;
            }

            $byRow[$row][self::columnIndex($m[1])] = $value;
        }

        ksort($byRow);

        $fieldsByRow = [];

        foreach ($byRow as $row => $ordered) {
            ksort($ordered);
            $fieldsByRow[(int) $row] = MemImporter::headerFields($ordered);
        }

        $anchor = null;

        foreach ($fieldsByRow as $row => $fields) {
            if (MemImporter::detectHeader($byRow[$row]) !== null) {
                $anchor = $row;

                break;
            }
        }

        if ($anchor === null) {
            return null;
        }

        $band = max(0, (int) config('mem.workbook.header_band_rows', 2));
        $top = $anchor;
        $bottom = $anchor;

        // Both neighbours are only absorbed while they still name a field: the
        // title block above and the data band below name none, so the block
        // stops at the real edge of the header.
        for ($i = 0; $i < $band; $i++) {
            $previous = $top - 1;

            if ($previous < 1 || ! self::namesAField($fieldsByRow[$previous] ?? [])) {
                break;
            }

            $top = $previous;
        }

        for ($i = 0; $i < $band; $i++) {
            $next = $bottom + 1;

            if (! self::namesAField($fieldsByRow[$next] ?? [])) {
                break;
            }

            $bottom = $next;
        }

        $fields = [];

        for ($row = $top; $row <= $bottom; $row++) {
            foreach ($fieldsByRow[$row] ?? [] as $index => $field) {
                if ($field !== null) {
                    $fields[$index] ??= $field;
                }
            }
        }

        ksort($fields);

        return [$bottom, $fields];
    }

    /**
     * Whether a row is a section banner rather than a line.
     *
     * The sheet's own convention is a gray band, and that is what is read
     * first. A real workbook may paint its bands with a THEME colour, though,
     * which no RGB can be read off without resolving the theme + tint (see
     * WfpSpreadsheet) — so a row that is nothing but a lone label naming a
     * section ("Operations-Related Expenditures") counts as a banner too. Both
     * shapes are the same thing to the School Head and neither can be a line:
     * a line always carries an activity.
     *
     * @param  array<int, string>  $ordered
     */
    private static function isBannerRow(WfpSpreadsheet $book, string $sheet, int $row, array $ordered, ?string $gray): bool
    {
        if ($book->isSectionRow($sheet, $row, $gray)) {
            return true;
        }

        $filled = 0;

        foreach ($ordered as $value) {
            if (trim((string) $value) !== '') {
                $filled++;
            }
        }

        return $filled === 1 && self::sectionFromRow($ordered) !== null;
    }

    /**
     * Fill in a line's Unit Cost from the sheet's own Total Estimated Cost.
     *
     * The total is the authoritative money on the sheet, but a line is stored
     * as Quantity × Unit Cost (recomputed by the model, never trusted from the
     * file). A School Head who writes the amount straight into the total column
     * — "1 pc of tarpaulin, 3,840" — leaves the unit cost blank, and without
     * this the line would import at zero and the month would stop adding up to
     * the sheet's own Subtotal. So the unit cost is derived back from the total
     * whenever it is the only one of the three that is missing.
     *
     * @param  array<string, mixed>  $assoc
     */
    private static function deriveUnitCost(array &$assoc): void
    {
        $total = self::number($assoc['calculated_total'] ?? null);

        if (trim((string) ($assoc['unit_cost'] ?? '')) !== '' || $total === null || $total <= 0.0) {
            return;
        }

        $quantity = self::number($assoc['quantity'] ?? null);

        if ($quantity === null || $quantity <= 0.0) {
            $assoc['quantity'] = '1';
            $quantity = 1.0;
        }

        // Four decimals, trailing zeros trimmed, so a clean division stays a
        // clean number and a repeating one still multiplies back to the total.
        $unitCost = number_format($total / $quantity, 4, '.', '');

        $assoc['unit_cost'] = rtrim(rtrim($unitCost, '0'), '.');
    }

    /** A cell as a number, or null when it is blank or not numeric. */
    private static function number(mixed $value): ?float
    {
        $value = trim((string) $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Whether a row carries the data columns of a line — enough, with the
     * Objective / Project-Activity carried down from the row above, to be one.
     *
     * @param  array<string, mixed>  $assoc
     */
    private static function carriesResourceData(array $assoc): bool
    {
        foreach (['resource_kind', 'quantity', 'unit', 'unit_cost', 'account_title', 'account_code'] as $field) {
            if (trim((string) ($assoc[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a row names at least one recognised header field.
     *
     * @param  array<int, string|null>  $fields
     */
    private static function namesAField(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * One row's cells, keyed by 1-based column index.
     *
     * @param  array<string, string>  $cells
     * @return array<int, string>
     */
    private static function rowCells(array $cells, int $row): array
    {
        $out = [];

        foreach ($cells as $ref => $value) {
            if (preg_match('/^([A-Z]+)(\d+)$/', $ref, $m) !== 1 || (int) $m[2] !== $row) {
                continue;
            }

            $out[self::columnIndex($m[1])] = $value;
        }

        ksort($out);

        return $out;
    }

    /**
     * The last row of the month's table.
     *
     * A month sheet carries more than its lines: beneath them sit the
     * signatories block, the month/year stamp and the per-account summary
     * list, whose rows are not lines the School Head entered. Reading them
     * produced a wall of "Activity Description is required" errors on rows
     * nobody typed. The table is therefore bounded by its own last coded line
     * — the per-line Account Code column is what identifies a line — and a
     * sheet that carries no code at all keeps its full extent, so a codeless
     * file is still read (and flagged by the validator).
     *
     * @param  array<string, string>  $cells
     * @param  array<int, string|null>  $fields
     */
    private static function tableEnd(array $cells, array $fields, int $headerRow, int $maxRow): int
    {
        $codeColumns = [];

        foreach ($fields as $index => $field) {
            if ($field === 'account_code') {
                $codeColumns[] = $index;
            }
        }

        if ($codeColumns === []) {
            return $maxRow;
        }

        $last = null;

        foreach ($cells as $ref => $value) {
            if (preg_match('/^([A-Z]+)(\d+)$/', (string) $ref, $m) !== 1 || trim((string) $value) === '') {
                continue;
            }

            $row = (int) $m[2];

            if ($row <= $headerRow || ! in_array(self::columnIndex($m[1]), $codeColumns, true)) {
                continue;
            }

            $last = $last === null ? $row : max($last, $row);
        }

        return $last ?? $maxRow;
    }

    /** @param array<string, string> $cells */
    private static function maxRow(array $cells): int
    {
        $max = 0;

        foreach (array_keys($cells) as $ref) {
            if (preg_match('/^[A-Z]+(\d+)$/', (string) $ref, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max;
    }

    /** @param array<int, string> $cells */
    private static function isBlank(array $cells): bool
    {
        foreach ($cells as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** A sub-total/TOTAL row (its first word is "total"/"subtotal"), not a line. */
    private static function isTotalRow(array $cells): bool
    {
        foreach ($cells as $value) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');

            if (str_starts_with($normalized, 'total') || str_starts_with($normalized, 'subtotal')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The section a banner row names, matched tolerantly against
     * config/mem.php's `sections` labels ("Operations-Related Expenses" →
     * "operations").
     *
     * @param  array<int, string>  $cells
     */
    private static function sectionFromRow(array $cells): ?string
    {
        foreach ($cells as $value) {
            $normalized = preg_replace('/[^a-z]+/', '', strtolower($value)) ?? '';

            if ($normalized === '') {
                continue;
            }

            foreach ((array) config('mem.sections', []) as $key => $label) {
                $label = preg_replace('/[^a-z]+/', '', strtolower((string) $label)) ?? '';

                if ($label !== '' && str_contains($normalized, $label)) {
                    return (string) $key;
                }
            }
        }

        return null;
    }

    /**
     * A UACS account code as a fixed-width digit string: strip non-digits and
     * left-pad to the configured width. A code that is not the full width is
     * flagged (a 9-digit 502039900 is a source quirk the SH should see).
     *
     * @return array{value: ?string, warning: ?string}
     */
    private static function normalizeCode(string $value, int $width): array
    {
        $value = trim($value);

        if ($value === '') {
            return ['value' => null, 'warning' => null];
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        if ($digits === '') {
            return ['value' => null, 'warning' => null];
        }

        if (strlen($digits) < $width) {
            return [
                'value' => str_pad($digits, $width, '0', STR_PAD_LEFT),
                'warning' => "Account code “{$value}” has ".strlen($digits)." digit(s); padded to {$width}.",
            ];
        }

        if (strlen($digits) > $width) {
            return [
                'value' => $digits,
                'warning' => "Account code “{$value}” has more than {$width} digits.",
            ];
        }

        return ['value' => $digits, 'warning' => null];
    }

    private static function columnIndex(string $column): int
    {
        $index = 0;
        $length = strlen($column);

        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord(strtoupper($column[$i])) - 64);
        }

        return $index;
    }
}
