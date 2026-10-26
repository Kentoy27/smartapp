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

        // A shared formula is positional: its dependents hold only an index
        // and are resolved against where the master sits. Row surgery moves
        // and deletes rows, so expand them into ordinary formulas first.
        $parts[$sheetPath] = self::expandSharedFormulas($parts[$sheetPath]);

        // Title rows paint the template's gray section colour; resolve it once
        // only when at least one row is an account-title section header.
        $titleStyle = null;

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['__account_title__'])) {
                $titleStyle = self::titleStyle($parts);

                break;
            }
        }

        [$sheetXml, $stylesXml] = self::writeRows(
            $parts[$sheetPath],
            $parts['xl/styles.xml'] ?? '',
            $layout,
            $rows,
            $titleStyle,
        );

        $parts[$sheetPath] = $sheetXml;

        // The template is saved scrolled deep into its blank band. After the
        // fill trims that band, that saved position shows empty rows below
        // the frozen header and hides the lines the form carries.
        $parts[$sheetPath] = self::focusSheetView(
            $parts[$sheetPath],
            (int) ($layout['data_start_row'] ?? (($layout['header_row'] ?? 1) + 1)),
        );

        if (isset($parts['xl/styles.xml'])) {
            $parts['xl/styles.xml'] = $stylesXml;
        }

        $parts['xl/workbook.xml'] = self::updatePrintArea(
            $parts['xl/workbook.xml'] ?? '',
            (string) ($layout['sheet_name'] ?? ''),
            $parts[$sheetPath],
        );

        self::dropCalcChain($parts);

        return PurePhpZipWriter::create($parts);
    }

    /**
     * Point a frozen sheet at its first data line when it opens.
     *
     * The template is saved scrolled deep into its blank band, with the
     * letterhead and table header frozen above it. A fill trims that band, so
     * the saved row no longer exists: Excel reopens the workbook showing the
     * frozen header above an empty grid, and the filled lines are above the
     * viewport until the reader scrolls up. Move the bottom pane's top-left
     * cell — and its selection, which the template leaves in the top pane — to
     * the first data line, so the form opens on the content it carries.
     */
    private static function focusSheetView(string $sheetXml, int $firstDataRow): string
    {
        if (preg_match('/<pane\b[^>]*\bySplit="(\d+)"[^>]*\/>/', $sheetXml, $pane) !== 1) {
            return $sheetXml;
        }

        // A frozen pane's scroll position must be below the frozen rows.
        $target = 'A'.max(1, $firstDataRow, (int) $pane[1] + 1);

        $sheetXml = (string) preg_replace_callback(
            '/<pane\b([^>]*\bySplit="\d+"[^>]*)\/>/',
            static function (array $m) use ($target): string {
                if (preg_match('/\btopLeftCell="[^"]*"/', $m[1]) === 1) {
                    return '<pane'.((string) preg_replace('/\btopLeftCell="[^"]*"/', 'topLeftCell="'.$target.'"', $m[1], 1)).'/>';
                }

                return '<pane'.$m[1].' topLeftCell="'.$target.'"/>';
            },
            $sheetXml,
            1
        );

        return (string) preg_replace_callback(
            '/<selection\b[^>]*\bpane="bottomLeft"[^>]*\/>/',
            static function (array $m) use ($target): string {
                $tag = $m[0];

                if (preg_match('/\bsqref="[^"]*"/', $tag) === 1) {
                    $tag = (string) preg_replace('/\bsqref="[^"]*"/', 'sqref="'.$target.'"', $tag, 1);
                } else {
                    $tag = (string) preg_replace('/\s*\/>$/', ' sqref="'.$target.'"/>', $tag, 1);
                }

                if (preg_match('/\bactiveCell="[^"]*"/', $tag) === 1) {
                    $tag = (string) preg_replace('/\bactiveCell="[^"]*"/', 'activeCell="'.$target.'"', $tag, 1);
                }

                return $tag;
            },
            $sheetXml
        );
    }

    /**
     * Drop the workbook's calcChain (xl/calcChain.xml) and its relationship
     * and content-type override. Row surgery (shifting and cloning rows)
     * rewrites cell references but leaves the formula index describing cells
     * that moved or no longer exist, so Excel treats the workbook as corrupt
     * and repairs away shared formulas. Dropping it lets Excel recalculate on
     * open instead — the same approach OpcrfWorkbookTrim uses.
     *
     * @param  array<string, string>  $parts
     */
    private static function dropCalcChain(array &$parts): void
    {
        $rels = $parts['xl/_rels/workbook.xml.rels'] ?? '';
        $part = null;
        $rid = null;

        if (preg_match('/<Relationship\s[^>]*\bType="[^"]*\/calcChain"[^>]*\bId="([^"]*)"/u', $rels, $m)
            || preg_match('/<Relationship\s[^>]*\bId="([^"]*)"[^>]*\bType="[^"]*\/calcChain"/u', $rels, $m)) {
            $rid = $m[1];
            $target = self::relationshipTarget($rels, $rid);
            if ($target !== null) {
                $part = self::resolve('xl/', $target);
            }
        }

        if ($part !== null && isset($parts[$part])) {
            unset($parts[$part]);
        }

        if ($rid !== null) {
            $parts['xl/_rels/workbook.xml.rels'] = (string) preg_replace(
                '/<Relationship\s[^>]*\bId="'.preg_quote($rid, '/').'"[^>]*\/>\s*/u',
                '',
                $rels
            ) ?? $rels;
        }

        if ($part !== null) {
            $contentTypes = $parts['[Content_Types].xml'] ?? '';
            $parts['[Content_Types].xml'] = (string) preg_replace(
                '#<Override\s[^>]*PartName="/'.preg_quote($part, '#').'"[^>]*/>#u',
                '',
                $contentTypes
            ) ?? $contentTypes;
        }

        // Without a calcChain, Excel only knows to rebuild the formulas when
        // the workbook asks for a full calculation. The cached values in the
        // template predate the rows this fill wrote, so they must be replaced.
        $parts['xl/workbook.xml'] = self::markForFullRecalculation($parts['xl/workbook.xml'] ?? '');
    }

    /**
     * Ask Excel to recalculate every formula when the workbook opens.
     */
    private static function markForFullRecalculation(string $workbookXml): string
    {
        if ($workbookXml === '') {
            return $workbookXml;
        }

        if (preg_match('/<calcPr\b/', $workbookXml) !== 1) {
            return str_replace('</workbook>', '<calcPr fullCalcOnLoad="1"/></workbook>', $workbookXml);
        }

        if (preg_match('/\bfullCalcOnLoad="[^"]*"/', $workbookXml) === 1) {
            return (string) preg_replace('/\bfullCalcOnLoad="[^"]*"/', 'fullCalcOnLoad="1"', $workbookXml, 1);
        }

        return (string) preg_replace('/<calcPr\b/', '<calcPr fullCalcOnLoad="1"', $workbookXml, 1);
    }

    /**
     * The Target a workbook-level relationship id points at, or null.
     */
    private static function relationshipTarget(string $rels, string $rid): ?string
    {
        if (preg_match('/<Relationship\s[^>]*\bId="'.preg_quote($rid, '/').'"[^>]*\bTarget="([^"]*)"/u', $rels, $m)
            || preg_match('/<Relationship\s[^>]*\bTarget="([^"]*)"[^>]*\bId="'.preg_quote($rid, '/').'"/u', $rels, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Resolve a relationship Target against the directory of the part that
     * carries it.
     */
    private static function resolve(string $baseDirectory, string $target): string
    {
        $segments = [];

        foreach (explode('/', $baseDirectory.$target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
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

    /**
     * The title-block cells that identify a generated form: the letterhead's
     * region and division lines, the school's name in its own cell, and the
     * planning year in the form's title and its two target headings. All are
     * pinned in config, so a template revision that moves them is a config
     * change rather than a code change.
     *
     * The year matters: the shipped template is headed for one year, and a
     * workbook generated for another must not keep the template's year.
     *
     * @return array<string, string> cell reference => text
     */
    public static function titleCells(?string $school, ?int $year): array
    {
        $cells = [];

        // The static letterhead first, so a misconfigured cell can never
        // overwrite the school name or the year stamped below.
        foreach ((array) config('wfp.template.letterhead_cells', []) as $ref => $text) {
            $ref = trim((string) $ref);
            $text = trim((string) $text);

            if ($ref !== '' && $text !== '') {
                $cells[$ref] = $text;
            }
        }

        $schoolCell = trim((string) config('wfp.template.school_name_cell', 'A6'));
        $school = trim((string) $school);

        if ($school !== '' && $schoolCell !== '') {
            $cells[$schoolCell] = $school;
        }

        if ($year === null) {
            return $cells;
        }

        foreach ((array) config('wfp.template.year_cells', []) as $ref => $pattern) {
            $ref = trim((string) $ref);

            if ($ref !== '' && is_string($pattern) && $pattern !== '') {
                $cells[$ref] = sprintf($pattern, $year);
            }
        }

        return $cells;
    }

    /* ------------------------------------------------------------------
     * Writing
     * ----------------------------------------------------------------- */

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: string, 1: string} patched sheet XML and styles XML
     */
    private static function writeRows(string $sheetXml, string $stylesXml, array $layout, array $rows, ?int $titleStyle = null): array
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
        } elseif ($desired < count($dataRows)) {
            /*
             * Fewer rows than the template's blank band: drop the unused tail
             * of the band and pull the TOTAL row and the signature block up,
             * so the workbook is as long as the plan rather than as long as
             * the form. One line is always kept, so an empty plan still opens
             * with a working line and a valid TOTAL range.
             */
            $keep = max(1, $desired);
            $sheetXml = self::trimDataRows($sheetXml, $dataRows, $keep);
            $dataRows = array_slice($dataRows, 0, $keep);
        }

        $styles = self::harvestStyles($sheetXml, $dataRows);

        // The template's blank band was cut from a completed workbook, so its
        // cells carry hundreds of one-off styles: bold print, gray/yellow
        // section fills and fixed heights. Generated lines get clean variants
        // (white, not bold, wrapped); only the account-title rows keep the
        // template's gray/bold section style.
        [$stylesXml, $cleanStyles] = self::cleanCellStyles($stylesXml, self::stylesUsed($sheetXml, $dataRows));

        $fitRows = [];
        $titleRows = [];

        foreach ($rows as $index => $row) {
            $targetRow = $dataRows[$index] ?? null;

            if ($targetRow === null) {
                continue;
            }

            // An account-title section row paints the whole band gray.
            $rowStyle = (isset($row['__account_title__']) && $titleStyle !== null) ? (string) $titleStyle : null;
            $styleMap = $rowStyle === null ? $cleanStyles : null;

            $writes = self::writesForRow((array) $row, $layout);
            $sheetXml = self::rebuildRow($sheetXml, $targetRow, $writes, $styles, $rowStyle, true, $styleMap);

            if ($styleMap !== null) {
                $fitRows[] = $targetRow;
            } else {
                $titleRows[] = $targetRow;
            }
        }

        // Blank out any template data rows the run did not fill.
        for ($index = $desired; $index < count($dataRows); $index++) {
            $writes = [];

            foreach (self::blankColumns($layout) as $col) {
                $writes[$col] = ['value' => null, 'numeric' => false];
            }

            $sheetXml = self::rebuildRow($sheetXml, $dataRows[$index], $writes, $styles, null, false, $cleanStyles);
            $fitRows[] = $dataRows[$index];
        }

        $sheetXml = self::fitRowHeights($sheetXml, $fitRows, $stylesXml);

        // Section rows are deliberately left unwrapped (only their colour and
        // weight are the template's), so they are one line even when they land
        // on a template row that shipped at 102 or 122 points.
        $sheetXml = self::fitRowHeights($sheetXml, $titleRows, $stylesXml, false);

        return [self::updateDimension($sheetXml), $stylesXml];
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
        $isTitle = isset($row['__account_title__']);

        $writes = [];

        foreach ($row as $field => $value) {
            if ($field === 'month' || $field === '__account_title__') {
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

            // A title row has no dedicated Account Title column on some
            // templates — fall back to the Objectives column so the title is
            // still visible rather than dropped.
            if ($isTitle && $field === 'account_title' && ! isset($columns['account_title']) && isset($columns['objective'])) {
                $writes[$columns['objective']] = ['value' => self::stringify($value), 'numeric' => false];
            }
        }

        // A title row paints the whole band gray: write every blank column as
        // an empty cell so each inherits the row's gray style. Columns already
        // written above (title, code) keep their values.
        if ($isTitle) {
            foreach (self::blankColumns($layout) as $col) {
                $writes[$col] ??= ['value' => null, 'numeric' => false];
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
     * cell and each cell's own style. When `$rowStyle` is given (a title
     * section's gray), it overrides every written cell's style so the whole
     * row paints gray. `$unhide` lifts the template's `hidden` flag: a row
     * that now carries data must be visible, and the shipped template hides
     * some of its blank lines.
     *
     * @param  array<string, array{value: ?string, numeric: bool}>  $writes  column letter => write
     * @param  array<string, string>  $styles  column letter => harvested style id
     * @param  string|null  $rowStyle  style id forced on every written cell
     * @param  array<string, string>|null  $styleMap  base style id => clean style id
     */
    private static function rebuildRow(string $sheetXml, int $rowNumber, array $writes, array $styles, ?string $rowStyle = null, bool $unhide = false, ?array $styleMap = null): string
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

        if ($unhide) {
            $attrs = (string) preg_replace('/\s+hidden="[^"]*"/', '', $attrs);
        }

        $cells = [];
        $order = [];
        $existing = [];

        if (preg_match_all('/<c\b[^>]*\/>|<c\b[^>]*>.*?<\/c>/s', $row, $found) !== false) {
            foreach ($found[0] as $element) {
                if (preg_match('/\br="([A-Z]+)\d+"/', $element, $ref) !== 1) {
                    continue;
                }

                $col = $ref[1];

                // A section row must be uniformly gray and bold, including
                // cells the filler does not write (formulas and the template's
                // suppressed totals); otherwise a yellow or blue cell is left
                // in the middle of the gray band.
                if ($rowStyle !== null) {
                    $element = self::withStyleId($element, $rowStyle);
                }

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

            $style = $rowStyle;

            if ($style === null) {
                if (isset($existing[$col]) && preg_match('/\bs="(\d+)"/', $existing[$col], $s) === 1) {
                    $style = $s[1];
                } elseif (isset($styles[$col])) {
                    $style = $styles[$col];
                }
            }

            $cells[$col] = self::cellElement($col, $rowNumber, $spec['value'], $spec['numeric'], $style);
            $order[$col] = self::columnIndex($col);
        }

        uksort($cells, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        $rebuilt = '<row'.$attrs.'>'.implode('', $cells).'</row>';

        if ($styleMap !== null) {
            $rebuilt = self::restyleRow($rebuilt, $styleMap);
        }

        // A callback (not a replacement string) so a value containing "$"
        // is never read as a backreference.
        return (string) preg_replace_callback($pattern, static fn (): string => $rebuilt, $sheetXml, 1);
    }

    /** Force one cell element to a style id, adding the attribute if needed. */
    private static function withStyleId(string $element, string $styleId): string
    {
        if (preg_match('/\bs="\d+"/', $element) === 1) {
            return (string) preg_replace('/\bs="\d+"/', 's="'.$styleId.'"', $element, 1);
        }

        return (string) preg_replace('/<c\b/', '<c s="'.$styleId.'"', $element, 1);
    }

    /**
     * Swap every cell's style id for its clean variant, including cells the
     * template computes (a formula may be kept while its bold print is not).
     *
     * @param  array<string, string>  $styleMap  base style id => clean style id
     */
    private static function restyleRow(string $rowXml, array $styleMap): string
    {
        return (string) preg_replace_callback(
            '/<c\b[^>]*>/',
            static function (array $m) use ($styleMap): string {
                if (preg_match('/\bs="(\d+)"/', $m[0], $s) === 1 && isset($styleMap[$s[1]])) {
                    return (string) preg_replace('/\bs="\d+"/', 's="'.$styleMap[$s[1]].'"', $m[0], 1);
                }

                return $m[0];
            },
            $rowXml
        );
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
     * Every style id used by the data band, so clean variants can be prepared
     * before any row is rewritten.
     *
     * @param  array<int, int>  $dataRows
     * @return array<int, string>
     */
    private static function stylesUsed(string $sheetXml, array $dataRows): array
    {
        $styles = [];

        foreach ($dataRows as $rowNumber) {
            if (preg_match(self::rowPattern($rowNumber), $sheetXml, $m) !== 1) {
                continue;
            }

            if (preg_match_all('/<c\b[^>]*>/', $m[0], $cells) === 0) {
                continue;
            }

            foreach ($cells[0] as $cell) {
                if (preg_match('/\bs="(\d+)"/', $cell, $s) === 1) {
                    $styles[$s[1]] = $s[1];
                }
            }
        }

        return array_values($styles);
    }

    /**
     * Build a clean style for every template style in the data band: the same
     * font family and size but not bold, the same border and number format,
     * no fill, and wrapped text. New fonts and cellXfs are appended to
     * styles.xml, so every existing style index stays valid.
     *
     * @param  array<int, string>  $styleIds
     * @return array{0: string, 1: array<string, string>} styles XML and base style => clean style
     */
    private static function cleanCellStyles(string $stylesXml, array $styleIds): array
    {
        if ($stylesXml === '' || $styleIds === []) {
            return [$stylesXml, []];
        }

        if (preg_match('/<fonts\b([^>]*)>(.*?)<\/fonts>/s', $stylesXml, $fontsBlock) !== 1
            || preg_match('/<cellXfs\b([^>]*)>(.*?)<\/cellXfs>/s', $stylesXml, $xfsBlock) !== 1) {
            return [$stylesXml, []];
        }

        if (preg_match_all('/<font\b[^>]*>.*?<\/font>|<font\b[^>]*\/>/s', $fontsBlock[2], $fonts) === 0
            || preg_match_all('/<xf\b[^>]*\/>|<xf\b[^>]*>.*?<\/xf>/s', $xfsBlock[2], $xfs) === 0) {
            return [$stylesXml, []];
        }

        $fontsByXml = [];

        foreach ($fonts[0] as $index => $font) {
            $fontsByXml[$font] ??= $index;
        }

        $nonBold = []; // original font id => non-bold font id
        $newFonts = [];

        foreach ($styleIds as $styleId) {
            $fontId = self::styleFontId($xfs[0][(int) $styleId] ?? '');

            if ($fontId === null || isset($nonBold[$fontId])) {
                continue;
            }

            $font = $fonts[0][$fontId] ?? '';

            if ($font === '' || ! self::fontIsBold($font)) {
                $nonBold[$fontId] = $fontId;

                continue;
            }

            $clean = (string) preg_replace('/<b\b[^>]*\/>|<b\b[^>]*>.*?<\/b>/s', '', $font);

            if (isset($fontsByXml[$clean])) {
                $nonBold[$fontId] = $fontsByXml[$clean];

                continue;
            }

            $newFonts[] = $clean;
            $newFontsIndex = count($fonts[0]) + count($newFonts) - 1;
            $nonBold[$fontId] = $newFontsIndex;
            $fontsByXml[$clean] = $newFontsIndex;
        }

        $xfsByXml = [];

        foreach ($xfs[0] as $index => $xf) {
            $xfsByXml[$xf] ??= $index;
        }

        $map = [];
        $newXfs = [];

        foreach ($styleIds as $styleId) {
            $styleId = (int) $styleId;
            $xf = $xfs[0][$styleId] ?? null;

            if ($xf === null) {
                continue;
            }

            $clean = self::cleanCellXf($xf, $nonBold);

            if (isset($xfsByXml[$clean])) {
                $map[(string) $styleId] = (string) $xfsByXml[$clean];

                continue;
            }

            $newXfs[] = $clean;
            $cleanIndex = count($xfs[0]) + count($newXfs) - 1;
            $map[(string) $styleId] = (string) $cleanIndex;
            $xfsByXml[$clean] = $cleanIndex;
        }

        return [self::appendStyleElements($stylesXml, $fontsBlock, $xfsBlock, $newFonts, $newXfs), $map];
    }

    /**
     * A cellXf with the same number format, border and alignment as the
     * template's, but a non-bold font, no fill, and wrapping switched on.
     *
     * @param  array<int, int>  $nonBold  original font id => non-bold font id
     */
    private static function cleanCellXf(string $xf, array $nonBold): string
    {
        $fontId = self::styleFontId($xf);

        if ($fontId !== null && isset($nonBold[$fontId])) {
            $xf = (string) preg_replace('/\bfontId="\d+"/', 'fontId="'.$nonBold[$fontId].'"', $xf, 1);
        }

        $xf = (string) preg_replace('/\bfillId="\d+"/', 'fillId="0"', $xf, 1);
        $xf = self::withXfAttribute($xf, 'applyFont', '1');
        $xf = self::withXfAttribute($xf, 'applyFill', '1');

        if (preg_match('/\bborderId="[1-9]\d*"/', $xf) === 1) {
            $xf = self::withXfAttribute($xf, 'applyBorder', '1');
        }

        if (preg_match('/\bnumFmtId="[1-9]\d*"/', $xf) === 1) {
            $xf = self::withXfAttribute($xf, 'applyNumberFormat', '1');
        }

        return self::withWrappedAlignment($xf);
    }

    private static function styleFontId(string $xf): ?int
    {
        return preg_match('/\bfontId="(\d+)"/', $xf, $m) === 1 ? (int) $m[1] : null;
    }

    private static function fontIsBold(string $font): bool
    {
        return preg_match('/<b\b(?![^>]*\bval="(?:0|false)")/i', $font) === 1;
    }

    /** Add or replace one attribute on an `<xf>` element's opening tag. */
    private static function withXfAttribute(string $xf, string $attribute, string $value): string
    {
        if (preg_match('/^<xf\b([^>]*?)(\/?)>/s', $xf, $m) !== 1) {
            return $xf;
        }

        $attrs = $m[1];
        $pattern = '/\b'.preg_quote($attribute, '/').'="[^"]*"/';

        if (preg_match($pattern, $attrs) === 1) {
            $attrs = (string) preg_replace($pattern, $attribute.'="'.$value.'"', $attrs, 1);
        } else {
            $attrs .= ' '.$attribute.'="'.$value.'"';
        }

        return '<xf'.$attrs.$m[2].'>'.substr($xf, strlen($m[0]));
    }

    /** Preserve an xf's alignment, but force wrapping on. */
    private static function withWrappedAlignment(string $xf): string
    {
        $alignment = '';

        if (preg_match('/<alignment\b([^>]*?)\/>/s', $xf, $m) === 1) {
            $alignment = $m[1];
        } elseif (preg_match('/<alignment\b([^>]*)>/s', $xf, $m) === 1) {
            $alignment = $m[1];
        }

        $alignment = trim((string) preg_replace('/\bwrapText="[^"]*"/', '', $alignment));
        $alignment = ($alignment === '' ? '' : $alignment.' ').'wrapText="1"';

        $xf = (string) preg_replace('/<alignment\b[^>]*\/>|<alignment\b[^>]*>.*?<\/alignment>/s', '', $xf);
        $xf = self::withXfAttribute($xf, 'applyAlignment', '1');
        $element = '<alignment '.$alignment.'/>';

        if (preg_match('/^<xf\b[^>]*\/>$/s', $xf) === 1) {
            return (string) preg_replace('/\/>$/', '>'.$element.'</xf>', $xf, 1);
        }

        if (str_ends_with($xf, '</xf>')) {
            return substr($xf, 0, -strlen('</xf>')).$element.'</xf>';
        }

        return $xf;
    }

    /**
     * Append the new fonts and cellXfs to styles.xml and keep both `count`
     * attributes truthful.
     *
     * @param  array<int, string>  $newFonts
     * @param  array<int, string>  $newXfs
     */
    private static function appendStyleElements(string $stylesXml, array $fontsBlock, array $xfsBlock, array $newFonts, array $newXfs): string
    {
        if ($newFonts !== []) {
            $fontCount = preg_match_all('/<font\b/', $fontsBlock[2]);
            $attrs = self::withCountAttribute($fontsBlock[1], $fontCount + count($newFonts));
            $fontsXml = '<fonts'.$attrs.'>'.$fontsBlock[2].implode('', $newFonts).'</fonts>';
            $stylesXml = (string) preg_replace_callback(
                '/<fonts\b[^>]*>.*?<\/fonts>/s',
                static fn (): string => $fontsXml,
                $stylesXml,
                1
            );
        }

        if ($newXfs !== []) {
            $xfCount = preg_match_all('/<xf\b/', $xfsBlock[2]);
            $attrs = self::withCountAttribute($xfsBlock[1], $xfCount + count($newXfs));
            $xfsXml = '<cellXfs'.$attrs.'>'.$xfsBlock[2].implode('', $newXfs).'</cellXfs>';
            $stylesXml = (string) preg_replace_callback(
                '/<cellXfs\b[^>]*>.*?<\/cellXfs>/s',
                static fn (): string => $xfsXml,
                $stylesXml,
                1
            );
        }

        return $stylesXml;
    }

    private static function withCountAttribute(string $attrs, int $count): string
    {
        if (preg_match('/\bcount="\d+"/', $attrs) === 1) {
            return (string) preg_replace('/\bcount="\d+"/', 'count="'.$count.'"', $attrs, 1);
        }

        return rtrim($attrs).' count="'.$count.'"';
    }

    /**
     * The cellXfs index of the first style whose fill is gray — the template's
     * section-header colour — so account-title rows paint the same gray as the
     * form's own section bands. Returns null when no gray fill exists, in
     * which case title rows keep their column's harvested style (no
     * corruption).
     */
    private static function titleStyle(array $parts): ?int
    {
        $styles = $parts['xl/styles.xml'] ?? '';

        if ($styles === '' || ! preg_match('/<cellXfs[^>]*>(.*?)<\/cellXfs>/s', $styles, $block)) {
            return null;
        }

        if (! preg_match_all('/<xf\b[^>]*>/s', $block[1], $xfs)) {
            return null;
        }

        foreach ($xfs[0] as $index => $xf) {
            if (! preg_match('/\bfillId="(\d+)"/', $xf, $f)) {
                continue;
            }

            if (self::fillIsGray($styles, (int) $f[1])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Whether the fill at $fillId paints a mid-gray: a gray125 pattern, or a
     * solid fill whose foreground/background colour is gray.
     */
    private static function fillIsGray(string $styles, int $fillId): bool
    {
        if (! preg_match('/<fills[^>]*>(.*?)<\/fills>/s', $styles, $block)) {
            return false;
        }

        $index = -1;

        if (! preg_match_all('/<fill\b[^>]*>.*?<\/fill>/s', $block[1], $fills, PREG_SET_ORDER)) {
            return false;
        }

        foreach ($fills as $fill) {
            $index++;

            if ($index !== $fillId) {
                continue;
            }

            $xml = $fill[0];

            if (preg_match('/patternType="gray125"/i', $xml) === 1) {
                return true;
            }

            if (preg_match_all('/<(fg|bg)Color\b[^>]*\brgb="([0-9A-Fa-f]{6,8})"/i', $xml, $colors, PREG_SET_ORDER) === 1) {
                foreach ($colors as $color) {
                    if (self::isGrayRgb($color[2])) {
                        return true;
                    }
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Whether an ARGB/RGB colour is a mid-gray: r == g == b, neither near-black
     * nor near-white.
     */
    private static function isGrayRgb(string $rgb): bool
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $rgb) ?? '');

        if (strlen($hex) === 8) {
            $hex = substr($hex, 2); // drop the ARGB alpha byte
        }

        if (strlen($hex) !== 6) {
            return false;
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return $r === $g && $g === $b && $r >= 0x40 && $r <= 0xE8;
    }

    /**
     * Rewrite the template's shared formulas as ordinary ones, before any row
     * is moved or deleted.
     *
     * A shared formula is stored once, on a master cell; every other cell in
     * its range carries only `<f t="shared" si="n"/>` and is resolved against
     * where that master SITS. Row surgery breaks the contract: trimming the
     * blank band can delete a master whose dependents survive in the TOTAL
     * row, and Excel then repairs the workbook and reports "Removed Records:
     * Shared formula". Expanding each dependent to the formula it stands for
     * (the master's formula shifted to the dependent's offset) leaves every
     * formula self-contained, so the rows may then be moved freely.
     */
    private static function expandSharedFormulas(string $sheetXml): string
    {
        if (! str_contains($sheetXml, 't="shared"')) {
            return $sheetXml;
        }

        // si => the master's position and the formula it stores.
        $masters = [];

        if (preg_match_all('/<c\b[^>]*\/>|<c\b[^>]*>.*?<\/c>/s', $sheetXml, $cells) !== false) {
            foreach ($cells[0] as $cell) {
                if (preg_match('/\br="([A-Z]+)(\d+)"/', $cell, $ref) !== 1
                    || preg_match('/<f\b([^>]*)>(.*?)<\/f>/s', $cell, $formula) !== 1
                    || preg_match('/\bt="shared"/', $formula[1]) !== 1
                    || preg_match('/\bsi="(\d+)"/', $formula[1], $si) !== 1) {
                    continue;
                }

                $masters[$si[1]] = [
                    'column' => self::columnIndex($ref[1]),
                    'row' => (int) $ref[2],
                    'formula' => $formula[2],
                ];
            }
        }

        if ($masters === []) {
            return $sheetXml;
        }

        return (string) preg_replace_callback(
            '/<c\b[^>]*\/>|<c\b[^>]*>.*?<\/c>/s',
            static function (array $m) use ($masters): string {
                $cell = $m[0];

                if (preg_match('/\br="([A-Z]+)(\d+)"/', $cell, $ref) !== 1) {
                    return $cell;
                }

                $column = self::columnIndex($ref[1]);
                $row = (int) $ref[2];

                // The master keeps its formula but loses the shared markers:
                // once every dependent is expanded, nothing refers to it.
                $cell = (string) preg_replace_callback(
                    '/<f\b([^>]*)>(.*?)<\/f>/s',
                    static function (array $f): string {
                        if (preg_match('/\bt="shared"/', $f[1]) !== 1) {
                            return $f[0];
                        }

                        return '<f'.self::withoutSharedAttributes($f[1]).'>'.$f[2].'</f>';
                    },
                    $cell,
                    1
                );

                // A dependent becomes the master's formula translated to this
                // cell: relative columns and rows move, `$`-anchored ones stay.
                return (string) preg_replace_callback(
                    '/<f\b([^>]*)\bsi="(\d+)"([^>]*)\/>/',
                    static function (array $f) use ($column, $row, $masters): string {
                        $attributes = $f[1].$f[3];
                        $master = $masters[$f[2]] ?? null;

                        if ($master === null || preg_match('/\bt="shared"/', $attributes) !== 1) {
                            return $f[0];
                        }

                        $formula = self::translateFormula(
                            $master['formula'],
                            $row - $master['row'],
                            $column - $master['column'],
                        );

                        return '<f'.self::withoutSharedAttributes($attributes).'>'.$formula.'</f>';
                    },
                    $cell
                );
            },
            $sheetXml
        );
    }

    /** An `<f>` tag's attributes minus the shared-formula bookkeeping. */
    private static function withoutSharedAttributes(string $attributes): string
    {
        return rtrim((string) preg_replace(
            '/\s+(?:t="shared"|ref="[^"]*"|si="\d+")/',
            '',
            $attributes
        ));
    }

    /**
     * Shift every relative A1 reference in a formula by the given offsets.
     * Quoted strings and `$`-anchored parts of a reference are left alone, and
     * a function name (`LOG10(`, `DAYS360(`) is not mistaken for a reference.
     */
    private static function translateFormula(string $formula, int $rowOffset, int $columnOffset): string
    {
        if ($rowOffset === 0 && $columnOffset === 0) {
            return $formula;
        }

        // Only the text outside double-quoted string literals is a formula.
        $parts = preg_split('/("(?:[^"]|"")*")/', $formula, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $formula;
        }

        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                continue;
            }

            $parts[$index] = (string) preg_replace_callback(
                '/(?<![A-Za-z0-9_.])(\$?)([A-Z]{1,3})(\$?)(\d+)(?![0-9A-Za-z_(!])/',
                static function (array $m) use ($rowOffset, $columnOffset): string {
                    $column = self::columnIndex($m[2]) + ($m[1] === '' ? $columnOffset : 0);
                    $row = (int) $m[4] + ($m[3] === '' ? $rowOffset : 0);

                    return $m[1].self::columnLetter(max(1, $column)).$m[3].max(1, $row);
                },
                $part
            );
        }

        return implode('', $parts);
    }

    /**
     * Rewrite the row numbers inside every formula element, self-closing ones
     * included, through $map.
     *
     * The self-closing alternative must come FIRST: `/<f\b[^>]*>.*?<\/f>/s`
     * treats a `<f t="shared" si="…"/>` as an opening tag and pairs it with the
     * NEXT formula's closing tag, swallowing the cells in between — whose
     * `r="…"` references are then rewritten as if they were part of the
     * formula. That is what turned a TOTAL row's cell references into
     * references to the deleted data rows.
     *
     * @param  callable(int): int  $map
     */
    private static function rewriteFormulaRows(string $xml, callable $map): string
    {
        return (string) preg_replace_callback(
            '/<f\b[^>]*\/>|<f\b[^>]*>.*?<\/f>/s',
            static fn (array $m): string => (string) preg_replace_callback(
                '/(\$?[A-Z]{1,3}\$?)(\d+)(?!\d)/',
                static fn (array $c): string => $c[1].$map((int) $c[2]),
                $m[0]
            ),
            $xml
        );
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

                $row = (string) preg_replace_callback(
                    '/\br="([A-Z]+)'.$number.'"/',
                    static fn (array $c): string => 'r="'.$c[1].$new.'"',
                    $row
                );

                // A formula that points at or below the insertion point moves
                // with the rows it names (the TOTAL row's own-row range, for
                // one); one that points into the band above does not.
                return self::rewriteFormulaRows(
                    $row,
                    static fn (int $target): int => $target > $greaterThan ? $target + $delta : $target,
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

        $updated = self::rewriteFormulaRows(
            $row,
            static fn (int $target): int => $target === $oldLastDataRow ? $newLast : $target,
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
            $clone = self::rewriteFormulaRows(
                $clone,
                static fn (int $target): int => $target === $lastDataRow ? $new : $target,
            );

            $clones .= $clone;
        }

        return (string) preg_replace_callback($pattern, static fn (array $mm): string => $mm[0].$clones, $sheetXml, 1);
    }

    /**
     * Remove the tail of the template's blank data band, keeping `$keep` rows,
     * and pull every row below the band up by the removed count. The rows that
     * move carry formulas (the TOTAL row's SUM ranges and its self-references)
     * and merges, so both are re-pointed at the rows' new positions.
     *
     * Only whole rows from the END of the band are removed, so a line that
     * stays keeps its own per-row formulas untouched.
     *
     * @param  array<int, int>  $dataRows  the band, in sheet order
     */
    private static function trimDataRows(string $sheetXml, array $dataRows, int $keep): string
    {
        $band = array_values(array_map('intval', $dataRows));

        if ($keep >= count($band)) {
            return $sheetXml;
        }

        $firstRemoved = $band[$keep];
        $lastRemoved = $band[count($band) - 1];
        $removed = $lastRemoved - $firstRemoved + 1;
        $lastKept = $firstRemoved - 1;

        // The removed rows themselves.
        $sheetXml = (string) preg_replace_callback(
            self::ROW_PATTERN,
            static function (array $m) use ($firstRemoved, $lastRemoved): string {
                if (preg_match('/\br="(\d+)"/', $m[0], $rn) !== 1) {
                    return $m[0];
                }

                $number = (int) $rn[1];

                return $number >= $firstRemoved && $number <= $lastRemoved ? '' : $m[0];
            },
            $sheetXml
        );

        // Everything below the band moves up, cells and formulas included.
        $sheetXml = (string) preg_replace_callback(
            self::ROW_PATTERN,
            static function (array $m) use ($lastRemoved, $lastKept, $removed): string {
                $row = $m[0];

                if (preg_match('/\br="(\d+)"/', $row, $rn) !== 1) {
                    return $row;
                }

                $number = (int) $rn[1];

                if ($number <= $lastRemoved) {
                    return $row;
                }

                $new = $number - $removed;

                $row = (string) preg_replace('/<row\b([^>]*?)\br="'.$number.'"/', '<row$1r="'.$new.'"', $row, 1);

                $row = (string) preg_replace_callback(
                    '/\br="([A-Z]+)'.$number.'"/',
                    static fn (array $c): string => 'r="'.$c[1].$new.'"',
                    $row
                );

                return self::shiftFormulaRows($row, $lastRemoved, $lastKept, $removed);
            },
            $sheetXml
        );

        return self::shiftMergesUp($sheetXml, $firstRemoved, $lastRemoved, $removed);
    }

    /**
     * Re-point a moved row's formulas at the rows' new positions: a reference
     * past the removed band shifts up; a reference into the band collapses
     * onto the band's new last row, so a TOTAL `SUM(...)` still covers exactly
     * the surviving data rows.
     */
    private static function shiftFormulaRows(string $row, int $lastRemoved, int $lastKept, int $removed): string
    {
        return self::rewriteFormulaRows(
            $row,
            static function (int $number) use ($lastRemoved, $lastKept, $removed): int {
                if ($number > $lastRemoved) {
                    return $number - $removed;
                }

                if ($number > $lastKept) {
                    return $lastKept;
                }

                return $number;
            },
        );
    }

    /**
     * Drop merge ranges wholly inside the removed band, move the ones below it
     * up, and clamp a range that straddles the cut to the surviving rows.
     * The `<mergeCells count>` is kept in step with the ranges that remain.
     */
    private static function shiftMergesUp(string $sheetXml, int $firstRemoved, int $lastRemoved, int $removed): string
    {
        $sheetXml = (string) preg_replace_callback(
            '/<mergeCell ref="([A-Z]+)(\d+):([A-Z]+)(\d+)"\/>/',
            static function (array $m) use ($firstRemoved, $lastRemoved, $removed): string {
                $start = (int) $m[2];
                $end = (int) $m[4];

                if ($start >= $firstRemoved && $end <= $lastRemoved) {
                    return '';
                }

                if ($start > $lastRemoved) {
                    $start -= $removed;
                    $end -= $removed;
                } elseif ($start >= $firstRemoved) {
                    $start = $firstRemoved;
                    $end -= $removed;
                } elseif ($end >= $firstRemoved) {
                    $end = $firstRemoved - 1;
                }

                return '<mergeCell ref="'.$m[1].$start.':'.$m[3].$end.'"/>';
            },
            $sheetXml
        );

        return self::updateMergeCount($sheetXml);
    }

    /** Rewrite `<mergeCells count>` to the number of ranges left in it. */
    private static function updateMergeCount(string $sheetXml): string
    {
        if (preg_match('/<mergeCells\b([^>]*)>(.*?)<\/mergeCells>/s', $sheetXml, $m) !== 1) {
            return $sheetXml;
        }

        $count = preg_match_all('/<mergeCell\b/', $m[2]);

        if ($count === 0) {
            return (string) preg_replace('/<mergeCells\b[^>]*>.*?<\/mergeCells>/s', '', $sheetXml, 1);
        }

        $attrs = (string) preg_replace('/\bcount="\d+"/', 'count="'.$count.'"', $m[1]);
        $rebuilt = '<mergeCells'.$attrs.'>'.$m[2].'</mergeCells>';

        return (string) preg_replace_callback(
            '/<mergeCells\b[^>]*>.*?<\/mergeCells>/s',
            static fn (): string => $rebuilt,
            $sheetXml,
            1
        );
    }

    /**
     * Keep the sheet's print area as tall as the sheet actually is: a trimmed
     * form must not print the template's removed blank band, and a form that
     * grew past the template's band must still print its new lines.
     */
    private static function updatePrintArea(string $workbookXml, string $sheetName, string $sheetXml): string
    {
        if ($workbookXml === '' || $sheetName === '') {
            return $workbookXml;
        }

        $maxRow = 0;

        if (preg_match_all('/<row\b[^>]*\br="(\d+)"/', $sheetXml, $rows) > 0) {
            foreach ($rows[1] as $row) {
                $maxRow = max($maxRow, (int) $row);
            }
        }

        if ($maxRow === 0) {
            return $workbookXml;
        }

        $quoted = "'".$sheetName."'!";
        $plain = $sheetName.'!';

        return (string) preg_replace_callback(
            '/<definedName\b([^>]*\bname="_xlnm\.Print_Area"[^>]*)>(.*?)<\/definedName>/s',
            static function (array $m) use ($maxRow, $quoted, $plain): string {
                $ref = html_entity_decode($m[2], ENT_XML1 | ENT_QUOTES, 'UTF-8');

                if (! str_starts_with($ref, $quoted) && ! str_starts_with($ref, $plain)) {
                    return $m[0];
                }

                $ref = (string) preg_replace('/(:\$?[A-Z]+\$?)\d+$/', '${1}'.$maxRow, $ref);

                return '<definedName'.$m[1].'>'.htmlspecialchars($ref, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</definedName>';
            },
            $workbookXml
        );
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

    /**
     * Give each generated line the height its wrapped text needs. The template
     * stores fixed heights copied from the workbook it was cut from (21, 42,
     * 63, 102, 122.4 …), so a line landing on one of those rows would either
     * clip its text or be absurdly tall. The height is written without
     * `customHeight`, leaving Excel free to re-fit the row after an edit.
     *
     * @param  array<int, int>  $dataRows
     */
    private static function fitRowHeights(string $sheetXml, array $dataRows, string $stylesXml, bool $wrap = true): string
    {
        if ($dataRows === []) {
            return $sheetXml;
        }

        $widths = self::columnWidths($sheetXml);
        $fontSizes = self::styleFontSizes($stylesXml);

        foreach ($dataRows as $rowNumber) {
            $sheetXml = self::fitRowHeight($sheetXml, (int) $rowNumber, $widths, $fontSizes, $wrap);
        }

        return $sheetXml;
    }

    /**
     * @param  array<int, float>  $widths  1-based column index => width
     * @param  array<int, float>  $fontSizes  style id => point size
     */
    private static function fitRowHeight(string $sheetXml, int $rowNumber, array $widths, array $fontSizes, bool $wrap = true): string
    {
        $pattern = self::rowPattern($rowNumber);

        if (preg_match($pattern, $sheetXml, $m) !== 1) {
            return $sheetXml;
        }

        $row = $m[0];
        $height = 15.0;

        if (preg_match_all('/<c\b[^>]*>.*?<\/c>/s', $row, $cells) !== false) {
            foreach ($cells[0] as $cell) {
                if (preg_match('/\br="([A-Z]+)\d+"/', $cell, $ref) !== 1) {
                    continue;
                }

                if (preg_match('/<is>.*?<t[^>]*>(.*?)<\/t>/s', $cell, $text) !== 1) {
                    continue;
                }

                $value = html_entity_decode($text[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');

                if (trim($value) === '') {
                    continue;
                }

                $styleId = preg_match('/\bs="(\d+)"/', $cell, $s) === 1 ? (int) $s[1] : 0;
                $fontSize = $fontSizes[$styleId] ?? 11.0;
                $lineHeight = max(15.0, $fontSize * 1.32);
                $columnWidth = $widths[self::columnIndex($ref[1])] ?? 8.43;

                // Excel's width unit is one character of the default 11pt
                // font; a 16pt cell fits proportionally fewer per line. The
                // 0.85 margin absorbs word-wrap boundaries, which can leave a
                // line short enough to need one more than the raw length
                // suggests.
                $charactersPerLine = max(4, (int) floor($columnWidth * (11.0 / $fontSize) * 0.85));
                $lines = 0;

                if (! $wrap) {
                    $lines = 1;
                } else {
                    foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
                        $lines += max(1, (int) ceil(mb_strlen($line) / $charactersPerLine));
                    }
                }

                $height = max($height, max(1, $lines) * $lineHeight);
            }
        }

        $height = min(409.0, $height);

        if (preg_match('/^<row\b([^>]*?)(\/?)>/s', $row, $open) === 1) {
            $attrs = (string) preg_replace('/\s+(?:ht|customHeight)="[^"]*"/', '', $open[1]);
            $attrs = rtrim($attrs).' ht="'.self::formatHeight($height).'"';
            $row = '<row'.$attrs.$open[2].'>'.substr($row, strlen($open[0]));
        }

        return (string) preg_replace_callback($pattern, static fn (): string => $row, $sheetXml, 1);
    }

    /**
     * @return array<int, float> 1-based column index => width
     */
    private static function columnWidths(string $sheetXml): array
    {
        $widths = [];
        $default = 8.43;

        if (preg_match('/<sheetFormatPr\b[^>]*\bdefaultColWidth="([0-9.]+)"/', $sheetXml, $m) === 1) {
            $default = (float) $m[1];
        }

        if (preg_match('/<cols\b[^>]*>(.*?)<\/cols>/s', $sheetXml, $block) !== 1) {
            return $widths;
        }

        if (preg_match_all('/<col\b[^>]*\/?>/', $block[1], $cols) === false) {
            return $widths;
        }

        foreach ($cols[0] as $col) {
            $min = preg_match('/\bmin="(\d+)"/', $col, $minMatch) === 1 ? (int) $minMatch[1] : 1;
            $max = preg_match('/\bmax="(\d+)"/', $col, $maxMatch) === 1 ? (int) $maxMatch[1] : $min;
            $width = preg_match('/\bwidth="([0-9.]+)"/', $col, $widthMatch) === 1 ? (float) $widthMatch[1] : $default;

            // The template ends with `<col min="52" max="16384">`; only the
            // columns this table can occupy are worth remembering.
            $max = min($max, 200);

            for ($index = $min; $index <= $max; $index++) {
                $widths[$index] = $width;
            }
        }

        return $widths;
    }

    /**
     * @return array<int, float> style id => point size
     */
    private static function styleFontSizes(string $stylesXml): array
    {
        if ($stylesXml === ''
            || preg_match('/<fonts\b[^>]*>(.*?)<\/fonts>/s', $stylesXml, $fontsBlock) !== 1
            || preg_match('/<cellXfs\b[^>]*>(.*?)<\/cellXfs>/s', $stylesXml, $xfsBlock) !== 1) {
            return [];
        }

        if (preg_match_all('/<font\b[^>]*>.*?<\/font>|<font\b[^>]*\/>/s', $fontsBlock[1], $fonts) === 0
            || preg_match_all('/<xf\b[^>]*\/>|<xf\b[^>]*>.*?<\/xf>/s', $xfsBlock[1], $xfs) === 0) {
            return [];
        }

        $sizes = [];

        foreach ($fonts[0] as $index => $font) {
            $sizes[$index] = preg_match('/<sz\b[^>]*\bval="([0-9.]+)"/', $font, $m) === 1 ? (float) $m[1] : 11.0;
        }

        $styleSizes = [];

        foreach ($xfs[0] as $index => $xf) {
            $fontId = self::styleFontId($xf);
            $styleSizes[$index] = $fontId !== null ? ($sizes[$fontId] ?? 11.0) : 11.0;
        }

        return $styleSizes;
    }

    private static function formatHeight(float $height): string
    {
        return rtrim(rtrim(number_format($height, 2, '.', ''), '0'), '.');
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
