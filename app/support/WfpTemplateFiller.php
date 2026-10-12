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
     * Best-effort: a column that cannot be resolved is simply left out (and can
     * be pinned in config); only a workbook with no readable worksheet throws.
     *
     * @param  array<string, string>  $parts
     * @return array{sheet_path: string, sheet_name: string, header_row: ?int, columns: array<string, string>, months: array<int, string>, data_start_row: ?int, data_rows: array<int, int>, total_row: ?int}
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
        $columns = $headerRow === null ? [] : self::resolveColumns($byRow[$headerRow] ?? []);
        $months = $headerRow === null ? [] : self::resolveMonths($byRow[$headerRow] ?? []);

        $dataStart = $headerRow === null ? null : $headerRow + 1;
        [$dataRows, $totalRow] = $dataStart === null
            ? [[], null]
            : self::findDataBand($byRow, $dataStart);

        return [
            'sheet_path' => $sheetPath,
            'sheet_name' => $sheetName,
            'header_row' => $headerRow,
            'columns' => $columns,
            'months' => $months,
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

    /* ------------------------------------------------------------------
     * Writing
     * ----------------------------------------------------------------- */

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function writeRows(string $sheetXml, array $layout, array $rows): string
    {
        $columns = (array) $layout['columns'];
        $months = (array) $layout['months'];
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

            $writes = self::writesForRow((array) $row, $columns, $months);
            $sheetXml = self::rebuildRow($sheetXml, $targetRow, $writes, $styles);
        }

        // Blank out any template data rows the run did not fill.
        for ($index = $desired; $index < count($dataRows); $index++) {
            $writes = [];

            foreach (array_values($columns) as $col) {
                $writes[$col] = ['value' => null, 'numeric' => false];
            }

            foreach (array_values($months) as $col) {
                $writes[$col] = ['value' => null, 'numeric' => false];
            }

            $sheetXml = self::rebuildRow($sheetXml, $dataRows[$index], $writes, $styles);
        }

        return self::updateDimension($sheetXml);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $columns
     * @param  array<int, string>  $months
     * @return array<string, array{value: ?string, numeric: bool}>
     */
    private static function writesForRow(array $row, array $columns, array $months): array
    {
        $writes = [];

        foreach ($row as $field => $value) {
            if ($field === 'month') {
                continue;
            }

            $numeric = in_array($field, self::NUMERIC_FIELDS, true);

            // Month-shaped: a financial target belongs in its own month column.
            if ($field === 'financial_target' && $months !== []) {
                $month = (int) ($row['month'] ?? 0);

                if (isset($months[$month])) {
                    $writes[$months[$month]] = ['value' => self::stringify($value), 'numeric' => true];
                }

                continue;
            }

            if (isset($columns[$field])) {
                $writes[$columns[$field]] = ['value' => self::stringify($value), 'numeric' => $numeric];
            }
        }

        return $writes;
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

        if (preg_match_all('/<c\b[^>]*\/>|<c\b[^>]*>.*?<\/c>/s', $row, $found) !== false) {
            foreach ($found[0] as $element) {
                if (preg_match('/\br="([A-Z]+)\d+"/', $element, $ref) !== 1) {
                    continue;
                }

                $col = $ref[1];

                if (isset($writes[$col])) {
                    continue; // this column is being rewritten below
                }

                $cells[$col] = $element;
                $order[$col] = self::columnIndex($col);
            }
        }

        foreach ($writes as $col => $spec) {
            $style = null;

            if (preg_match('/<c\b(?=[^>]*\br="'.preg_quote($col.$rowNumber, '/').'")[^>]*>/', $row, $existing) === 1
                && preg_match('/\bs="(\d+)"/', $existing[0], $s) === 1) {
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
     * @param  array<string, string>  $headerCells
     * @return array<string, string>  field => column letter
     */
    private static function matchLabels(array $headerCells): array
    {
        $matched = [];

        foreach ((array) config('wfp.template.columns', []) as $field => $spec) {
            foreach ((array) ($spec['labels'] ?? []) as $label) {
                $needle = self::normalize((string) $label);

                if ($needle === '') {
                    continue;
                }

                foreach ($headerCells as $col => $text) {
                    if (self::normalize($text) === $needle) {
                        $matched[$field] ??= $col;

                        break 2;
                    }
                }
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, string>  $headerCells
     * @return array<int, string>  month number => column letter
     */
    private static function resolveMonths(array $headerCells): array
    {
        if (! (bool) config('wfp.template.months.enabled', false)) {
            return [];
        }

        $labels = (array) config('wfp.template.months.labels', []);
        $months = [];

        foreach ($headerCells as $col => $text) {
            $normalized = self::normalize($text);

            foreach ($labels as $number => $spellings) {
                foreach ((array) $spellings as $spelling) {
                    if ($normalized !== '' && $normalized === self::normalize((string) $spelling)) {
                        $months[(int) $number] = $col;

                        break 3;
                    }
                }
            }
        }

        return $months;
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
     * @return array{0: ?string, 1: string}  sheet path and name
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
     * @return array<string, string>  sheet name => package path
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

    private static function rowPattern(int $rowNumber): string
    {
        return '/<row\b(?=[^>]*\br="'.$rowNumber.'")[^>]*(?:\/>|>.*?<\/row>)/s';
    }

    private const ROW_PATTERN = '/<row\b[^>]*\br="(\d+)"[^>]*(?:\/>|>.*?<\/row>)/s';

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
