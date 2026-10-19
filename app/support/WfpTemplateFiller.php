<?php

namespace App\Support;

use RuntimeException;

/**
 * Produces a generated WFP by PATCHING the official WFP-Template.xlsx rather
 * than building a standalone workbook.
 *
 * The template's headings, merged ranges, styles, column widths, print area and
 * TOTAL formulas are all preserved: only the blank styled data rows are
 * overwritten in place with the generated values. When the generated rows
 * exceed the template's blank band, the extra rows are cloned (the total row
 * and everything below are shifted down, and a TOTAL `SUM(...)` range that
 * ended on the last data row is extended to cover the new ones, best-effort).
 *
 * The whole package is rebuilt with PurePhpZipWriter, so a PHP build without
 * the zip extension still produces a valid workbook — exactly as the OPCRF
 * personalizer does.
 *
 * Where each column lives is DISCOVERED from the file (see locate()), never
 * guessed. When a column cannot be resolved automatically it can be pinned in
 * config/wfp.php ('column' => 'C'), which `wfp:inspect-template` reports.
 */
class WfpTemplateFiller
{
    /** Fields written as numeric cells; everything else is an inline string. */
    private const NUMERIC_FIELDS = ['physical_target', 'financial_target'];

    /**
     * Every package part of a workbook, by name. Public so callers can locate()
     * once and then fill() (which re-reads its own copies).
     *
     * @return array<string, string>
     */
    public static function readParts(string $workbookBytes): array
    {
        $staged = tempnam(sys_get_temp_dir(), 'wfp-');

        if ($staged === false || file_put_contents($staged, $workbookBytes) === false) {
            throw new RuntimeException('The WFP template could not be read.');
        }

        try {
            if (class_exists(\ZipArchive::class)) {
                $zip = new \ZipArchive;

                if ($zip->open($staged) !== true) {
                    throw new RuntimeException('The WFP template could not be opened.');
                }

                try {
                    $parts = [];

                    for ($index = 0; $index < $zip->numFiles; $index++) {
                        $name = (string) $zip->getNameIndex($index);

                        if ($name !== '') {
                            $parts[$name] = (string) $zip->getFromIndex($index);
                        }
                    }

                    return $parts;
                } finally {
                    $zip->close();
                }
            }

            return (new PurePhpZipReader($staged))->readAll();
        } finally {
            @unlink($staged);
        }
    }

    /**
     * Locate the template's table: which sheet it is on, its header row, the
     * resolved column letters, its blank data band and its TOTAL row.
     *
     * The official template spreads its header across a merged BLOCK of rows
     * (parent headings such as "Financial Target (2025)", then per-month and
     * Object Code labels, then the physical month labels), so columns and
     * month bands are resolved from the whole block, not one row.
     *
     * Best-effort: a column that cannot be resolved is simply left out (and can
     * be pinned in config); only a workbook with no readable worksheet throws.
     *
     * @param  array<string, string>  $parts
     * @return array{sheet_path: string, sheet_name: string, header_row: ?int, columns: array<string, string>, months: array<int, string>, physical_months: array<int, string>, code_columns_by_month: array<int, string>, code_columns: array<string>, data_start_row: ?int, data_rows: array<int, int>, total_row: ?int}
     */
    public static function locate(array $parts): array
    {
        [$sheetPath, $sheetName] = self::resolveSheet($parts, (string) config('wfp.template.sheet', 'WFP'));

        if ($sheetPath === null) {
            throw new RuntimeException('The WFP template has no readable worksheet.');
        }

        $sheetXml = $parts[$sheetPath];
        $shared = self::sharedStrings($parts);
        [$byRow] = self::gridByRow($sheetXml, $shared);

        $headerRow = self::findHeaderRow($byRow);

        if ($headerRow === null) {
            return [
                'sheet_path' => $sheetPath,
                'sheet_name' => $sheetName,
                'header_row' => null,
                'columns' => [],
                'months' => [],
                'physical_months' => [],
                'code_columns_by_month' => [],
                'code_columns' => [],
                'data_start_row' => null,
                'data_rows' => [],
                'total_row' => null,
            ];
        }

        $band = self::headerBandRows($byRow, $headerRow);
        $columns = self::resolveColumns(self::bandMap($band));
        [$months, $physicalMonths] = self::resolveMonths($band, $columns);
        [$codeByMonth, $codeColumns] = self::resolveCodeColumns($band, $columns, $months);

        $dataStart = self::dataStartRow($byRow, $sheetXml, $headerRow, $band);
        [$dataRows, $totalRow] = self::findDataBand($byRow, $dataStart);

        return [
            'sheet_path' => $sheetPath,
            'sheet_name' => $sheetName,
            'header_row' => $headerRow,
            'columns' => $columns,
            'months' => $months,
            'physical_months' => $physicalMonths,
            'code_columns_by_month' => $codeByMonth,
            'code_columns' => $codeColumns,
            'data_start_row' => $dataStart,
            'data_rows' => $dataRows,
            'total_row' => $totalRow,
        ];
    }

    /**
     * Write the given rows into the template and return the patched workbook
     * bytes. Each row is an associative array keyed by WFP field, plus an
     * optional 'month' for a month-grained template.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $layout  as returned by locate()
     *
     * @throws RuntimeException when the table's header or essential columns
     *                          cannot be located
     */
    public static function fill(string $templateBytes, array $rows, array $layout): string
    {
        if (($layout['header_row'] ?? null) === null) {
            throw new RuntimeException('The WFP template could not be read — no header row was found.');
        }

        $columns = (array) ($layout['columns'] ?? []);
        $months = (array) ($layout['months'] ?? []);

        if (! isset($columns['program_activity']) || ($months === [] && ! isset($columns['financial_target']))) {
            throw new RuntimeException('The WFP template could not be read — its table columns were not recognised.');
        }

        $parts = self::readParts($templateBytes);
        $sheetPath = $layout['sheet_path'];
        $parts[$sheetPath] = self::writeRows($parts[$sheetPath], $layout, $rows);

        return PurePhpZipWriter::create($parts);
    }

    /**
     * Stamp single cells into the template (a title-block field such as the
     * school's name), keeping each cell's own style. A cell the sheet does not
     * already have is created in its row, in column order.
     *
     * @param  array<string, string>  $cells  cell reference => text
     */
    public static function writeCells(string $templateBytes, array $cells): string
    {
        if ($cells === []) {
            return $templateBytes;
        }

        $parts = self::readParts($templateBytes);
        [$sheetPath] = self::resolveSheet($parts, (string) config('wfp.template.sheet', 'WFP'));

        if ($sheetPath === null) {
            throw new RuntimeException('The WFP template has no readable worksheet.');
        }

        $sheetXml = $parts[$sheetPath];

        foreach ($cells as $ref => $text) {
            if (preg_match('/^([A-Z]+)(\d+)$/i', (string) $ref, $m) !== 1) {
                continue;
            }

            $sheetXml = self::rebuildRow(
                $sheetXml,
                (int) $m[2],
                [strtoupper($m[1]) => ['value' => self::stringify($text), 'numeric' => false]],
                [],
            );
        }

        $parts[$sheetPath] = $sheetXml;

        return PurePhpZipWriter::create($parts);
    }

    /* ------------------------------------------------------------------
     * Writing
     * ----------------------------------------------------------------- */

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function writeRows(string $sheetXml, array $layout, array $rows): string
    {
        $dataRows = array_values(array_map('intval', (array) $layout['data_rows']));

        $capacity = count($dataRows);
        $desired = count($rows);
        $lastDataRow = $dataRows === [] ? (int) $layout['header_row'] : max($dataRows);
        $totalRow = $layout['total_row'] !== null ? (int) $layout['total_row'] : null;

        $delta = max(0, $desired - $capacity);

        if ($delta > 0) {
            $sheetXml = self::shiftRowsBelow($sheetXml, $lastDataRow, $delta);

            if ($totalRow !== null) {
                $sheetXml = self::extendTotalFormula($sheetXml, $totalRow + $delta, $lastDataRow, $delta);
            }

            $sheetXml = self::appendClonedRows($sheetXml, $lastDataRow, $delta);

            for ($i = 1; $i <= $delta; $i++) {
                $dataRows[] = $lastDataRow + $i;
            }
        }

        $styles = self::harvestStyles($sheetXml, $dataRows);

        foreach ($rows as $index => $row) {
            $targetRow = $dataRows[$index] ?? null;

            if ($targetRow === null) {
                continue;
            }

            $writes = self::writesForRow((array) $row, $layout);
            $sheetXml = self::rebuildRow($sheetXml, $targetRow, $writes, $styles);
        }

        // Blank out any template data rows the run did not fill.
        for ($index = $desired; $index < count($dataRows); $index++) {
            $writes = [];

            foreach (self::blankColumns($layout) as $col) {
                $writes[$col] = ['value' => null, 'numeric' => false];
            }

            $sheetXml = self::rebuildRow($sheetXml, $dataRows[$index], $writes, $styles);
        }

        return self::updateDimension($sheetXml);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $layout  as returned by locate()
     * @return array<string, array{value: ?string, numeric: bool}>
     */
    private static function writesForRow(array $row, array $layout): array
    {
        $columns = (array) $layout['columns'];
        $months = (array) $layout['months'];
        $physicalMonths = (array) ($layout['physical_months'] ?? []);
        $codeByMonth = (array) ($layout['code_columns_by_month'] ?? []);
        $codeColumns = (array) ($layout['code_columns'] ?? []);
        $hasMonth = array_key_exists('month', $row);
        $month = $hasMonth ? (int) $row['month'] : null;

        $writes = [];

        foreach ($row as $field => $value) {
            if ($field === 'month') {
                continue;
            }

            $numeric = in_array($field, self::NUMERIC_FIELDS, true);

            // Month-shaped targets belong in their own month's column, under
            // the band that field's parent heading spans.
            if ($field === 'financial_target' && $months !== []) {
                if ($hasMonth && isset($months[$month])) {
                    $writes[$months[$month]] = ['value' => self::stringify($value), 'numeric' => true];
                } elseif (isset($columns[$field])) {
                    $writes[$columns[$field]] = ['value' => self::stringify($value), 'numeric' => true];
                }

                continue;
            }

            if ($field === 'physical_target' && $physicalMonths !== []) {
                if ($hasMonth && isset($physicalMonths[$month])) {
                    $writes[$physicalMonths[$month]] = ['value' => self::stringify($value), 'numeric' => true];
                } elseif (isset($columns[$field])) {
                    $writes[$columns[$field]] = ['value' => self::stringify($value), 'numeric' => true];
                }

                continue;
            }

            /*
             * Object codes: a monthly template carries one Object Code column
             * per month. A month-grained line writes into its own month's
             * column; a month-less row (the account-code generator's pre-filled
             * form) writes the code into every monthly column at once.
             */
            if ($field === 'account_code' && ($codeByMonth !== [] || $codeColumns !== [])) {
                if ($hasMonth && isset($codeByMonth[$month])) {
                    $writes[$codeByMonth[$month]] = ['value' => self::stringify($value), 'numeric' => false];
                } elseif (! $hasMonth) {
                    foreach ($codeColumns as $col) {
                        $writes[$col] = ['value' => self::stringify($value), 'numeric' => false];
                    }
                } elseif (isset($columns[$field])) {
                    $writes[$columns[$field]] = ['value' => self::stringify($value), 'numeric' => false];
                }

                continue;
            }

            if (isset($columns[$field])) {
                $writes[$columns[$field]] = ['value' => self::stringify($value), 'numeric' => $numeric];
            }
        }

        return $writes;
    }

    /**
     * Every column the blank-out pass clears: the resolved columns plus all
     * month- and per-month-code columns.
     *
     * @param  array<string, mixed>  $layout
     * @return array<int, string>
     */
    private static function blankColumns(array $layout): array
    {
        $cols = [];

        foreach (['columns', 'months', 'physical_months', 'code_columns'] as $key) {
            foreach (array_values((array) ($layout[$key] ?? [])) as $col) {
                $cols[(string) $col] = (string) $col;
            }
        }

        return array_values($cols);
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * Rebuild one row element from a set of column writes, keeping every other
     * cell and each cell's own style.
     *
     * @param  array<string, array{value: ?string, numeric: bool}>  $writes  column letter => write
     * @param  array<string, string>  $styles  column letter => harvested style id
     */
    private static function rebuildRow(string $sheetXml, int $rowNumber, array $writes, array $styles): string
    {
        $pattern = self::rowPattern($rowNumber);

        if (preg_match($pattern, $sheetXml, $m) !== 1) {
            return $sheetXml;
        }

        $row = $m[0];

        if (preg_match('/^<row\b([^>]*?)(\/?)>/s', $row, $open) !== 1) {
            return $sheetXml;
        }

        $attrs = $open[1];

        $cells = [];
        $order = [];
        $existing = [];

        if (preg_match_all('/<c\b[^>]*\/>|<c\b[^>]*>.*?<\/c>/s', $row, $found) !== false) {
            foreach ($found[0] as $element) {
                if (preg_match('/\br="([A-Z]+)\d+"/', $element, $ref) !== 1) {
                    continue;
                }

                $col = $ref[1];
                $existing[$col] = $element;

                if (isset($writes[$col])) {
                    continue; // this column is being rewritten below
                }

                $cells[$col] = $element;
                $order[$col] = self::columnIndex($col);
            }
        }

        foreach ($writes as $col => $spec) {
            /*
             * A cell the template COMPUTES is not ours to overwrite: the
             * template's own totals (a grand-total row, the per-row sums in
             * the first data rows) must survive a fill, both when a value is
             * written and when a blank row is being cleared — rewriting one
             * would hard-code a number where the template had a formula.
             */
            if (isset($existing[$col]) && str_contains($existing[$col], '<f')) {
                $cells[$col] = $existing[$col];
                $order[$col] = self::columnIndex($col);

                continue;
            }

            $style = null;

            if (isset($existing[$col]) && preg_match('/\bs="(\d+)"/', $existing[$col], $s) === 1) {
                $style = $s[1];
            } elseif (isset($styles[$col])) {
                $style = $styles[$col];
            }

            $cells[$col] = self::cellElement($col, $rowNumber, $spec['value'], $spec['numeric'], $style);
            $order[$col] = self::columnIndex($col);
        }

        uksort($cells, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        $rebuilt = '<row'.$attrs.'>'.implode('', $cells).'</row>';

        // A callback (not a replacement string) so a value containing "$"
        // is never read as a backreference.
        return (string) preg_replace_callback($pattern, static fn (): string => $rebuilt, $sheetXml, 1);
    }

    private static function cellElement(string $col, int $rowNumber, ?string $value, bool $numeric, ?string $style): string
    {
        $ref = $col.$rowNumber;
        $s = $style !== null ? ' s="'.$style.'"' : '';

        if ($value === null || $value === '') {
            return '<c r="'.$ref.'"'.$s.'/>';
        }

        if ($numeric) {
            return '<c r="'.$ref.'"'.$s.'><v>'.self::formatNumber($value).'</v></c>';
        }

        return '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t xml:space="preserve">'
            .htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</t></is></c>';
    }

    private static function formatNumber(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * The style id for each column, taken from the first styled cell found in
     * the template's data band — so a cell we have to create still inherits the
     * column's border/font.
     *
     * @param  array<int, int>  $dataRows
     * @return array<string, string>
     */
    private static function harvestStyles(string $sheetXml, array $dataRows): array
    {
        $styles = [];

        foreach ($dataRows as $rowNumber) {
            if (preg_match(self::rowPattern($rowNumber), $sheetXml, $m) !== 1) {
                continue;
            }

            if (preg_match_all('/<c\b[^>]*\/>|<c\b[^>]*>.*?<\/c>/s', $m[0], $found) === false) {
                continue;
            }

            foreach ($found[0] as $element) {
                if (preg_match('/\br="([A-Z]+)\d+"/', $element, $ref) !== 1) {
                    continue;
                }

                if (preg_match('/\bs="(\d+)"/', $element, $s) === 1) {
                    $styles[$ref[1]] ??= $s[1];
                }
            }
        }

        return $styles;
    }

    /**
     * Shift every row number greater than $greaterThan down by $delta (and the
     * row's own cell references with it).
     */
    private static function shiftRowsBelow(string $sheetXml, int $greaterThan, int $delta): string
    {
        return (string) preg_replace_callback(
            self::ROW_PATTERN,
            static function (array $m) use ($greaterThan, $delta): string {
                $row = $m[0];

                if (preg_match('/\br="(\d+)"/', $row, $rn) !== 1) {
                    return $row;
                }

                $number = (int) $rn[1];

                if ($number <= $greaterThan) {
                    return $row;
                }

                $new = $number + $delta;

                $row = (string) preg_replace('/<row\b([^>]*?)\br="'.$number.'"/', '<row$1r="'.$new.'"', $row, 1);

                return (string) preg_replace_callback(
                    '/\br="([A-Z]+)'.$number.'"/',
                    static fn (array $c): string => 'r="'.$c[1].$new.'"',
                    $row
                );
            },
            $sheetXml
        );
    }

    /**
     * Extend a TOTAL row's `SUM(...)` ranges (best-effort) so they still cover
     * the data band after rows were inserted.
     */
    private static function extendTotalFormula(string $sheetXml, int $rowNumber, int $oldLastDataRow, int $delta): string
    {
        if (preg_match(self::rowPattern($rowNumber), $sheetXml, $m) !== 1) {
            return $sheetXml;
        }

        $newLast = $oldLastDataRow + $delta;
        $row = $m[0];

        $updated = (string) preg_replace_callback(
            '/<f\b[^>]*>.*?<\/f>/s',
            static fn (array $f): string => (string) preg_replace(
                '/(\$?[A-Z]+\$?)'.$oldLastDataRow.'(?!\d)/',
                '${1}'.$newLast,
                $f[0]
            ),
            $row
        );

        return (string) preg_replace_callback(self::rowPattern($rowNumber), static fn (): string => $updated, $sheetXml, 1);
    }

    /**
     * Clone the last template data row $delta times and insert the clones after
     * it, numbering them from $lastDataRow + 1.
     */
    private static function appendClonedRows(string $sheetXml, int $lastDataRow, int $delta): string
    {
        $pattern = self::rowPattern($lastDataRow);

        if (preg_match($pattern, $sheetXml, $m) !== 1) {
            return $sheetXml;
        }

        $source = $m[0];
        $clones = '';

        for ($i = 1; $i <= $delta; $i++) {
            $new = $lastDataRow + $i;
            $clone = (string) preg_replace('/<row\b([^>]*?)\br="'.$lastDataRow.'"/', '<row$1r="'.$new.'"', $source, 1);
            $clone = (string) preg_replace_callback(
                '/\br="([A-Z]+)'.$lastDataRow.'"/',
                static fn (array $c): string => 'r="'.$c[1].$new.'"',
                $clone
            );

            // A per-row formula the template carries (Q = SUM(E177:P177)) must
            // total the CLONE's own months, not the row it was copied from.
            $clone = (string) preg_replace_callback(
                '/<f\b[^>]*>.*?<\/f>/s',
                static fn (array $f): string => (string) preg_replace(
                    '/(\$?[A-Z]+\$?)'.$lastDataRow.'(?!\d)/',
                    '${1}'.$new,
                    $f[0]
                ),
                $clone
            );

            $clones .= $clone;
        }

        return (string) preg_replace_callback($pattern, static fn (array $mm): string => $mm[0].$clones, $sheetXml, 1);
    }

    /**
     * Rewrite the sheet's `<dimension>` to cover the sheet's actual used range.
     */
    private static function updateDimension(string $sheetXml): string
    {
        if (! str_contains($sheetXml, '<dimension')) {
            return $sheetXml;
        }

        $maxRow = 0;
        $maxCol = 0;

        if (preg_match_all(self::ROW_PATTERN, $sheetXml, $rows) !== false) {
            foreach ($rows[0] as $row) {
                if (preg_match('/\br="(\d+)"/', $row, $rn) === 1) {
                    $maxRow = max($maxRow, (int) $rn[1]);
                }
            }
        }

        if (preg_match_all('/<c\b[^>]*\br="([A-Z]+)\d+"/', $sheetXml, $refs) !== false) {
            foreach ($refs[1] as $col) {
                $maxCol = max($maxCol, self::columnIndex($col));
            }
        }

        if ($maxRow === 0 || $maxCol === 0) {
            return $sheetXml;
        }

        $ref = 'A1:'.self::columnLetter($maxCol).$maxRow;

        return (string) preg_replace('/<dimension ref="[^"]*"\/>/', '<dimension ref="'.$ref.'"/>', $sheetXml, 1);
    }

    /* ------------------------------------------------------------------
     * Locating the table
     * ----------------------------------------------------------------- */

    /**
     * The header row: the first scanned row that matches at least two config
     * column labels.
     *
     * @param  array<int, array<string, string>>  $byRow
     */
    private static function findHeaderRow(array $byRow): ?int
    {
        $scanRows = (int) config('wfp.template.header_scan_rows', 30);
        $best = null;
        $bestScore = 0;

        for ($row = 1; $row <= $scanRows; $row++) {
            $cells = $byRow[$row] ?? [];

            if ($cells === []) {
                continue;
            }

            $score = count(self::matchLabels($cells));

            if ($score >= 2 && $score > $bestScore) {
                $best = $row;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Resolve each config column to a header cell's letter (or the pinned one).
     *
     * @param  array<string, string>  $headerCells  column letter => text
     * @return array<string, string>
     */
    private static function resolveColumns(array $headerCells): array
    {
        $matched = self::matchLabels($headerCells);
        $columns = [];

        foreach ((array) config('wfp.template.columns', []) as $field => $spec) {
            $pinned = $spec['column'] ?? null;

            if (is_string($pinned) && $pinned !== '') {
                $columns[$field] = strtoupper($pinned);

                continue;
            }

            if (isset($matched[$field])) {
                $columns[$field] = $matched[$field];
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, string>  $headerCells  column letter => text
     * @return array<string, string> field => column letter
     */
    private static function matchLabels(array $headerCells): array
    {
        $matched = [];
        $spellings = [];

        foreach ((array) config('wfp.template.columns', []) as $field => $spec) {
            foreach ((array) ($spec['labels'] ?? []) as $label) {
                if (self::normalize((string) $label) !== '') {
                    $spellings[$field][] = (string) $label;
                }
            }
        }

        // An exact spelling wins…
        foreach ($spellings as $field => $labels) {
            foreach ($labels as $label) {
                $needle = self::normalize($label);

                foreach ($headerCells as $col => $text) {
                    if (self::normalize($text) === $needle) {
                        $matched[$field] ??= $col;

                        break 2;
                    }
                }
            }
        }

        // …then a heading that starts with a known label and continues with a
        // qualifier ("Financial Target (2025)", "Physical Target (2024)"). The
        // continuation must begin at a word boundary so a longer word ("unit"
        // in "united") never matches.
        foreach ($spellings as $field => $labels) {
            if (isset($matched[$field])) {
                continue;
            }

            foreach ($labels as $label) {
                foreach ($headerCells as $col => $text) {
                    if (self::startsWithLabel($text, $label)) {
                        $matched[$field] ??= $col;

                        break 2;
                    }
                }
            }
        }

        return $matched;
    }

    /** Whether a cell's text starts with the given label at a word boundary. */
    private static function startsWithLabel(string $text, string $label): bool
    {
        $normalize = static fn (string $value): string => trim(strtolower(preg_replace('/\s+/', ' ', $value) ?? ''));

        $cell = $normalize($text);
        $needle = $normalize($label);

        if ($needle === '' || $cell === $needle || ! str_starts_with($cell, $needle)) {
            return false;
        }

        $rest = substr($cell, strlen($needle));

        return $rest !== '' && ! ctype_alnum($rest[0]);
    }

    /** Whether a cell's text exactly (normalized) matches one of a field's labels. */
    private static function matchesField(string $text, string $field): bool
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return false;
        }

        foreach ((array) config('wfp.template.columns.'.$field.'.labels', []) as $label) {
            if ($normalized === self::normalize((string) $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The header block: the header row plus the sub-header rows under it (the
     * official template spreads month labels and per-month Object Code columns
     * across a merged block).
     *
     * @param  array<int, array<string, string>>  $byRow
     * @return array<int, array<string, string>> row => [col => text]
     */
    private static function headerBandRows(array $byRow, int $headerRow): array
    {
        $extra = max(0, (int) config('wfp.template.header_band_rows', 2));
        $band = [];

        for ($row = $headerRow; $row <= $headerRow + $extra; $row++) {
            if (isset($byRow[$row])) {
                $band[$row] = $byRow[$row];
            }
        }

        return $band;
    }

    /**
     * The header block as one column => text map: the topmost non-empty cell
     * per column, so a parent heading ("Financial Target (2025)") wins over
     * the month label beneath it, ordered left to right.
     *
     * @param  array<int, array<string, string>>  $bandRows
     * @return array<string, string>
     */
    private static function bandMap(array $bandRows): array
    {
        $map = [];

        foreach ($bandRows as $cells) {
            foreach ($cells as $col => $text) {
                if (trim($text) !== '' && ! isset($map[$col])) {
                    $map[$col] = $text;
                }
            }
        }

        uksort($map, static fn (string $a, string $b): int => self::columnIndex($a) <=> self::columnIndex($b));

        return $map;
    }

    /**
     * Month columns from the header block, split by the band they sit under:
     * the financial months at/right of the Financial Target parent, the
     * physical months under the Physical Target parent (left of the financial
     * band). Both maps are month number => column letter.
     *
     * @param  array<int, array<string, string>>  $bandRows
     * @param  array<string, string>  $columns
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function resolveMonths(array $bandRows, array $columns): array
    {
        if (! (bool) config('wfp.template.months.enabled', false)) {
            return [[], []];
        }

        $labels = (array) config('wfp.template.months.labels', []);
        $candidates = []; // month number => [column letter => column index]

        foreach ($bandRows as $cells) {
            foreach ($cells as $col => $text) {
                $normalized = self::normalize($text);

                if ($normalized === '') {
                    continue;
                }

                foreach ($labels as $number => $spellings) {
                    foreach ((array) $spellings as $spelling) {
                        if ($normalized === self::normalize((string) $spelling)) {
                            $candidates[(int) $number][$col] = self::columnIndex($col);

                            // Two levels out: this cell is done, the next
                            // cell of the same row still gets its turn.
                            break 2;
                        }
                    }
                }
            }
        }

        $financialFrom = isset($columns['financial_target']) ? self::columnIndex($columns['financial_target']) : null;
        $physicalFrom = isset($columns['physical_target']) ? self::columnIndex($columns['physical_target']) : null;

        $months = [];
        $physical = [];

        foreach ($candidates as $number => $byColumn) {
            asort($byColumn);

            foreach ($byColumn as $col => $index) {
                // The financial band: at/right of its parent heading — or the
                // leftmost candidate when no parent resolved.
                if (! isset($months[$number]) && ($financialFrom === null || $index >= $financialFrom)) {
                    $months[$number] = $col;
                }

                // The physical band: under its parent, before the financial one.
                if (! isset($physical[$number])
                    && $physicalFrom !== null
                    && $index >= $physicalFrom
                    && ($financialFrom === null || $index < $financialFrom)) {
                    $physical[$number] = $col;
                }

                if (isset($months[$number]) && (isset($physical[$number]) || $physicalFrom === null)) {
                    break;
                }
            }
        }

        ksort($months);
        ksort($physical);

        return [$months, $physical];
    }

    /**
     * The per-month Object Code columns — each sitting immediately right of its
     * month column — plus every Object Code column in the header block.
     *
     * @param  array<int, array<string, string>>  $bandRows
     * @param  array<string, string>  $columns
     * @param  array<int, string>  $months  financial month => column letter
     * @return array{0: array<int, string>, 1: array<string>}
     */
    private static function resolveCodeColumns(array $bandRows, array $columns, array $months): array
    {
        $all = [];

        foreach ($bandRows as $cells) {
            foreach ($cells as $col => $text) {
                if (self::matchesField($text, 'account_code') && ! isset($all[$col])) {
                    $all[$col] = $col;
                }
            }
        }

        $byMonth = [];

        foreach ($months as $number => $monthCol) {
            $next = self::columnLetter(self::columnIndex($monthCol) + 1);

            if (isset($all[$next])) {
                $byMonth[(int) $number] = $next;
            }
        }

        // A template that resolves a single Object Code column but has none in
        // the month band still writes it.
        if ($all === [] && isset($columns['account_code'])) {
            $all[$columns['account_code']] = $columns['account_code'];
        }

        return [$byMonth, array_values($all)];
    }

    /**
     * The first data row: the header block is where sub-header rows (month
     * labels, per-month Object Code columns) and parent headings merged across
     * rows live — the blank band begins after all of them.
     *
     * @param  array<int, array<string, string>>  $byRow
     * @param  array<int, array<string, string>>  $bandRows
     */
    private static function dataStartRow(array $byRow, string $sheetXml, int $headerRow, array $bandRows): int
    {
        $bandEnd = $bandRows === [] ? $headerRow : max(array_keys($bandRows));
        $row = $headerRow + 1;

        // Sub-header rows are skipped where they may live: inside the band.
        while ($row <= $bandEnd && self::isSubHeaderRow($byRow[$row] ?? [])) {
            $row++;
        }

        // A parent heading merged across rows covers the rows beneath it, so
        // the band starts after the merge — not inside the heading.
        $blockBottom = max($headerRow, self::headerBlockBottom($sheetXml, $headerRow, $bandEnd));

        return $row <= $blockBottom ? $blockBottom + 1 : $row;
    }

    /**
     * The last row of the header block: the band's bottom, extended by a
     * merged range that starts inside the block and spans rows — the official
     * template merges its parent headings across the header rows (A11:A13),
     * so writing data at row 13 would write into the header itself.
     *
     * A range reaching far below the block is a data-area merge (a cell
     * spanning many lines), not the header, and is left out.
     */
    private static function headerBlockBottom(string $sheetXml, int $headerRow, int $bandEnd): int
    {
        $limit = $bandEnd + 1;
        $bottom = $headerRow;

        if (preg_match_all('/<mergeCell ref="([A-Z]+)(\d+):[A-Z]+(\d+)"/', $sheetXml, $merges, PREG_SET_ORDER) === false) {
            return $bottom;
        }

        foreach ($merges as $merge) {
            $start = (int) $merge[2];
            $end = (int) $merge[3];

            if ($start < $headerRow || $start > $bandEnd || $end <= $headerRow || $end > $limit) {
                continue;
            }

            $bottom = max($bottom, $end);
        }

        return $bottom;
    }

    /**
     * Whether a row belongs to the header block rather than the data band: it
     * carries month labels or per-month Object Code / Account Title columns.
     *
     * @param  array<string, string>  $cells
     */
    private static function isSubHeaderRow(array $cells): bool
    {
        foreach ($cells as $text) {
            $normalized = self::normalize($text);

            if ($normalized === '') {
                continue;
            }

            foreach ((array) config('wfp.template.months.labels', []) as $spellings) {
                foreach ((array) $spellings as $spelling) {
                    if ($normalized === self::normalize((string) $spelling)) {
                        return true;
                    }
                }
            }

            if (self::matchesField($text, 'account_code') || self::matchesField($text, 'account_title')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The blank data band: the rows after the header up to the TOTAL row (or
     * the first fully absent row). Rows carrying styled-but-empty cells count,
     * because that is exactly what the template's blank band is.
     *
     * @param  array<int, array<string, string>>  $byRow
     * @return array{0: array<int, int>, 1: ?int}
     */
    private static function findDataBand(array $byRow, int $dataStart): array
    {
        $dataRows = [];
        $totalRow = null;

        for ($row = $dataStart; $row <= $dataStart + 1000; $row++) {
            $cells = $byRow[$row] ?? null;

            if ($cells === null) {
                if ($dataRows === []) {
                    continue;
                }

                break;
            }

            if (self::isTotalRow($cells)) {
                $totalRow = $row;

                break;
            }

            $dataRows[] = $row;
        }

        return [$dataRows, $totalRow];
    }

    /**
     * @param  array<string, string>  $cells
     */
    private static function isTotalRow(array $cells): bool
    {
        foreach ($cells as $text) {
            if (str_starts_with(self::normalize($text), 'total')) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------
     * Workbook plumbing
     * ----------------------------------------------------------------- */

    /**
     * @param  array<string, string>  $parts
     * @return array{0: ?string, 1: string} sheet path and name
     */
    private static function resolveSheet(array $parts, string $keyword): array
    {
        $map = self::sheetMap($parts); // name => path

        if ($keyword !== '') {
            foreach ($map as $name => $path) {
                if (stripos($name, $keyword) !== false) {
                    return [$path, $name];
                }
            }
        }

        foreach ($map as $name => $path) {
            return [$path, $name];
        }

        // Fallback: any worksheet part, in file order.
        foreach (array_keys($parts) as $path) {
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $path) === 1) {
                return [$path, 'Sheet 1'];
            }
        }

        return [null, ''];
    }

    /**
     * @param  array<string, string>  $parts
     * @return array<string, string> sheet name => package path
     */
    private static function sheetMap(array $parts): array
    {
        $workbook = $parts['xl/workbook.xml'] ?? '';
        $rels = $parts['xl/_rels/workbook.xml.rels'] ?? '';
        $map = [];

        if ($workbook === '' || preg_match('/<sheets>(.*?)<\/sheets>/s', $workbook, $block) !== 1) {
            return $map;
        }

        $targets = [];
        preg_match_all('/<Relationship\b[^>]*>/', $rels, $relElements);

        foreach ($relElements[0] as $rel) {
            if (preg_match('/\bId="([^"]*)"/', $rel, $id) === 1 && preg_match('/\bTarget="([^"]*)"/', $rel, $target) === 1) {
                $targets[$id[1]] = $target[1];
            }
        }

        preg_match_all('/<sheet\b[^>]*\/?>/', $block[1], $sheetElements);

        foreach ($sheetElements[0] as $element) {
            if (preg_match('/\bname="([^"]*)"/', $element, $name) !== 1) {
                continue;
            }

            if (preg_match('/\br:id="([^"]*)"/', $element, $rid) !== 1 || ! isset($targets[$rid[1]])) {
                continue;
            }

            $target = $targets[$rid[1]];
            $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;

            if (isset($parts[$path])) {
                $map[html_entity_decode($name[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')] = $path;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $parts
     * @return array<int, string>
     */
    private static function sharedStrings(array $parts): array
    {
        $xml = $parts['xl/sharedStrings.xml'] ?? '';
        $document = $xml === '' ? false : @simplexml_load_string($xml);

        if ($document === false) {
            return [];
        }

        $strings = [];

        foreach ($document->si as $si) {
            $text = '';

            foreach ($si->t as $t) {
                $text .= (string) $t;
            }

            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * Every cell as row number => [column letter => text], plus the flat
     * ref => text map. Empty (styled) cells are included, which is what lets
     * the blank data band be found.
     *
     * @param  array<int, string>  $shared
     * @return array{0: array<int, array<string, string>>, 1: array<string, string>}
     */
    private static function gridByRow(string $sheetXml, array $shared): array
    {
        $byRow = [];
        $flat = [];

        if (preg_match_all('/<c\b[^>]*\/>|<c\b[^>]*>.*?<\/c>/s', $sheetXml, $matches) === false) {
            return [$byRow, $flat];
        }

        foreach ($matches[0] as $element) {
            if (preg_match('/\br="([A-Z]+)(\d+)"/', $element, $ref) !== 1) {
                continue;
            }

            $col = $ref[1];
            $row = (int) $ref[2];
            $value = self::extractCellText($element, $shared);

            $byRow[$row][$col] = $value;
            $flat[$col.$row] = $value;
        }

        return [$byRow, $flat];
    }

    /**
     * @param  array<int, string>  $shared
     */
    private static function extractCellText(string $element, array $shared): string
    {
        if (str_contains($element, 't="inlineStr"') && preg_match('/<is>.*?<t[^>]*>(.*?)<\/t>/s', $element, $t) === 1) {
            return html_entity_decode($t[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }

        if (preg_match('/<v>(.*?)<\/v>/s', $element, $v) === 1) {
            $raw = html_entity_decode($v[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');

            return str_contains($element, 't="s"') ? ($shared[(int) $raw] ?? '') : $raw;
        }

        return '';
    }

    /* ------------------------------------------------------------------
     * Small helpers
     * ----------------------------------------------------------------- */

    private static function normalize(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    /*
     * Every row pattern keeps the self-closing alternative FIRST and gives
     * each alternative its own `[^>]*`, because a single greedy `[^>]*`
     * followed by `(?:\/>|>.*?<\/row>)` matches too much: it swallows the
     * closing slash of an empty row (`<row r="5"/>`), the `\/>` branch then
     * fails, and the `>.*?<\/row>` branch runs on to the NEXT row's closing
     * tag — so rewriting row 5 would clobber row 6 as well.
     */
    private static function rowPattern(int $rowNumber): string
    {
        return '/<row\b(?=[^>]*\br="'.$rowNumber.'")(?:[^>]*\/>|[^>]*>.*?<\/row>)/s';
    }

    private const ROW_PATTERN = '/<row\b[^>]*\br="(\d+)"(?:[^>]*\/>|[^>]*>.*?<\/row>)/s';

    private static function columnIndex(string $column): int
    {
        $index = 0;

        foreach (str_split($column) as $letter) {
            $index = $index * 26 + (ord(strtoupper($letter)) - 64);
        }

        return $index;
    }

    private static function columnLetter(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $index--;
            $letters = chr(($index % 26) + 65).$letters;
            $index = intdiv($index, 26);
        }

        return $letters === '' ? 'A' : $letters;
    }
}
