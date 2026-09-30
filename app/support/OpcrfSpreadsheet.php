<?php

namespace App\Support;

use RuntimeException;

/**
 * Minimal reader for the filled-in OPCRF .xlsx template.
 *
 * The uploaded workbook is the app's own OPCRF-TEMPLATE.xlsx after staff
 * have typed into it, so the analyzer only needs to know where each piece
 * of information lives:
 *
 *   - Title (B2): "OFFICE PERFORMANCE COMMITMENT AND REVIEW FORM (OPCRF)"
 *   - Header block (rows 4–7, merged label/value columns B..M):
 *       B4 "Name of Employee:"      → F4 value (merged F4:M4)
 *       B5 "Position/Designation:"  → F5 value (merged F5:M5)
 *       B6 "Review Period:"         → F6 value (merged F6:M6)
 *       B7 "Strand/Bureau/.../Division:" → F7 value (merged F7:M7)
 *     (the typed value can sit anywhere in that left value band, so the
 *     reader scans rightwards from C — but only up to column M. Columns
 *     N..V hold a separate evaluator block ("Position:", "Approving
 *     Authority:", "Evaluator:", "Date of Review:") whose values live
 *     in merged O..V cells, and the untouched template even pre-fills
 *     the evaluator's name in O4 — scanning that far would mistake it
 *     for the employee's own name.)
 *
 *   - Objectives table (row 13+): the objective text lives in a merged
 *     F..G band (G holds the typed objective when present, else F),
 *     column H "Timeline", column S "Actual Accomplishments" and column
 *     T "RATING (Q,E,T)" — numeric ratings per criteria row.
 *
 *   - Parts (the "PART I-A / I-B / I-C" banners in column B): each part
 *     contributes its own objective blocks, its own Timeline column
 *     (H for I-A/I-B, J for I-C) and its "Part … Total Score" row near
 *     the section end — parts() returns them in sheet order.
 *
 *   - Signers: the roles row (RATEE / RATER / APPROVING AUTHORITY) at
 *     the sheet's end, with each signer's name a couple of rows above in
 *     the same column — signers().
 *
 * A .xlsx is a zip of XML files (Office Open XML). The workbook is opened
 * with PHP's native ZipArchive when the zip extension is loaded, and falls
 * back to a small pure-PHP zip reader (central-directory parse + gzinflate)
 * on PHP builds without it — e.g. the XAMPP web-server PHP, which ships
 * with php_zip disabled. No external spreadsheet package is installed.
 */
class OpcrfSpreadsheet
{
    /**
     * Shared strings from xl/sharedStrings.xml, indexed in file order.
     *
     * @var array<int, string>
     */
    private array $sharedStrings = [];

    /**
     * Sheet cell values keyed by reference ("B4"), with shared strings and
     * inline strings already resolved to plain text.
     *
     * @var array<string, string>
     */
    private array $cells = [];

    public function __construct(string $filePath)
    {
        $this->load($filePath);
    }

    /**
     * Analyze the uploaded workbook into the fields the submission flow
     * needs.
     *
     * @return array{
     *     employee_name: string, position: string, review_period: string,
     *     division_office: string, objectives: string, accomplishments: string,
     *     self_rating: ?float, ratings: array<int, float>, objective_rows: int
     * }
     *
     * @throws RuntimeException when the file is not a readable .xlsx.
     */
    public function analyze(): array
    {
        return [
            'employee_name' => $this->headerValue('Name of Employee'),
            'position' => $this->headerValue('Position/Designation'),
            'review_period' => $this->headerValue('Review Period'),
            'division_office' => $this->headerValue('Strand/Bureau/Center/Service/Region/Division'),
            'objectives' => $this->objectiveColumn('G', 'F'),
            'accomplishments' => $this->objectiveColumn('S'),
            'self_rating' => $this->averageRating(),
            'ratings' => $this->columnRatings(),
            'objective_rows' => count($this->objectiveRowRefs()),
        ];
    }

    /* ------------------------------------------------------------------
     * Sheet layout data — feeds the Excel-style review modal, which
     * mirrors the real template's design (title row, header block,
     * objectives table).
     * ----------------------------------------------------------------- */

    /**
     * The merged title banner in B2, single line.
     */
    public function title(): string
    {
        $title = trim(str_replace(["\r", "\n"], ' ', $this->cells['B2'] ?? ''));

        return preg_replace('/\s+/', ' ', $title) ?? $title;
    }

    /**
     * The header block (rows 4–7, label in B, left value band C..M) as
     * label/value pairs, in template order — the raw cells the Excel
     * sheet shows on top.
     *
     * @return array<int, array{ref: string, label: string, value: string}>
     */
    public function headerCells(): array
    {
        $pairs = [];

        foreach (range(4, 7) as $rowNumber) {
            $label = $this->cells['B'.$rowNumber] ?? '';

            if ($label === '') {
                continue;
            }

            $pairs[] = [
                'ref' => 'B'.$rowNumber,
                'label' => rtrim(str_replace("\n", ' ', $label)),
                'value' => $this->firstFilledToRight('C', $rowNumber),
            ];
        }

        return $pairs;
    }

    /**
     * One entry per objective block (rows 14+), in template order, with
     * everything the Excel-style table shows for that block:
     * objectives (F/G merged band), timeline (H), and the per-criteria
     * rows — criteria label (L), accomplishments (S) and rating (T).
     *
     * @return array<int, array{
     *     row: int, objectives: string, timeline: string,
     *     criteria: array<int, array{label: string, accomplishments: string, rating: ?float}>
     * }>
     */
    public function objectiveEntries(): array
    {
        $banners = $this->partBannerRows();

        if ($banners === []) {
            return $this->entriesForRange(14, $this->lastUsedRow() + 1);
        }

        $entries = [];

        foreach ($banners as $index => $banner) {
            $endRow = $banners[$index + 1]['row'] ?? ($this->lastUsedRow() + 1);

            foreach ($this->entriesForRange($banner['row'] + 1, $endRow) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The workbook's OPCRF parts (Part I-A, I-B, I-C), in sheet order.
     * Each part carries its banner title/note, its objective entries,
     * and its own "Part … Total Score" row value (null while the
     * template's formula still shows an Excel error like #DIV/0!).
     *
     * Templates without part banners (plain test fixtures, older
     * versions) come back as a single untitled part without a total row.
     *
     * @return array<int, array{key: string, title: string, note: string, entries: array<int, array{row: int, objectives: string, timeline: string, criteria: array<int, array{label: string, accomplishments: string, rating: ?float}>}>, total_score: ?float, has_total_row: bool}>
     */
    public function parts(): array
    {
        $banners = $this->partBannerRows();

        if ($banners === []) {
            return [[
                'key' => '',
                'title' => '',
                'note' => '',
                'entries' => $this->entriesForRange(14, $this->lastUsedRow() + 1),
                'total_score' => null,
                'has_total_row' => false,
            ]];
        }

        $parts = [];

        foreach ($banners as $index => $banner) {
            $endRow = $banners[$index + 1]['row'] ?? ($this->lastUsedRow() + 1);

            $parts[] = [
                'key' => $banner['key'],
                'title' => $banner['title'],
                'note' => $banner['note'],
                'entries' => $this->entriesForRange($banner['row'] + 1, $endRow),
                'total_score' => $this->totalScoreBetween($banner['row'] + 1, $endRow),
                'has_total_row' => true,
            ];
        }

        return $parts;
    }

    /**
     * The signer block at the sheet's end: one row of role labels
     * (RATEE, RATER, APPROVING AUTHORITY) with each signer's name a
     * couple of rows above in the same column — the lines signed after
     * the review. Signature-line placeholders and stray numeric cells
     * (a computed score landing in a name column) never read as names.
     * Empty when the template has no such block.
     *
     * @return array<int, array{ref: string, role: string, name: string}>
     */
    public function signers(): array
    {
        $rolesRow = null;

        foreach ($this->cells as $ref => $value) {
            if ($value !== '' && $this->normalizeLabel($value) === 'ratee') {
                $rolesRow = (int) substr($ref, 1);

                break;
            }
        }

        if ($rolesRow === null) {
            return [];
        }

        $signers = [];

        foreach ($this->cells as $ref => $value) {
            if ($value === '' || (int) substr($ref, 1) !== $rolesRow) {
                continue;
            }

            $column = preg_replace('/\d+/', '', $ref);

            $signers[] = [
                'ref' => $ref,
                'role' => rtrim(str_replace("\n", ' ', $value)),
                'name' => $this->nameAbove($column, $rolesRow),
            ];
        }

        usort($signers, fn (array $a, array $b): int => $this->columnIndex(
            preg_replace('/\d+/', '', $a['ref'])
        ) <=> $this->columnIndex(preg_replace('/\d+/', '', $b['ref'])));

        return $signers;
    }

    /**
     * The evaluator block (rows 4–7, right side: labels in column N,
     * values merged across O..V) as label/value pairs — "Evaluator:",
     * "Position:", "Approving Authority:", "Date of Review:". Shown in
     * the review modal beside the staff member's own header block.
     *
     * Rows whose label cell is blank but whose value is filled fall back
     * to the template's standard label for that row, so a name typed
     * into O4 still reads as "Evaluator: …". Rows with no value at all
     * are skipped.
     *
     * @return array<int, array{ref: string, label: string, value: string}>
     */
    public function evaluatorCells(): array
    {
        $fallbackLabels = [
            4 => 'Evaluator:',
            5 => 'Position:',
            6 => 'Approving Authority:',
            7 => 'Date of Review:',
        ];

        $pairs = [];

        foreach (range(4, 7) as $rowNumber) {
            $label = rtrim(str_replace("\n", ' ', $this->cells['N'.$rowNumber] ?? ''));
            $value = $this->firstFilledToRight('O', $rowNumber, 'V');

            if ($value === '') {
                continue;
            }

            $pairs[] = [
                'ref' => 'N'.$rowNumber,
                'label' => $label !== '' ? $label : $fallbackLabels[$rowNumber],
                'value' => $value,
            ];
        }

        return $pairs;
    }

    /* ------------------------------------------------------------------
     * Workbook loading
     * ----------------------------------------------------------------- */

    /**
     * Parse the workbook's first sheet + shared strings into $cells.
     */
    private function load(string $filePath): void
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('The uploaded file could not be found.');
        }

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();

            if ($zip->open($filePath) !== true) {
                throw new RuntimeException('The file is not a valid .xlsx workbook.');
            }

            try {
                $this->sharedStrings = $this->readSharedStrings(
                    fn (string $name): string|false => $zip->getFromName($name)
                );
                $this->cells = $this->readFirstSheet(
                    fn (string $name): string|false => $zip->getFromName($name)
                );
            } finally {
                $zip->close();
            }

            return;
        }

        // PHP build without the zip extension (e.g. XAMPP's web-server
        // PHP): parse the zip container in pure PHP instead of crashing
        // with "Class ZipArchive not found".
        $contents = (new PurePhpZipReader($filePath))->readAll();

        $this->sharedStrings = $this->readSharedStrings(
            fn (string $name): string|false => $contents[$name] ?? false
        );
        $this->cells = $this->readFirstSheet(
            fn (string $name): string|false => $contents[$name] ?? false
        );
    }

    /**
     * @param  callable(string): string|false  $readEntry
     * @return array<int, string>
     */
    private function readSharedStrings(callable $readEntry): array
    {
        $xml = $readEntry('xl/sharedStrings.xml');

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

            // Rich-text runs (r/t) as well as plain (t) nodes.
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
     * @param  callable(string): string|false  $readEntry
     * @return array<string, string>
     */
    private function readFirstSheet(callable $readEntry): array
    {
        $xml = $readEntry('xl/worksheets/sheet1.xml');

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
     * Template-layout extraction
     * ----------------------------------------------------------------- */

    /**
     * The value for a header row: the row whose first cell matches the
     * given label, taking the first non-empty cell to its right.
     */
    private function headerValue(string $label): string
    {
        foreach (range(4, 7) as $rowNumber) {
            $labelCell = $this->cells['B'.$rowNumber] ?? '';

            if ($this->labelMatches($labelCell, $label)) {
                // Start the scan one column past the label cell (C), so the
                // label itself is never returned as the value.
                return $this->firstFilledToRight('C', $rowNumber);
            }
        }

        return '';
    }

    /**
     * Tolerant label comparison: strips punctuation/case/whitespace, so
     * "Name of Employee: " matches "NAME OF EMPLOYEE".
     */
    private function labelMatches(string $cellValue, string $label): bool
    {
        $needle = $this->normalizeLabel($label);

        return $needle !== '' && str_starts_with($this->normalizeLabel($cellValue), $needle);
    }

    /**
     * Normalized label text: alphanumerics only, lowercased — so layout
     * labels compare tolerantly across case, punctuation and line wraps.
     */
    private function normalizeLabel(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    /**
     * First non-empty cell in the row, scanning rightwards from
     * $startColumn up to (and including) $endColumn.
     *
     * The scan must stay inside the left value band (C..M): columns N..V
     * belong to the template's evaluator block ("Position:", "Approving
     * Authority:", "Evaluator:", "Date of Review:" labels in N, values
     * merged across O..V). The untouched template ships with the
     * evaluator's own name already filled into O4, so scanning past M
     * would return that as the employee's (empty) name.
     */
    private function firstFilledToRight(string $startColumn, int $rowNumber, string $endColumn = 'M'): string
    {
        $startIndex = $this->columnIndex($startColumn);
        $endIndex = min($this->columnIndex($endColumn), $startIndex + 29);

        for ($index = $startIndex; $index <= $endIndex; $index++) {
            $value = $this->cells[$this->columnName($index).$rowNumber] ?? '';

            // Skip label-looking cells for good measure: anything ending
            // in a colon is a label, never a typed value.
            if ($value !== '' && ! str_ends_with($value, ':')) {
                return $value;
            }
        }

        return '';
    }

    /**
     * All objective rows (rows 14+, where column F "Statement of
     * Objectives" is filled), as cell references like "F16".
     *
     * F is vertically merged across each objective's criteria block, so
     * its value only exists on the block's first row — every F-filled row
     * is exactly one objective. (Criteria labels in column L may sit on
     * that same row and do not disqualify it.) The F13 header cell
     * "Objectives (based on Office Functions)" and the part tables'
     * repeated F-column "Objectives" header bands are not objectives.
     *
     * @return array<int, string>
     */
    private function objectiveRowRefs(): array
    {
        $refs = [];

        foreach ($this->cells as $ref => $value) {
            if ($value === '' || ! str_starts_with($ref, 'F')) {
                continue;
            }

            $rowNumber = (int) substr($ref, 1);

            if ($rowNumber < 14) {
                continue; // header band ends at row 13
            }

            // Part-table header bands repeat the column caption in F.
            if ($this->normalizeLabel($value) === 'objectives') {
                continue;
            }

            $refs[] = $ref;
        }

        ksort($refs, SORT_NATURAL);

        return $refs;
    }

    /**
     * The criteria rows (column L "Performance Measure") belonging to the
     * objective block starting at $startRow — every filled L row from the
     * block's first row down to just before the next objective block.
     *
     * @return array<int, string>
     */
    private function criteriaRowRefs(int $startRow, ?int $endRow = null): array
    {
        $refs = [];
        $endRow = $endRow ?? $this->nextObjectiveRow($startRow);

        foreach (range($startRow, $endRow - 1) as $rowNumber) {
            $value = $this->cells['L'.$rowNumber] ?? '';

            if ($value !== '') {
                $refs[] = 'L'.$rowNumber;
            }
        }

        return $refs;
    }

    /**
     * The first row of the objective block after $startRow (blocks are
     * sparse — the next F-filled row), or one past the last used row.
     */
    private function nextObjectiveRow(int $startRow): int
    {
        $maxRow = 0;

        foreach ($this->cells as $ref => $value) {
            if ($value === '') {
                continue;
            }

            $rowNumber = (int) substr($ref, 1);
            $maxRow = max($maxRow, $rowNumber);
        }

        foreach ($this->objectiveRowRefs() as $ref) {
            $rowNumber = (int) substr($ref, 1);

            if ($rowNumber > $startRow) {
                return $rowNumber;
            }
        }

        return $maxRow + 1;
    }

    /**
     * One newline-joined text blob for the objectives/accomplishments
     * table, only the objective rows. The objectives live in a merged
     * F..G band in the real template — column G holds the typed objective
     * when present, falling back to F — so $column is tried first and
     * $fallbackColumn second, per row.
     */
    private function objectiveColumn(string $column, ?string $fallbackColumn = null): string
    {
        $lines = [];

        foreach ($this->objectiveRowRefs() as $ref) {
            $rowNumber = substr($ref, 1);
            $value = $this->cells[$column.$rowNumber] ?? '';

            if ($value === '' && $fallbackColumn !== null) {
                $value = $this->cells[$fallbackColumn.$rowNumber] ?? '';
            }

            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The "PART I-x: …" banner rows, in sheet order: B-cell text starting
     * with "Part I-x:" (case-insensitive, colon required — "Part I-A
     * Total Score" rows are score rows, not banners), carrying a key
     * ("I-A"), a title ("COMMITMENT TO ORGANIZATIONAL OUTCOMES (60%)")
     * and the explanatory note that follows after a line break in the
     * same cell. Part I-A's banner sits at row 10, above the objectives
     * band, so every row from the title down is scanned.
     *
     * @return array<int, array{row: int, key: string, title: string, note: string}>
     */
    private function partBannerRows(): array
    {
        $banners = [];

        foreach ($this->cells as $ref => $value) {
            if ($value === '' || ! str_starts_with($ref, 'B')) {
                continue;
            }

            $rowNumber = (int) substr($ref, 1);

            if ($rowNumber < 3 || ! preg_match('/^part\s+(i-[a-z])\s*:/i', $value, $m)) {
                continue;
            }

            $rest = trim(preg_replace('/^part\s+i-[a-z]\s*:\s*/i', '', $value) ?? '');
            [$title, $note] = array_pad(explode("\n", $rest, 2), 2, '');

            $banners[] = [
                'row' => $rowNumber,
                'key' => strtoupper($m[1]),
                'title' => trim(preg_replace('/\s+/', ' ', $title) ?? ''),
                'note' => trim(preg_replace('/\s+/', ' ', $note) ?? ''),
            ];
        }

        usort($banners, fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return $banners;
    }

    /**
     * The objective entries whose F-cells fall inside [$startRow,
     * $endRow), each carrying the part's Timeline column (H — except
     * Part I-C's layout, which puts Timeline in J; detected per entry
     * from whichever of H/J holds text on the block's first row).
     *
     * @return array<int, array{row: int, objectives: string, timeline: string, criteria: array<int, array{label: string, accomplishments: string, rating: ?float}>}>
     */
    private function entriesForRange(int $startRow, int $endRow): array
    {
        $entries = [];

        foreach ($this->objectiveRowRefs() as $ref) {
            $rowNumber = (int) substr($ref, 1);

            if ($rowNumber < $startRow || $rowNumber >= $endRow) {
                continue;
            }

            $objectives = $this->cells['G'.$rowNumber] ?? '';

            if ($objectives === '') {
                $objectives = $this->cells['F'.$rowNumber] ?? '';
            }

            $criteria = [];

            foreach ($this->criteriaRowRefs($rowNumber, $endRow) as $criteriaRef) {
                $criteriaRow = (int) substr($criteriaRef, 1);

                $criteria[] = [
                    'label' => $this->cells['L'.$criteriaRow] ?? '',
                    'accomplishments' => $this->cells['S'.$criteriaRow] ?? '',
                    'rating' => $this->mixedToFloat($this->cells['T'.$criteriaRow] ?? ''),
                ];
            }

            // Part I-C keeps "Timeline" in column J, not H. Only trust a
            // J fallback when it isn't a number — I-A rows use J for the
            // numeric performance-target value.
            $timeline = $this->cells['H'.$rowNumber] ?? '';

            if ($timeline === '') {
                $candidate = $this->cells['J'.$rowNumber] ?? '';
                $timeline = is_numeric($candidate) ? '' : $candidate;
            }

            $entries[] = [
                'row' => $rowNumber,
                'objectives' => $objectives,
                'timeline' => $timeline,
                'criteria' => $criteria,
            ];
        }

        return $entries;
    }

    /**
     * The "Part … Total Score" value inside [$startRow, $endRow): the
     * numeric value in column V on the row whose column B starts with
     * "Part" and mentions "Total Score". Excel-error leftovers
     * (#DIV/0!) read as null.
     */
    private function totalScoreBetween(int $startRow, int $endRow): ?float
    {
        foreach ($this->cells as $ref => $value) {
            if ($value === '' || ! str_starts_with($ref, 'B')) {
                continue;
            }

            $rowNumber = (int) substr($ref, 1);

            if ($rowNumber < $startRow || $rowNumber >= $endRow) {
                continue;
            }

            $normalized = $this->normalizeLabel($value);

            if (! str_starts_with($normalized, 'part') || ! str_contains($normalized, 'totalscore')) {
                continue;
            }

            $total = $this->mixedToFloat($this->cells['V'.$rowNumber] ?? '');

            return $total !== null && $total >= 0 ? $total : null;
        }

        return null;
    }

    /**
     * The signer name typed above a role label: the nearest non-empty,
     * plausible-name cell in $column within a few rows above $rolesRow.
     * Signature-line dashes/underscores and stray computed numbers are
     * not names.
     */
    private function nameAbove(string $column, int $rolesRow): string
    {
        for ($rowNumber = $rolesRow - 1; $rowNumber >= max(1, $rolesRow - 4); $rowNumber--) {
            $value = trim($this->cells[$column.$rowNumber] ?? '');

            if ($value === '') {
                continue;
            }

            $looksLikeName = preg_match('/[a-z]/i', $value) === 1
                && preg_match('/[\p{L}]\s+\p{L}/u', $value) === 1;

            return $looksLikeName ? $value : '';
        }

        return '';
    }

    /**
     * One past the last used row on the sheet.
     */
    private function lastUsedRow(): int
    {
        $maxRow = 0;

        foreach ($this->cells as $ref => $value) {
            if ($value === '') {
                continue;
            }

            $maxRow = max($maxRow, (int) substr($ref, 1));
        }

        return $maxRow;
    }

    /**
     * Numeric ratings from column T (rows 14+). T holds the rating for
     * each criteria row (Quality/Efficiency/Timeliness) of an objective
     * block, so the average over all of them matches the template's own
     * U-column block averages.
     *
     * @return array<int, float>
     */
    private function columnRatings(): array
    {
        $ratings = [];

        foreach ($this->cells as $ref => $value) {
            if (! str_starts_with($ref, 'T')) {
                continue;
            }

            $rowNumber = (int) substr($ref, 1);

            if ($rowNumber < 14) {
                continue;
            }

            $rating = $this->mixedToFloat($value);

            if ($rating !== null) {
                $ratings[] = $rating;
            }
        }

        return $ratings;
    }

    /**
     * The overall self rating: average of column T ratings (the template's
     * own U column is a per-block average of Q/E/T sub-ratings).
     */
    private function averageRating(): ?float
    {
        $ratings = $this->columnRatings();

        if ($ratings === []) {
            return null;
        }

        return round(array_sum($ratings) / count($ratings), 2);
    }

    /**
     * Excel stores numbers either as numeric cells ("5") or, when a cell
     * was touched by a formula/format pass, as text ("5 " with float
     * noise like "4.666666666666667"). Accept both.
     */
    private function mixedToFloat(string $value): ?float
    {
        $value = trim($value);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
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

    private function columnName(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }
}

