<?php

namespace App\Support;

use RuntimeException;

/**
 * Tolerant reader for an uploaded Work and Financial Plan .xlsx.
 *
 * Unlike OpcrfSpreadsheet (which hard-codes the OPCRF template's layout),
 * the WFP workbook is wide and free-form — the app does not know, or want to
 * know, where each figure lives. So this reader stays layout-agnostic:
 *
 *   - it loads every worksheet's cells (shared/inline strings resolved),
 *   - validates and analyzes the workbook's WFP headings across all sheets,
 *   - detects a title/school/year best-effort for the status card, and
 *   - exposes every populated row for the review table.
 *
 * The workbook is opened with PHP's native ZipArchive when the zip extension
 * is loaded, and falls back to PurePhpZipReader on builds without it (e.g.
 * XAMPP's web-server PHP ships with php_zip disabled). No external package.
 */
class WfpSpreadsheet
{
    /**
     * Shared strings from xl/sharedStrings.xml, indexed in file order.
     *
     * @var array<int, string>
     */
    private array $sharedStrings = [];

    /**
     * Sheet cell values keyed by reference ("B4"), shared strings and
     * inline strings already resolved to plain text.
     *
     * @var array<string, string>
     */
    private array $cells = [];

    /**
     * Worksheet names mapped to their cell values.
     *
     * @var array<string, array<string, string>>
     */
    private array $worksheets = [];

    public function __construct(string $filePath)
    {
        $this->load($filePath);
    }

    /* ------------------------------------------------------------------
     * Metadata for the status card
     * ----------------------------------------------------------------- */

    /**
     * A best-effort title: the first substantial text cell in the top rows.
     */
    public function title(): string
    {
        $byRow = [];

        foreach ($this->cells as $ref => $value) {
            if ($value === '' || is_numeric($value)) {
                continue;
            }

            $parts = $this->refParts($ref);

            if ($parts === null || $parts['row'] > 5) {
                continue;
            }

            $byRow[$parts['row']][$parts['col']] = $value;
        }

        ksort($byRow);

        foreach ($byRow as $row) {
            ksort($row);

            foreach ($row as $value) {
                if (mb_strlen($value) >= 6) {
                    return mb_substr(trim(preg_replace('/\s+/', ' ', $value) ?? $value), 0, 200);
                }
            }
        }

        return '';
    }

    /**
     * The planning year detected in the sheet: "CY 2025" (or a bare year).
     */
    public function detectSchoolYear(): ?string
    {
        foreach ($this->allCellValues() as $value) {
            if (preg_match('/\bCY\s*(\d{4})\b/i', $value, $m) === 1) {
                return 'CY '.$m[1];
            }
        }

        foreach ($this->allCellValues() as $value) {
            if (preg_match('/\b(20\d{2})\b/', $value, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * The school named in the sheet, e.g. "Bino Elementary School".
     */
    public function detectSchoolName(): ?string
    {
        foreach ($this->allCellValues() as $value) {
            if (preg_match(
                "#([A-Za-z][A-Za-z.'\- ]{1,60}?\s+(?:Elementary|Central|Integrated|National|High|Secondary)\s+School)#i",
                $value,
                $m
            ) === 1) {
                return mb_substr(trim(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]), 0, 255);
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------
     * Structure validation
     * ----------------------------------------------------------------- */

    /**
     * Reject workbooks that clearly are not a WFP.
     *
     * The check is deliberately tolerant: it looks only for the defining
     * heading phrase plus a handful of the section headings, so a completed
     * copy of the official template — with extra rows, renamed activities,
     * or reordered columns — still passes.
     *
     * @throws RuntimeException when the workbook does not look like a WFP.
     */
    public function validateStructure(): void
    {
        $analysis = $this->analysis();
        $validation = $this->validation($analysis);

        if ($validation['errors'] !== []) {
            throw new RuntimeException(implode(' ', $validation['errors']));
        }
    }

    /**
     * Workbook-wide findings, based on every worksheet's actual populated
     * cells. This reports structure and coverage without inventing scores.
     *
     * @return array<string, mixed>
     */
    public function analysis(): array
    {
        $allValues = [];
        $sheetSummaries = [];

        foreach ($this->worksheets as $name => $cells) {
            $values = array_values($cells);
            $allValues = array_merge($allValues, $values);
            $sheetGrid = $this->worksheetGrid($cells);
            $sheetSummaries[] = [
                'name' => $name,
                'rows' => count($sheetGrid['rows']),
                'columns' => count($sheetGrid['column_indexes']),
                'populated_cells' => count(array_filter($values, fn (string $value): bool => trim($value) !== '')),
            ];
        }

        $haystack = $this->normalize(implode(' ', $allValues));
        $requiredHeadings = [];

        foreach ((array) config('wfp.required_keywords', ['work and financial plan']) as $phrase) {
            $phrase = (string) $phrase;
            $requiredHeadings[] = [
                'label' => $phrase,
                'found' => str_contains($haystack, $this->normalize($phrase)),
            ];
        }

        $sections = [];

        foreach ((array) config('wfp.section_keywords', []) as $section) {
            $section = (string) $section;
            $sections[] = [
                'label' => $section,
                'found' => str_contains($haystack, $this->normalize($section)),
            ];
        }

        return [
            'sheet_count' => count($sheetSummaries),
            'row_count' => array_sum(array_column($sheetSummaries, 'rows')),
            'populated_cell_count' => array_sum(array_column($sheetSummaries, 'populated_cells')),
            'required_headings' => $requiredHeadings,
            'sections' => $sections,
            'sections_found' => count(array_filter($sections, fn (array $section): bool => $section['found'])),
            'sections_required' => (int) config('wfp.min_sections', 3),
            'sheets' => $sheetSummaries,
        ];
    }

    /**
     * Validation findings shown beside the workbook-wide analysis.
     *
     * @param  array<string, mixed>|null  $analysis
     * @return array{passed: bool, errors: array<int, string>, warnings: array<int, string>}
     */
    public function validation(?array $analysis = null): array
    {
        $analysis ??= $this->analysis();
        $errors = [];
        $warnings = [];

        foreach ($analysis['required_headings'] as $heading) {
            if (! $heading['found']) {
                $errors[] = 'Required heading not found: '.$heading['label'].'.';
            }
        }

        if ($analysis['sections_found'] < $analysis['sections_required']) {
            $errors[] = 'The workbook does not contain enough recognizable WFP sections. Found '
                .$analysis['sections_found'].'; at least '.$analysis['sections_required'].' are required.';
        }

        foreach ($analysis['sections'] as $section) {
            if (! $section['found']) {
                $warnings[] = 'Section heading not found: '.$section['label'].'.';
            }
        }

        return ['passed' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * All worksheet names and populated rows, preserving source row and
     * column positions while avoiding dense blank cells in stored JSON.
     *
     * @return array<int, array{name: string, column_indexes: array<int, int>, rows: array<int, array{number: int, cells: array<int, string>}>, header_index: ?int}>
     */
    public function workbookSheets(): array
    {
        $sheets = [];

        foreach ($this->worksheets as $name => $cells) {
            $sheets[] = ['name' => $name] + $this->worksheetGrid($cells);
        }

        return $sheets;
    }

    /**
     * The complete data package persisted with a WFP upload.
     *
     * @return array{sheet_data: array<int, array<string, mixed>>, analysis: array<string, mixed>, validation: array<string, mixed>}
     */
    public function reviewData(): array
    {
        $analysis = $this->analysis();

        return [
            'sheet_data' => $this->workbookSheets(),
            'analysis' => $analysis,
            'validation' => $this->validation($analysis),
        ];
    }

    /* ------------------------------------------------------------------
     * Preview
     * ----------------------------------------------------------------- */

    /**
     * The used range as a list of non-empty rows, for the preview table.
     *
     * @return array{rows: array<int, array{number: int, cells: array<int, string>}>, columns: int, header_index: ?int, truncated: bool}
     */
    public function grid(int $maxRows = 60): array
    {
        /** @var array<int, array<int, string>> $perRow */
        $perRow = [];
        $maxRow = 0;
        $maxCol = 0;

        foreach ($this->cells as $ref => $value) {
            $parts = $this->refParts($ref);

            if ($parts === null) {
                continue;
            }

            $perRow[$parts['row']][$parts['col']] = $value;
            $maxRow = max($maxRow, $parts['row']);
            $maxCol = max($maxCol, $parts['col']);
        }

        $rows = [];
        $lastRow = 0;
        $truncated = false;

        for ($r = 1; $r <= $maxRow; $r++) {
            $source = $perRow[$r] ?? [];
            $cells = [];
            $hasContent = false;

            for ($c = 1; $c <= $maxCol; $c++) {
                $v = $source[$c] ?? '';
                $cells[$c] = $v;
                $hasContent = $hasContent || $v !== '';
            }

            if (! $hasContent) {
                continue; // skip blank separator rows
            }

            if (count($rows) >= $maxRows) {
                $truncated = true;
                break;
            }

            $rows[] = ['number' => $r, 'cells' => $cells];
            $lastRow = $r;
        }

        // The header is the busiest row among the first few used rows.
        $headerIndex = null;
        $best = 0;

        foreach (array_slice($rows, 0, 10, true) as $i => $row) {
            $count = count(array_filter($row['cells'], fn (string $v): bool => $v !== ''));

            if ($count > $best) {
                $best = $count;
                $headerIndex = $i;
            }
        }

        return [
            'rows' => $rows,
            'columns' => $maxCol,
            'header_index' => $headerIndex,
            'truncated' => $truncated,
        ];
    }

    /* ------------------------------------------------------------------
     * Workbook loading
     * ----------------------------------------------------------------- */

    private function load(string $filePath): void
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('The uploaded file could not be found.');
        }

        $entries = class_exists(\ZipArchive::class)
            ? $this->readZipEntriesWithArchive($filePath)
            : (new PurePhpZipReader($filePath))->readAll();

        $this->sharedStrings = $this->readSharedStrings($entries['xl/sharedStrings.xml'] ?? false);
        $this->worksheets = $this->readWorksheets($entries);
        $this->cells = reset($this->worksheets) ?: [];
    }

    /**
     * @return array<string, string>
     */
    private function readZipEntriesWithArchive(string $filePath): array
    {
        $zip = new \ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('The file is not a valid .xlsx workbook.');
        }

        try {
            $entries = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $contents = $zip->getFromIndex($i);

                if ($name !== false && $contents !== false) {
                    $entries[$name] = $contents;
                }
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /**
     * The XML of the first worksheet entry ("xl/worksheets/sheetN.xml").
     *
     * @param  array<string, string>  $entries
     */
    private function firstWorksheet(array $entries): string|false
    {
        foreach (array_keys($entries) as $name) {
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1) {
                return $entries[$name];
            }
        }

        return false;
    }

    /**
     * Resolve sheet names and worksheet files through the workbook
     * relationships; fall back to sheet file order for older workbooks.
     *
     * @param  array<string, string>  $entries
     * @return array<string, array<string, string>>
     */
    private function readWorksheets(array $entries): array
    {
        $workbookXml = $entries['xl/workbook.xml'] ?? false;
        $relationshipsXml = $entries['xl/_rels/workbook.xml.rels'] ?? false;
        $sheetFiles = [];

        if ($workbookXml !== false && $relationshipsXml !== false) {
            $workbook = @simplexml_load_string($workbookXml);
            $relationships = @simplexml_load_string($relationshipsXml);

            if ($workbook !== false && $relationships !== false) {
                $relationshipTargets = [];

                foreach ($relationships->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $relationship) {
                    $attributes = $relationship->attributes();
                    $relationshipTargets[(string) $attributes['Id']] = (string) $attributes['Target'];
                }

                foreach ($workbook->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->sheets
                    ->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->sheet as $sheet) {
                    $attributes = $sheet->attributes();
                    $relationshipAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    $name = trim((string) $attributes['name']);
                    $target = $relationshipTargets[(string) $relationshipAttributes['id']] ?? null;

                    if ($name === '' || $target === null) {
                        continue;
                    }

                    $path = $this->normalizeZipPath(str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target);

                    if (isset($entries[$path])) {
                        $sheetFiles[$name] = $entries[$path];
                    }
                }
            }
        }

        if ($sheetFiles === []) {
            foreach ($entries as $path => $xml) {
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $path) === 1) {
                    $sheetFiles['Sheet '.(count($sheetFiles) + 1)] = $xml;
                }
            }
        }

        if ($sheetFiles === []) {
            throw new RuntimeException('The workbook has no readable worksheet.');
        }

        $sheets = [];

        foreach ($sheetFiles as $name => $xml) {
            $sheets[$name] = $this->readWorksheet($xml);
        }

        return $sheets;
    }

    private function normalizeZipPath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
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
     * @return array<int, string>
     */
    private function readSharedStrings(string|false $xml): array
    {
        if ($xml === false || $xml === '') {
            return [];
        }

        $document = @simplexml_load_string($xml);

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
     * @return array<string, string>
     */
    private function readWorksheet(string|false $xml): array
    {
        if ($xml === false || $xml === '') {
            throw new RuntimeException('The workbook has no readable worksheet.');
        }

        $document = @simplexml_load_string($xml);

        if ($document === false) {
            throw new RuntimeException('The worksheet could not be parsed.');
        }

        $cells = [];

        foreach ($document->sheetData->row as $row) {
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];

                if ($ref === '') {
                    continue;
                }

                $type = (string) $cell['t'];
                $value = '';

                if ($type === 'inlineStr' && isset($cell->is)) {
                    foreach ($cell->is->t as $t) {
                        $value .= (string) $t;
                    }
                } else {
                    $raw = (string) ($cell->v ?? '');

                    if ($raw !== '') {
                        $value = $type === 's'
                            ? ($this->sharedStrings[(int) $raw] ?? '')
                            : $raw;
                    }
                }

                $cells[$ref] = trim($value);
            }
        }

        return $cells;
    }

    /**
     * Build a sparse but position-preserving grid from worksheet cells.
     *
     * @param  array<string, string>  $cells
     * @return array{column_indexes: array<int, int>, rows: array<int, array{number: int, cells: array<int, string>}>, header_index: ?int}
     */
    private function worksheetGrid(array $cells): array
    {
        $perRow = [];
        $columns = [];

        foreach ($cells as $ref => $value) {
            $parts = $this->refParts($ref);

            if ($parts === null || $value === '') {
                continue;
            }

            $perRow[$parts['row']][$parts['col']] = $value;
            $columns[$parts['col']] = true;
        }

        ksort($perRow);
        $columnIndexes = array_keys($columns);
        sort($columnIndexes);
        $rows = [];

        foreach ($perRow as $number => $rowCells) {
            ksort($rowCells);
            $rows[] = ['number' => (int) $number, 'cells' => $rowCells];
        }

        $headerIndex = null;
        $best = 0;

        foreach (array_slice($rows, 0, 10, true) as $index => $row) {
            $count = count($row['cells']);

            if ($count > $best) {
                $best = $count;
                $headerIndex = $index;
            }
        }

        return [
            'column_indexes' => $columnIndexes,
            'rows' => $rows,
            'header_index' => $headerIndex,
        ];
    }

    /* ------------------------------------------------------------------
     * Small helpers
     * ----------------------------------------------------------------- */

    /**
     * All cell text joined and normalized (alphanumerics only, lowercased),
     * so headings compare tolerantly across case, punctuation, and line wraps.
     */
    private function normalizedText(): string
    {
        return $this->normalize(implode(' ', $this->allCellValues()));
    }

    /**
     * All text cells in workbook order, across every worksheet.
     *
     * @return array<int, string>
     */
    private function allCellValues(): array
    {
        $values = [];

        foreach ($this->worksheets as $cells) {
            array_push($values, ...array_values($cells));
        }

        return $values;
    }

    private function normalize(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    /**
     * @return array{col: int, row: int}|null
     */
    private function refParts(string $ref): ?array
    {
        if (preg_match('/^([A-Z]+)(\d+)$/', $ref, $m) !== 1) {
            return null;
        }

        return ['col' => $this->columnIndex($m[1]), 'row' => (int) $m[2]];
    }

    private function columnIndex(string $column): int
    {
        $index = 0;
        $length = strlen($column);

        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord(strtoupper($column[$i])) - 64);
        }

        return $index;
    }
}
