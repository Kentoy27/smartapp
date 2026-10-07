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
 *   - it loads the first worksheet's cells (shared/inline strings resolved),
 *   - validates the workbook by *searching* for the WFP headings, and
 *   - detects a title/school/year best-effort for the status card, and
 *   - exposes the used range as a grid for the preview table.
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
        foreach ($this->cells as $value) {
            if (preg_match('/\bCY\s*(\d{4})\b/i', $value, $m) === 1) {
                return 'CY '.$m[1];
            }
        }

        foreach ($this->cells as $value) {
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
        foreach ($this->cells as $value) {
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
        $haystack = $this->normalizedText();

        foreach ((array) config('wfp.required_keywords', ['work and financial plan']) as $phrase) {
            if (! str_contains($haystack, $this->normalize((string) $phrase))) {
                throw new RuntimeException(
                    'This workbook doesn’t look like the WFP template — the “Work and Financial Plan” heading was not found.'
                );
            }
        }

        $sections = (array) config('wfp.section_keywords', []);
        $found = 0;

        foreach ($sections as $section) {
            if (str_contains($haystack, $this->normalize((string) $section))) {
                $found++;
            }
        }

        if ($found < (int) config('wfp.min_sections', 3)) {
            throw new RuntimeException(
                'This workbook is missing the WFP sections (Objectives, Programs/Projects/Activities, Major Outputs, …). Upload a completed copy of the official WFP template.'
            );
        }
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
        $this->cells = $this->readWorksheet($this->firstWorksheet($entries));
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

    /* ------------------------------------------------------------------
     * Small helpers
     * ----------------------------------------------------------------- */

    /**
     * All cell text joined and normalized (alphanumerics only, lowercased),
     * so headings compare tolerantly across case, punctuation, and line wraps.
     */
    private function normalizedText(): string
    {
        return $this->normalize(implode(' ', array_values($this->cells)));
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
