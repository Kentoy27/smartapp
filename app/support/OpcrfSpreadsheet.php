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
 * The OTHER TABS of the official template are parsed too (sheetNames(),
 * partTwo(), partThree(), partFour(), rawSheet()):
 *
 *   - PART II — two competency sections (II-A Leadership, II-B Core
 *     Behavioural): group banners in column B, one numbered indicator
 *     line per row in column C, each group's average in column J and its
 *     "Part II-x Total Score: Weighted Average …" row.
 *
 *   - PART III — the summary table: final performance components
 *     (PART I A/B/C, PART II A/B) with their weight allocation and
 *     obtained score, the RPMS adjectival rating, and the
 *     Ratee-Rater agreement block (employee/superior names).
 *
 *   - PART IV — Part IV-A the Office Improvement Plan (gap analysis →
 *     improvement area → objective → intervention → timeline →
 *     resources rows) and Part IV-B the Individual Development Plan
 *     (strengths → improvement needs → learning objective →
 *     developmental intervention → timeline → resources rows), each
 *     with its Feedback line.
 *
 * Tabs that do not match any of those layouts — a workbook the school
 * added a notes tab to — are exposed by name via sheetNames() and their
 * cells via rawSheet(), so "all of the contents" stays true for any
 * shape of file.
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
     * The shipped Part I layout's column letters, used whenever a part has
     * no header band of its own to read (plain fixtures, older templates)
     * and for the fields every part shares.
     *
     * @var array<string, string|array<int, string>>
     */
    private const SHIPPED_COLUMNS = [
        'kra' => 'B',
        'attribution' => ['C', 'D', 'E'],
        'objective' => 'G',
        'objective_fallback' => 'F',
        'timeline' => 'H',
        'weight' => 'I',
        'target_value' => 'J',
        'target_description' => 'K',
        'measure' => 'L',
        'scale' => ['M', 'N', 'O', 'P', 'Q'],
        'movs' => 'R',
        'accomplishments' => 'S',
        'rating' => 'T',
        'average' => 'U',
        'weighted_average' => 'V',
    ];

    /**
     * Shared strings from xl/sharedStrings.xml, indexed in file order.
     *
     * @var array<int, string>
     */
    private array $sharedStrings = [];

    /**
     * Every sheet's cell values, keyed by tab name ("PART I (CY 2025 &
     * SY2025-2026)"), each an array of cell values keyed by reference
     * ("B4") with shared strings and inline strings already resolved to
     * plain text.
     *
     * @var array<string, array<string, string>>
     */
    private array $sheets = [];

    /**
     * The first sheet's tab name — the one the layout accessors read.
     */
    private string $firstSheetName = '';

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
     * The office's Statement of Purpose (row 8): the label in column B and
     * the narrative typed into the merged value band. It is one of the
     * longest pieces of prose in the whole form and used to be dropped
     * entirely, so the review never showed what the office committed to.
     *
     * @return array{ref: string, label: string, value: string}
     */
    public function purposeStatement(): array
    {
        $label = rtrim(str_replace("\n", ' ', $this->cells['B8'] ?? ''));

        return [
            'ref' => 'B8',
            'label' => $label,
            'value' => $this->firstFilledToRight('F', 8),
        ];
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
     * @return array<int, array{key: string, title: string, note: string, entries: array<int, array{row: int, objectives: string, timeline: string, criteria: array<int, array{label: string, accomplishments: string, rating: ?float}>}>, total_score: ?float, has_total_row: bool, captions: array<string, string>, bands: array{planning: string, evaluation: string}}>
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
                'captions' => [],
                'bands' => ['planning' => '', 'evaluation' => ''],
            ]];
        }

        $parts = [];

        foreach ($banners as $index => $banner) {
            $endRow = $banners[$index + 1]['row'] ?? ($this->lastUsedRow() + 1);
            $startRow = $banner['row'] + 1;
            $layout = $this->partLayout($startRow, $endRow);

            $parts[] = [
                'key' => $banner['key'],
                'title' => $banner['title'],
                'note' => $banner['note'],
                'entries' => $this->entriesForRange($startRow, $endRow),
                'total_score' => $this->totalScoreBetween($startRow, $endRow),
                'has_total_row' => true,
                // The part's own wording for each field, so the review can
                // label its blocks exactly as the form does.
                'captions' => $layout['captions'] ?? [],
                'bands' => $layout['bands'] ?? ['planning' => '', 'evaluation' => ''],
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
     * The open ZipArchive handle (when PHP's zip extension is loaded), so
     * every tab's part can be fetched without re-opening the container.
     */
    private ?\ZipArchive $zip = null;

    /**
     * The pure-PHP fallback's unpacked package parts (when the zip
     * extension is unavailable).
     *
     * @var array<string, string>|null
     */
    private ?array $zipContents = null;

    /**
     * Parse the workbook's first sheet + shared strings into $cells.
     */
    private function load(string $filePath): void
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('The uploaded file could not be found.');
        }

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive;

            if ($zip->open($filePath) !== true) {
                throw new RuntimeException('The file is not a valid .xlsx workbook.');
            }

            $this->zip = $zip;

            try {
                $this->sharedStrings = $this->readSharedStrings(
                    fn (string $name): string|false => $zip->getFromName($name)
                );
                $this->loadSheets(
                    function (array $names): array {
                        $zip = $this->zip;
                        $read = [];

                        foreach ($names as $name) {
                            $read[$name] = $zip->getFromName($name);
                        }

                        return $read;
                    }
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
        $this->zipContents = $contents;

        $this->sharedStrings = $this->readSharedStrings(
            fn (string $name): string|false => $contents[$name] ?? false
        );
        $this->loadSheets(
            function (array $names) use ($contents): array {
                $read = [];

                foreach ($names as $name) {
                    $read[$name] = $contents[$name] ?? false;
                }

                return $read;
            }
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
     * Read every tab of the workbook into $sheets (tab name → cells), in
     * tab order; $cells aliases the first sheet for the layout accessors.
     *
     * @param  callable(array<int, string>): array<int, string|false>  $readEntries
     */
    private function loadSheets(callable $readEntries): void
    {
        // Re-open the container to resolve each tab's relationship target:
        // sheetN.xml file names are a convention, never a guarantee.
        $contents = $this->readAllParts();

        $this->sheets = [];

        foreach ($this->sheetTargets($contents) as $name => $target) {
            $xml = $readEntries([$target])[$target] ?? false;

            if ($xml === false || $xml === '') {
                continue;
            }

            $document = @simplexml_load_string($xml);

            if ($document === false) {
                continue;
            }

            $this->sheets[$name] = $this->readSheetCells($document);
        }

        if ($this->sheets === []) {
            throw new RuntimeException('The workbook has no readable worksheet.');
        }

        $this->firstSheetName = array_key_first($this->sheets);
        $this->cells = $this->sheets[$this->firstSheetName];
    }

    /**
     * Raw package parts by name — via ZipArchive when the reader holds one,
     * or the already-unpacked pure-PHP read.
     *
     * @return array<string, string>
     */
    private function readAllParts(): array
    {
        if ($this->zip instanceof \ZipArchive) {
            $parts = [];

            foreach (range(0, $this->zip->numFiles - 1) as $index) {
                $name = (string) $this->zip->getNameIndex($index);

                if ($name !== '') {
                    $parts[$name] = (string) $this->zip->getFromName($name);
                }
            }

            return $parts;
        }

        return $this->zipContents ?? [];
    }

    /**
     * The workbook's tabs in order, as tab name → worksheet part path,
     * resolved through xl/workbook.xml and its relationships.
     *
     * @param  array<string, string>  $parts
     * @return array<string, string>
     */
    private function sheetTargets(array $parts): array
    {
        $workbook = $parts['xl/workbook.xml'] ?? '';
        $rels = $parts['xl/_rels/workbook.xml.rels'] ?? '';

        if ($workbook === '') {
            // No workbook manifest at all: the first-sheet convention the
            // reader has always used.
            return ($parts['xl/worksheets/sheet1.xml'] ?? '') !== ''
                ? ['Sheet1' => 'xl/worksheets/sheet1.xml']
                : [];
        }

        if (! preg_match('/<sheets>(.*?)<\/sheets>/s', $workbook, $block)) {
            return [];
        }

        $targets = [];
        $position = 0;

        if (preg_match_all('/<sheet\b[^>]*>/u', $block[0], $elements)) {
            foreach ($elements[0] as $raw) {
                $position++;

                preg_match('/\bname="([^"]*)"/u', $raw, $name);
                preg_match('/\br:id="([^"]*)"/u', $raw, $rid);

                $target = null;

                if (isset($rid[1]) && $rels !== '') {
                    if (preg_match('/<Relationship\s[^>]*\bId="'.preg_quote($rid[1], '/').'"[^>]*\bTarget="([^"]*)"/u', $rels, $m)
                        || preg_match('/<Relationship\s[^>]*\bTarget="([^"]*)"[^>]*\bId="'.preg_quote($rid[1], '/').'"/u', $rels, $m)) {
                        $target = 'xl/'.ltrim($m[1], '/');
                    }
                }

                // A manifest without a matching relationship (or without
                // rels at all — a minimal fixture's shape): fall back to
                // the positional convention, sheet1.xml for tab 1 and so
                // on, which is what Excel writes.
                $target ??= 'xl/worksheets/sheet'.$position.'.xml';

                $decoded = html_entity_decode($name[1] ?? 'Sheet'.$position, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                // A well-formed package lists each tab once; keep the
                // first occurrence and never let a duplicate tab name
                // overwrite an earlier sheet.
                $targets[$decoded] ??= $target;
            }
        }

        return $targets;
    }

    /**
     * One sheet's <c> elements as ref → plain-text value.
     *
     * Hidden rows and hidden columns are skipped: what an Excel user
     * never sees must never reach the analysis. The official template
     * ships a fully-filled example objective block on hidden rows 16–18
     * (timeline "January to December 2024", ratings 5/4/5, the works) —
     * reading it would report content the staff member cannot even
     * display, let alone have filled in.
     *
     * @return array<string, string>
     */
    private function readSheetCells(\SimpleXMLElement $document): array
    {
        $cells = [];

        if (! isset($document->sheetData->row)) {
            return $cells;
        }

        $hiddenColumns = $this->hiddenColumnRanges($document);

        foreach ($document->sheetData->row as $row) {
            if ($this->rowIsHidden($row)) {
                continue;
            }

            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];

                if ($ref === '') {
                    continue;
                }

                if ($hiddenColumns !== [] && $this->cellIsInHiddenColumn($ref, $hiddenColumns)) {
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
     * The sheet's hidden column ranges from <cols>, as inclusive
     * [minIndex, maxIndex] pairs of 1-based column indexes. Excel hides
     * columns with the hidden="1" attribute; a hard width="0" is
     * invisible too and treated the same.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function hiddenColumnRanges(\SimpleXMLElement $document): array
    {
        $ranges = [];

        if (! isset($document->cols->col)) {
            return $ranges;
        }

        foreach ($document->cols->col as $col) {
            $hidden = strtolower((string) ($col['hidden'] ?? ''));
            $width = (string) ($col['width'] ?? '');

            $isHidden = in_array($hidden, ['1', 'true'], true) || $width === '0';

            if (! $isHidden) {
                continue;
            }

            $min = (int) ($col['min'] ?? 0);
            $max = max($min, (int) ($col['max'] ?? 0));

            if ($min > 0) {
                $ranges[] = [$min, $max];
            }
        }

        return $ranges;
    }

    /**
     * Does this <row> carry the hidden attribute ("1" or "true")?
     */
    private function rowIsHidden(\SimpleXMLElement $row): bool
    {
        $hidden = strtolower((string) ($row['hidden'] ?? ''));

        return in_array($hidden, ['1', 'true'], true);
    }

    /**
     * Is this cell reference ("AB12") inside one of the hidden column
     * ranges?
     *
     * @param  array<int, array{0: int, 1: int}>  $ranges
     */
    private function cellIsInHiddenColumn(string $ref, array $ranges): bool
    {
        preg_match('/^[A-Z]+/', $ref, $matches);

        if (($matches[0] ?? '') === '') {
            return false;
        }

        $index = $this->columnIndex($matches[0]);

        foreach ($ranges as [$min, $max]) {
            if ($index >= $min && $index <= $max) {
                return true;
            }
        }

        return false;
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
    private function criteriaRowRefs(int $startRow, ?int $endRow, string $column = 'L'): array
    {
        $refs = [];
        $endRow = $endRow ?? $this->nextObjectiveRow($startRow);

        foreach (range($startRow, $endRow - 1) as $rowNumber) {
            $value = $this->cells[$column.$rowNumber] ?? '';

            if ($value !== '') {
                $refs[] = $column.$rowNumber;
            }
        }

        return $refs;
    }

    /**
     * The Performance Measure column a part's criteria rows live in — the
     * mapped one when the part has a header band, else the shipped L.
     */
    private function measureColumn(?array $map): string
    {
        return (string) ($map['measure'] ?? self::SHIPPED_COLUMNS['measure']);
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
     * $endRow) — the FULL template row, every column the real OPCRF
     * layout carries:
     *
     *   B  Key Result Areas (KRA)          block-level, may be merged
     *   C..E Organizational Outcome        block-level, may be merged
     *      Attribution (GAA / BEDP / Agenda)
     *   F/G Objectives                     block-level
     *   H  Timeline (J on Part I-C)        block-level
     *   I  Weight Allocation               block-level
     *   J/K Performance Targets            block-level (value + description)
     *   R  Means of Verification (MOVs)    block-level
     *   U/V AVERAGE (QET) / WEIGHTED       block-level
     *   L  Performance Measure             per criteria row
     *   M..Q the 5-level Rating Scale      per criteria row (plus any
     *                                      continuation rows beneath it)
     *   S  Actual Accomplishments          per criteria row
     *   T  RATING (Q,E,T)                  per criteria row
     *
     * @return array<int, array{row: int, kra: string, attribution: string, objectives: string, timeline: string, weight: string, target_value: string, target_description: string, movs: string, average: ?float, weighted_average: ?float, criteria: array<int, array{label: string, scale: array<int, array{level: int, text: string}>, accomplishments: string, rating: ?float}>}>
     */
    private function entriesForRange(int $startRow, int $endRow): array
    {
        $entries = [];
        $map = $this->partColumnMap($startRow, $endRow);

        foreach ($this->objectiveRowRefs() as $ref) {
            $rowNumber = (int) substr($ref, 1);

            if ($rowNumber < $startRow || $rowNumber >= $endRow) {
                continue;
            }

            $objectives = $this->cellOf($map, 'objective', $rowNumber);

            if ($objectives === '') {
                $objectives = $this->cellOf($map, 'objective_fallback', $rowNumber);
            }

            $timeline = $this->cellOf($map, 'timeline', $rowNumber);
            $targetValue = $this->cellOf($map, 'target_value', $rowNumber);

            // No header band to read (a plain fixture, an older template):
            // fall back to the shipped layout's column J, which is the
            // Timeline on Part I-C but the numeric Performance Target
            // elsewhere — trust it only when it is not a number.
            if ($map === null) {
                $candidate = $this->cells['J'.$rowNumber] ?? '';

                if (is_numeric($candidate)) {
                    $targetValue = $candidate;
                } elseif ($timeline === '') {
                    $timeline = $candidate;
                }
            }

            $criteria = [];

            // A part can hold several objectives: this block's criteria
            // stop at the NEXT objective's first row (or the part's end).
            $blockEnd = min($this->nextObjectiveRow($rowNumber), $endRow);

            foreach ($this->criteriaRowRefs($rowNumber, $blockEnd, $this->measureColumn($map)) as $criteriaRef) {
                $criteriaRow = (int) substr($criteriaRef, 1);

                $criteria[] = [
                    'label' => $this->cellOf($map, 'measure', $criteriaRow),
                    'scale' => $this->ratingScale($criteriaRow, $blockEnd, $map),
                    'accomplishments' => $this->cellOf($map, 'accomplishments', $criteriaRow),
                    'rating' => $this->mixedToFloat($this->cellOf($map, 'rating', $criteriaRow)),
                ];
            }

            $entries[] = [
                'row' => $rowNumber,
                'kra' => $this->blockCell((string) ($map['kra'] ?? self::SHIPPED_COLUMNS['kra']), $rowNumber, $startRow),
                'attribution' => implode("\n", array_filter(
                    array_map(
                        fn (string $column): string => $this->blockCell($column, $rowNumber, $startRow),
                        $map['attribution'] ?? self::SHIPPED_COLUMNS['attribution']
                    ),
                    fn (string $v): bool => $v !== ''
                )),
                'objectives' => $objectives,
                'timeline' => $timeline,
                'weight' => $this->cellOf($map, 'weight', $rowNumber),
                'target_value' => $targetValue,
                'target_description' => $this->cellOf($map, 'target_description', $rowNumber),
                'movs' => $this->cellOf($map, 'movs', $rowNumber),
                'average' => $this->mixedToFloat($this->cellOf($map, 'average', $rowNumber)),
                'weighted_average' => $this->mixedToFloat($this->cellOf($map, 'weighted_average', $rowNumber)),
                'criteria' => $criteria,
            ];
        }

        return $entries;
    }

    /**
     * The column letters a part's own header band assigns to each field,
     * read from the captions the workbook actually carries (row 13 for
     * Part I-A, 81 for I-B, 117 for I-C) rather than hardcoded.
     *
     * The three parts do NOT share one layout: Part I-C moves Timeline to
     * column J, its Weight Allocation to K, and drops the Performance
     * Targets and AVERAGE/WEIGHTED AVERAGE blocks altogether. Guessing
     * from the letters alone put I-A's performance-target value ("4 (1 per
     * quarter)", "10 ROA") into the Timeline field. Reading the part's own
     * captions removes the guess.
     *
     * Returns null when the part has no recognisable header band, so the
     * caller keeps the shipped layout's fixed columns.
     *
     * @return array<string, mixed>|null
     */
    private function partColumnMap(int $startRow, int $endRow): ?array
    {
        $layout = $this->partLayout($startRow, $endRow);

        return $layout === null ? null : $layout['map'];
    }

    /**
     * Everything a part's own header band tells us: which column holds
     * which field (the map), what that header calls each field (the
     * captions, for display in the template's own wording) and the
     * planning/evaluation band captions above the table. Null when the
     * part has no recognisable header band.
     *
     * @return array{map: array<string, mixed>, captions: array<string, string>, bands: array{planning: string, evaluation: string}}|null
     */
    private function partLayout(int $startRow, int $endRow): ?array
    {
        // The caption band is the handful of rows between the part banner
        // and the FIRST objective row — never the data itself, or a value
        // like the Performance Measure "Timeliness" would be mistaken for
        // the "Timeline" column header.
        $bandEnd = $endRow;

        foreach ($this->objectiveRowRefs() as $ref) {
            $rowNumber = (int) substr($ref, 1);

            if ($rowNumber >= $startRow && $rowNumber < $bandEnd) {
                $bandEnd = $rowNumber;
            }
        }

        $band = []; // column letter => every caption cell it carries

        for ($rowNumber = $startRow; $rowNumber < $bandEnd; $rowNumber++) {
            foreach ($this->cells as $ref => $value) {
                if ($value === '' || (int) substr($ref, 1) !== $rowNumber) {
                    continue;
                }

                $column = preg_replace('/\d+/', '', $ref);

                // N..V belong to the evaluator block on row 4. Column B is
                // kept: it carries Part I-C's "Organizational
                // Effectiveness Area" caption and Part I-A's KRA caption.
                if ($column === '' || str_starts_with($column, 'N')) {
                    continue;
                }

                $band[$column][] = trim(preg_replace('/\s+/', ' ', str_replace("\n", ' ', $value)) ?? '');
            }
        }

        $map = [];
        $labels = [];

        foreach ($band as $column => $texts) {
            foreach ($texts as $caption) {
                $mapped = $this->columnsForCaption($column, $caption, $texts);

                if ($mapped === []) {
                    continue;
                }

                foreach (array_keys($mapped) as $field) {
                    $labels[$field] = $caption;
                }

                $map = array_merge($map, $mapped);

                break; // the first caption this column carries wins
            }
        }

        if (! isset($map['timeline'])) {
            return null;
        }

        // "TO BE ACCOMPLISHED DURING PLANNING" / "TO BE FILLED DURING
        // EVALUATION": the template's own two column-band headings.
        $bands = ['planning' => '', 'evaluation' => ''];

        foreach ($band as $texts) {
            foreach ($texts as $text) {
                if (preg_match('/during planning/i', $text) === 1) {
                    $bands['planning'] = $text;
                } elseif (preg_match('/during evaluation/i', $text) === 1) {
                    $bands['evaluation'] = $text;
                }
            }
        }

        return ['map' => $map, 'captions' => $labels, 'bands' => $bands];
    }

    /**
     * The field → column-letter mapping one header caption implies. A
     * caption that names a pair of columns (Performance Targets = value +
     * description) contributes both.
     *
     * @return array<string, mixed>
     */
    /**
     * The field → column-letter mapping one header caption implies. A
     * caption that names a pair of columns (Performance Targets = value +
     * description) contributes both.
     *
     * @param  array<int, string>  $columnCaptions  every caption cell this column carries, top to bottom
     * @return array<string, mixed>
     */
    private function columnsForCaption(string $column, string $caption, array $columnCaptions = []): array
    {
        $next = $this->columnName($this->columnIndex($column) + 1);

        return match (true) {
            preg_match('/key result area/i', $caption) === 1 => ['kra' => $column],
            // Part I-C names its left-hand column the "Organizational
            // Effectiveness Area" — the same block-level slot as a KRA,
            // and read the same way.
            preg_match('/organizational effectiveness area/i', $caption) === 1 => ['kra' => $column, 'area' => $column],
            preg_match('/organizational outcome attribution/i', $caption) === 1 => ['attribution' => [
                $column,
                $next,
                $this->columnName($this->columnIndex($column) + 2),
            ]],
            preg_match('/^objectives\b/i', $caption) === 1 => ['objective' => $column, 'objective_fallback' => $next],
            preg_match('/^timeline\s*$/i', $caption) === 1 => ['timeline' => $column],
            preg_match('/weight allocation/i', $caption) === 1 => ['weight' => $column],
            preg_match('/performance targets?/i', $caption) === 1 => $this->targetColumns($column, $next, $columnCaptions),
            preg_match('/performance measure/i', $caption) === 1 => ['measure' => $column],
            preg_match('/rating scale/i', $caption) === 1 => ['scale' => array_map(
                fn (int $offset): string => $this->columnName($this->columnIndex($column) + $offset),
                range(0, 4)
            )],
            preg_match('/means of verification/i', $caption) === 1 => ['movs' => $column],
            preg_match('/actual (results|accomplishments)/i', $caption) === 1 => ['accomplishments' => $column],
            preg_match('/rating \(q/i', $caption) === 1 => ['rating' => $column],
            preg_match('/^average/i', $caption) === 1 => ['average' => $column],
            preg_match('/weighted average/i', $caption) === 1 => ['weighted_average' => $column],
            default => [],
        };
    }

    /**
     * The Performance Targets block spans two columns — a numerical value
     * and its description. The sub-header row underneath names which is
     * which ("Value (numerical, statistical, trend)" / "Description
     * (expected outcome/ output/service)"); without it, the pair reads
     * left to right, which is how the shipped template orders them.
     *
     * @return array{target_value: string, target_description: string}
     */
    private function targetColumns(string $column, string $next, array $columnCaptions): array
    {
        foreach ($columnCaptions as $text) {
            if (preg_match('/^description/i', $text) === 1) {
                return ['target_value' => $next, 'target_description' => $column];
            }
        }

        return ['target_value' => $column, 'target_description' => $next];
    }

    /**
     * A cell read through the part's column map.
     *
     * A map is authoritative: when the part's own header band does not
     * name a field, that part has no such field (Part I-C carries no
     * Performance Targets or AVERAGE columns) and the cell is empty. Only
     * a part with no header band at all falls back to the shipped layout's
     * fixed letters.
     */
    private function cellOf(?array $map, string $field, int $rowNumber): string
    {
        $column = $map === null
            ? (self::SHIPPED_COLUMNS[$field] ?? '')
            : ($map[$field] ?? '');

        if (! is_string($column) || $column === '') {
            return '';
        }

        return trim($this->cells[$column.$rowNumber] ?? '');
    }

    /**
     * A block-level cell (KRA, attribution columns) read at $rowNumber:
     * the template merges these cells down across an objective's rows, so
     * the text sits on the block's first row. When the row itself is
     * empty (a second objective sharing the same merged KRA), the nearest
     * non-empty value ABOVE it inside the part wins — that is what Excel
     * displays spanning down. Column-header captions ("Key Result Areas
     * (KRA)", "GAA Programs/ Subprograms", …) never read as values.
     */
    private function blockCell(string $column, int $rowNumber, int $floor): string
    {
        $value = trim($this->cells[$column.$rowNumber] ?? '');

        if ($value !== '' && ! $this->isColumnCaption($value)) {
            return $value;
        }

        for ($above = $rowNumber - 1; $above >= max(16, $floor); $above--) {
            $value = trim($this->cells[$column.$above] ?? '');

            if ($value !== '' && ! $this->isColumnCaption($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Is this text one of the objectives table's column captions (which
     * sit directly above the data band) rather than a typed value?
     */
    private function isColumnCaption(string $value): bool
    {
        $normalized = $this->normalizeLabel($value);

        foreach (
            [
                'keyresultareaskra',
                'kra',
                'organizationaloutcomeattribution',
                'gaaprograms',
                'bedppillars',
                'currentadministrationagenda',
                'refertocitizenscharter',
            ] as $caption
        ) {
            if (str_starts_with($normalized, $caption)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One criteria row's 5-level Rating Scale (columns M..Q hold the
     * descriptors for 5 Outstanding down to 1 Poor) plus any continuation
     * rows beneath it (extra scale bands typed without a Performance
     * Measure label belong to the criteria above).
     *
     * @return array<int, array{level: int, text: string}>
     */
    private function ratingScale(int $criteriaRow, int $blockEndRow, ?array $map = null): array
    {
        $columns = $map['scale'] ?? self::SHIPPED_COLUMNS['scale'];

        // The five descriptors run 5 (Outstanding) down to 1 (Poor).
        $levels = [];

        foreach (array_values($columns) as $offset => $column) {
            $levels[$column] = 5 - $offset;
        }

        $scale = [];
        $row = $criteriaRow;

        while ($row < $blockEndRow) {
            // A row carrying the next Performance Measure label starts
            // the next criteria row — its scale is its own.
            if ($row > $criteriaRow && $this->cellOf($map, 'measure', $row) !== '') {
                break;
            }

            foreach ($levels as $column => $level) {
                $text = trim($this->cells[$column.$row] ?? '');

                if ($text !== '') {
                    $scale[] = ['level' => $level, 'text' => $text];
                }
            }

            $row++;
        }

        return $scale;
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

    /* ------------------------------------------------------------------
     * The other tabs — PART II, PART III, PART IV, and any workbook's
     * remaining sheets (see the class docblock for the layouts).
     * ----------------------------------------------------------------- */

    /**
     * The whole workbook beyond PART I, assembled in one call: the tab
     * list, PART II / III / IV parsed into their layouts, and any
     * non-standard tabs (a notes tab the school added) as raw cell
     * grids — so "all of the contents" stays true for any shape of file.
     *
     * @return array{tabs: array<int, array{name: string, position: int, cells: int}>, part_two: array{sections: array<int, array{key: string, title: string, note: string, groups: array<int, array{label: string, indicators: array<int, array{text: string, rating: ?float}>, average: ?float}>}>, total_rows: array<int, array{key: string, label: string, score: ?float}>, signers: array<int, array{ref: string, role: string, name: string}>}, part_three: array{components: array<int, array{part: string, component: string, weight: string, obtained: ?float}>, agreement: array<int, array{role: string, name: string}>}, part_four: array{office_plan: array<int, array<string, string>>, office_feedback: string, development_plan: array<int, array<string, string>>, development_feedback: string, signers: array<int, array{ref: string, role: string, name: string}>}, extra_sheets: array<int, array{name: string, rows: array<int, array{row: int, cells: array<int, array{ref: string, column: string, value: string}>}}>}
     */
    public function workbook(): array
    {
        $known = ['PART II', 'PART III', 'PART IV'];
        $extraSheets = [];

        foreach ($this->sheetNames() as $tab) {
            // The first tab is the main sheet's home (whatever it is
            // named) — the layout accessors above already cover it.
            if ($tab['position'] === 1) {
                continue;
            }

            $isStandard = false;

            foreach ($known as $part) {
                if (preg_match('/^'.preg_quote($part, '/').'/i', $tab['name'])) {
                    $isStandard = true;

                    break;
                }
            }

            if (! $isStandard) {
                $extraSheets[] = ['name' => $tab['name'], 'rows' => $this->rawSheet($tab['name'])];
            }
        }

        return [
            'tabs' => $this->sheetNames(),
            'part_two' => $this->partTwo(),
            'part_three' => $this->partThree(),
            'part_four' => $this->partFour(),
            'extra_sheets' => $extraSheets,
        ];
    }

    /**
     * Every tab of the workbook in order: name, 1-based position, and
     * non-empty cell count — the review UI's tab picker.
     *
     * @return array<int, array{name: string, position: int, cells: int}>
     */
    public function sheetNames(): array
    {
        $names = [];
        $position = 0;

        foreach ($this->sheets as $name => $cells) {
            $names[] = [
                'name' => $name,
                'position' => ++$position,
                'cells' => count(array_filter($cells, fn (string $v): bool => $v !== '')),
            ];
        }

        return $names;
    }

    /**
     * PART II's competency sections (II-A Leadership, II-B Core
     * Behavioural): each group banner (column B) with its numbered
     * indicator lines (column C) and per-indicator ratings (column I),
     * the group's computed average (column J), the section's weighted
     * total row, and the signers typed on the tab. Sections without
     * those layouts come back empty, so a Part II-shaped sheet yields
     * nothing here.
     *
     * @return array{sections: array<int, array{key: string, title: string, note: string, groups: array<int, array{label: string, indicators: array<int, array{text: string, rating: ?float, remarks: string}>, average: ?float}>}>, total_rows: array<int, array{key: string, label: string, score: ?float}>, signers: array<int, array{ref: string, role: string, name: string}>, scale: array{title: string, levels: array<int, array{number: string, adjectival: string, definition: string}>}}
     */
    public function partTwo(): array
    {
        $cells = $this->findSheet(function (string $name): bool {
            return preg_match('/part\s*ii/i', $name) === 1;
        });

        if ($cells === null) {
            return ['sections' => [], 'total_rows' => [], 'signers' => [], 'scale' => ['title' => '', 'levels' => []]];
        }

        $banners = [];

        foreach ($cells as $ref => $value) {
            if ($value === '' || ! str_starts_with($ref, 'B')) {
                continue;
            }

            $rowNumber = (int) substr($ref, 1);

            if ($rowNumber < 2 || ! preg_match('/^part\s+(ii-[a-z])\s*:/i', $value, $m)) {
                continue;
            }

            $rest = trim(preg_replace('/^part\s+ii-[a-z]\s*:\s*/i', '', $value) ?? '');
            [$title, $note] = array_pad(explode("\n", $rest, 2), 2, '');

            $banners[] = [
                'row' => $rowNumber,
                'key' => strtoupper($m[1]),
                'title' => trim(preg_replace('/\s+/', ' ', $title) ?? ''),
                'note' => trim(preg_replace('/\s+/', ' ', $note) ?? ''),
            ];
        }

        usort($banners, fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        $sections = [];
        $totalRows = [];

        foreach ($banners as $index => $banner) {
            $endRow = $banners[$index + 1]['row'] ?? ($this->lastUsedRowOf($cells) + 1);

            $sections[] = [
                'key' => $banner['key'],
                'title' => $banner['title'],
                'note' => $banner['note'],
                'groups' => $this->partTwoGroups($cells, $banner['row'] + 1, $endRow),
            ];

            $total = $this->partTwoTotalRow($cells, $banner['row'] + 1, $endRow);

            if ($total !== null) {
                $totalRows[] = $total;
            }
        }

        return [
            'sections' => $sections,
            'total_rows' => $totalRows,
            'signers' => $this->signersOf($cells),
            'scale' => $this->partTwoScale($cells),
        ];
    }

    /**
     * The tab's own reference legend — "DepEd Competencies Scale" with a
     * Numerical Rating (5..1), its Adjectival Rating and the Definition
     * behind each level. It is printed beside the competency table in the
     * form and tells a reviewer what a given rating actually means, so the
     * review shows it as a legend instead of dropping it.
     *
     * @param  array<string, string>  $cells
     * @return array{title: string, levels: array<int, array{number: string, adjectival: string, definition: string}>}
     */
    private function partTwoScale(array $cells): array
    {
        $title = '';

        foreach ($cells as $ref => $value) {
            if (preg_match('/deped competencies scale/i', $value) === 1) {
                $title = trim(preg_replace('/\s+/', ' ', $value) ?? '');

                break;
            }
        }

        $levels = [];

        foreach ($cells as $ref => $value) {
            if (! str_starts_with($ref, 'L') || $value === '' || ! is_numeric(trim($value))) {
                continue;
            }

            $rowNumber = (int) substr($ref, 1);
            $column = $this->columnName($this->columnIndex('M'));

            $levels[] = [
                'number' => trim($value),
                'adjectival' => trim($cells[$column.$rowNumber] ?? ''),
                'definition' => trim($cells[$this->columnName($this->columnIndex('N')).$rowNumber] ?? ''),
            ];
        }

        usort($levels, fn (array $a, array $b): int => (int) $b['number'] <=> (int) $a['number']);

        return ['title' => $title, 'levels' => $levels];
    }

    /**
     * PART III's summary tab: the final performance components table
     * (component → weight → obtained score), the RPMS adjectival rating,
     * and the Ratee-Rater agreement names. Empty arrays for sheets
     * without those layouts.
     *
     * @return array{components: array<int, array{part: string, component: string, weight: string, obtained: ?float}>, agreement: array<int, array{role: string, name: string}>, overall: ?float, rating: string, rating_table: array<int, array{range: string, numerical: string, adjectival: string}>, signatures: array<int, array{role: string, signature: string, date: string}>}
     */
    public function partThree(): array
    {
        $cells = $this->findSheet(function (string $name): bool {
            return preg_match('/part\s*iii/i', $name) === 1;
        });

        if ($cells === null) {
            return ['components' => [], 'agreement' => [], 'overall' => null, 'rating' => '', 'rating_table' => [], 'signatures' => []];
        }

        return [
            'components' => $this->partThreeComponents($cells),
            'agreement' => $this->partThreeAgreement($cells),
            'overall' => $this->partThreeOverall($cells),
            'rating' => $this->partThreeRating($cells),
            'rating_table' => $this->partThreeRatingTable($cells),
            'signatures' => $this->partThreeSignatures($cells),
        ];
    }

    /**
     * The form's Overall Score: the single number the component scores add
     * up to (column G on the components table). An Excel error left over
     * from an unrecalculated template reads as no score yet.
     *
     * @param  array<string, string>  $cells
     */
    private function partThreeOverall(array $cells): ?float
    {
        foreach ($this->partThreeComponents($cells) as $component) {
            $rowNumber = null;

            foreach ($cells as $ref => $value) {
                if (str_starts_with($ref, 'D') && trim(str_replace("\n", ' ', $value)) === $component['component']) {
                    $rowNumber = (int) substr($ref, 1);

                    break;
                }
            }

            if ($rowNumber === null) {
                continue;
            }

            $score = $this->mixedToFloat($cells['G'.$rowNumber] ?? '');

            if ($score !== null) {
                return $score;
            }
        }

        return null;
    }

    /**
     * The RPMS adjectival rating typed into column H beside the overall
     * score ("Outstanding", "Very Satisfactory", …).
     *
     * @param  array<string, string>  $cells
     */
    private function partThreeRating(array $cells): string
    {
        foreach (range(6, 20) as $rowNumber) {
            $value = trim($cells['H'.$rowNumber] ?? '');

            // The header row reads "RPMS Rating"; a real rating is a word.
            if ($value !== '' && preg_match('/^(outstanding|very satisfactory|satisfactory|unsatisfactory|poor)$/i', $value) === 1) {
                return $value;
            }
        }

        return '';
    }

    /**
     * The RPMS Rating Table printed beside the summary: the numerical
     * range that maps to each adjectival rating (4.500-5.000 → Outstanding,
     * …). It is the key to reading the score above, so the review shows it.
     *
     * @param  array<string, string>  $cells
     * @return array<int, array{range: string, numerical: string, adjectival: string}>
     */
    private function partThreeRatingTable(array $cells): array
    {
        $rows = [];

        foreach (range(5, 25) as $rowNumber) {
            $range = trim($cells['M'.$rowNumber] ?? '');
            $adjectival = trim($cells['O'.$rowNumber] ?? '');

            // The table's own header row ("Range / Numerical Rating /
            // Adjectival Rating") is not a rating band: a real one is a
            // span like "4.500-5.000".
            if ($range === '' || $adjectival === ''
                || preg_match('/^\d+(?:\.\d+)?\s*-\s*\d+(?:\.\d+)?$/', $range) !== 1) {
                continue;
            }

            $rows[] = [
                'range' => $range,
                'numerical' => trim($cells['N'.$rowNumber] ?? ''),
                'adjectival' => $adjectival,
            ];
        }

        return $rows;
    }

    /**
     * The Ratee-Rater agreement's signature and date lines — the two
     * columns with "Signature:" and "Date:" under the printed names. They
     * are part of what the form asks for, and a reviewer needs to see
     * whether they were filled in.
     *
     * @param  array<string, string>  $cells
     * @return array<int, array{role: string, signature: string, date: string}>
     */
    private function partThreeSignatures(array $cells): array
    {
        $columns = [];

        foreach ($cells as $ref => $value) {
            if (preg_match('/^name of (employee|superior)\s*:/i', $value) !== 1) {
                continue;
            }

            $column = preg_replace('/\d+/', '', $ref);
            $columns[$column] = preg_match('/^name of employee/i', $value) === 1
                ? 'Employee (Ratee)'
                : 'Superior (Rater)';
        }

        $signatures = [];

        foreach ($columns as $column => $role) {
            $valueAt = function (string $label) use ($cells, $column): string {
                foreach (range(15, 30) as $rowNumber) {
                    $value = trim($cells[$column.$rowNumber] ?? '');

                    if (preg_match('/^'.preg_quote($label, '/').'/i', $value) === 1) {
                        // The typed value, if any, follows the label.
                        return trim(preg_replace('/^.*?:\s*/', '', $value) ?? '');
                    }
                }

                return '';
            };

            $signatures[] = [
                'role' => $role,
                'signature' => $valueAt('Signature'),
                'date' => $valueAt('Date'),
            ];
        }

        return $signatures;
    }

    /**
     * PART IV's improvement plans: the Office Improvement Plan rows
     * (Part IV-A) and Individual Development Plan rows (Part IV-B),
     * each row a labelled-column map, plus the tab's Feedback lines and
     * signers. Empty for sheets without those layouts.
     *
     * @return array{office_plan: array<int, array<string, string>>, office_feedback: string, development_plan: array<int, array<string, string>>, development_feedback: string, signers: array<int, array{ref: string, role: string, name: string}>}
     */
    public function partFour(): array
    {
        $cells = $this->findSheet(function (string $name): bool {
            return preg_match('/part\s*iv/i', $name) === 1;
        });

        if ($cells === null) {
            return ['office_plan' => [], 'office_feedback' => '', 'development_plan' => [], 'development_feedback' => '', 'signers' => [], 'sections' => []];
        }

        $banners = [];

        foreach ($cells as $ref => $value) {
            if ($value === '' || ! str_starts_with($ref, 'C')) {
                continue;
            }

            $rowNumber = (int) substr($ref, 1);

            if ($rowNumber < 2 || ! preg_match('/^part\s+(iv-[ab])\s*:/i', $value, $m)) {
                continue;
            }

            $banners[] = ['row' => $rowNumber, 'key' => strtoupper($m[1])];
        }

        usort($banners, fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        $officePlan = [];
        $developmentPlan = [];
        $officeFeedback = '';
        $developmentFeedback = '';

        foreach ($banners as $index => $banner) {
            $endRow = $banners[$index + 1]['row'] ?? ($this->lastUsedRowOf($cells) + 1);

            if ($banner['key'] === 'IV-A') {
                $officePlan = $this->partFourPlanRows($cells, $banner['row'] + 1, $endRow, [
                    'gap' => 'C', 'area' => 'D', 'objective' => 'E',
                    'intervention' => 'G', 'timeline' => 'I', 'resources' => 'J',
                ]);
                $officeFeedback = $this->feedbackAfter($cells, $banner['row'] + 1, $endRow);
            } elseif ($banner['key'] === 'IV-B') {
                $developmentPlan = $this->partFourPlanRows($cells, $banner['row'] + 1, $endRow, [
                    'gap' => 'C', 'area' => 'D', 'objective' => 'E',
                    'intervention' => 'G', 'timeline' => 'I', 'resources' => 'J',
                ]);
                $developmentFeedback = $this->feedbackAfter($cells, $banner['row'] + 1, $endRow);
            }
        }

        return [
            'office_plan' => $officePlan,
            'office_feedback' => $officeFeedback,
            'development_plan' => $developmentPlan,
            'development_feedback' => $developmentFeedback,
            'signers' => $this->signersOf($cells),
            // Which of the two plans the form actually carries. An unfilled
            // plan is still a section of the form, and the review says so
            // rather than dropping it from the document.
            'sections' => array_column($banners, 'key', 'key'),
        ];
    }

    /**
     * A tab's raw contents as a simple row grid — the escape hatch for
     * any workbook with tabs beyond the official four: every non-empty
     * cell, grouped by row, so nothing a staff member typed is lost.
     *
     * @return array<int, array{row: int, cells: array<int, array{ref: string, column: string, value: string}>}>
     */
    public function rawSheet(string $name): array
    {
        $cells = $this->sheets[$name] ?? null;

        if ($cells === null) {
            return [];
        }

        $rows = [];

        foreach ($cells as $ref => $value) {
            if ($value === '') {
                continue;
            }

            $rowNumber = (int) preg_replace('/\D+/', '', $ref);
            $column = preg_replace('/\d+/', '', $ref) ?: $ref;
            $rows[$rowNumber][] = ['ref' => $ref, 'column' => $column, 'value' => $value];
        }

        ksort($rows);

        return array_map(
            fn (int $row, array $cells): array => ['row' => $row, 'cells' => array_values($cells)],
            array_keys($rows),
            array_values($rows)
        );
    }

    /* ------------------------------------------------------------------
     * Multi-tab sheet lookup and the per-tab layout parsers.
     * ----------------------------------------------------------------- */

    /**
     * The cells of the first sheet whose tab name satisfies $match, or
     * null.
     *
     * @return array<string, string>|null
     */
    private function findSheet(callable $match): ?array
    {
        foreach ($this->sheets as $name => $cells) {
            if ($match($name)) {
                return $cells;
            }
        }

        return null;
    }

    /**
     * PART II's competency groups inside [$startRow, $endRow): group
     * banners are the B cells that are not section banners, total rows,
     * table captions ("Competencies") or the reference scale rows; their
     * indicators are the numbered C lines from the banner row down; the
     * group average is the numeric J value on the banner row.
     *
     * @param  array<string, string>  $cells
     * @return array<int, array{label: string, indicators: array<int, array{text: string, rating: ?float}>, average: ?float}>
     */
    private function partTwoGroups(array $cells, int $startRow, int $endRow): array
    {
        $groups = [];

        foreach (range($startRow, $endRow - 1) as $rowNumber) {
            $label = trim($cells['B'.$rowNumber] ?? '');

            if ($label === '' || preg_match('/^part\s+ii-[a-z]\s*:/i', $label)
                || preg_match('/totalscore/i', $this->normalizeLabel($label))) {
                continue;
            }

            // Table captions ("Competencies") and the reference scale's
            // header/number rows ("Numerical Rating", "5", …) are not
            // competency groups — a real group owns numbered C indicators
            // beneath it.
            $hasIndicator = trim($cells['C'.($rowNumber + 1)] ?? '') !== ''
                || trim($cells['C'.$rowNumber] ?? '') !== '';

            if (! $hasIndicator || preg_match('/^(competenc|numerical|adjectival|definition|\d)/i', $label)) {
                continue;
            }

            $indicators = [];

            // The first indicator shares the banner row in the shipped
            // template's layout; the rest follow beneath it. Each row can
            // carry its own rating in column I.
            foreach (range($rowNumber, $endRow - 1) as $indicatorRow) {
                if ($indicatorRow > $rowNumber && trim($cells['B'.$indicatorRow] ?? '') !== '') {
                    break; // The next group banner ends this group's list.
                }

                $value = trim($cells['C'.$indicatorRow] ?? '');

                if ($value !== '') {
                    $indicators[] = [
                        'text' => $value,
                        'rating' => $this->mixedToFloat($cells['I'.$indicatorRow] ?? ''),
                        // "Remarks/ Observations" (column G) — where the
                        // rater writes about the indicator. It was parsed
                        // nowhere before, so the reviewer's own words were
                        // invisible in the review.
                        'remarks' => trim($cells['G'.$indicatorRow] ?? ''),
                    ];
                }
            }

            if ($indicators === []) {
                continue;
            }

            $groups[] = [
                'label' => $label,
                'indicators' => $indicators,
                'average' => $this->mixedToFloat($cells['J'.$rowNumber] ?? ''),
            ];
        }

        return $groups;
    }

    /**
     * The section's "Part II-x Total Score: Weighted Average …" row.
     *
     * @param  array<string, string>  $cells
     * @return array{key: string, label: string, score: ?float}|null
     */
    private function partTwoTotalRow(array $cells, int $startRow, int $endRow): ?array
    {
        foreach (range($startRow, $endRow - 1) as $rowNumber) {
            $value = trim($cells['B'.$rowNumber] ?? '');

            if ($value === '') {
                continue;
            }

            $normalized = $this->normalizeLabel($value);

            if (str_starts_with($normalized, 'part') && str_contains($normalized, 'totalscore')) {
                return [
                    'key' => '',
                    'label' => rtrim(str_replace("\n", ' ', $value)),
                    'score' => $this->mixedToFloat($cells['J'.$rowNumber] ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * PART III's components table: C holds the PART band ("PART I"), D
     * the component name, E the weight allocation and F the obtained
     * score. Header rows and the RPMS rating table (M..O) are skipped.
     *
     * @param  array<string, string>  $cells
     * @return array<int, array{part: string, component: string, weight: string, obtained: ?float}>
     */
    private function partThreeComponents(array $cells): array
    {
        $components = [];
        $part = '';

        foreach ($cells as $ref => $value) {
            $rowNumber = (int) substr($ref, 1);

            if (! str_starts_with($ref, 'D') || $rowNumber < 4 || $rowNumber > 40) {
                continue;
            }

            $component = rtrim(str_replace("\n", ' ', $value));

            if ($component === '' || preg_match('/rating|numerical|adjectival|range/i', $component)) {
                continue;
            }

            $part = trim($cells['C'.$rowNumber] ?? '') !== ''
                ? rtrim(str_replace("\n", ' ', (string) $cells['C'.$rowNumber]))
                : $part;

            $components[] = [
                'part' => $part,
                'component' => $component,
                'weight' => trim($cells['E'.$rowNumber] ?? ''),
                'obtained' => $this->mixedToFloat($cells['F'.$rowNumber] ?? ''),
            ];
        }

        return $components;
    }

    /**
     * The Ratee-Rater agreement block: the "Name of Employee: …" and
     * "Name of Superior: …" lines with the names they carry.
     *
     * @param  array<string, string>  $cells
     * @return array<int, array{role: string, name: string}>
     */
    private function partThreeAgreement(array $cells): array
    {
        $agreement = [];

        foreach ($cells as $ref => $value) {
            if ($value === '' || ! preg_match('/^name of (employee|superior)\s*:\s*(.*)$/is', $value, $m)) {
                continue;
            }

            $agreement[] = [
                'role' => strtolower($m[1]) === 'employee' ? 'Employee (Ratee)' : 'Superior (Rater)',
                'name' => trim($m[2]),
            ];
        }

        return $agreement;
    }

    /**
     * A PART IV plan's rows inside [$startRow, $endRow): each row maps
     * its labels ($map) to the typed values. The section's "Action Plan"
     * band and column-caption row come first; data rows start after the
     * captions (or after a few rows when a layout has none), and the
     * trailing Feedback line or signer block ends the table.
     *
     * @param  array<string, string>  $cells
     * @param  array<string, string>  $map
     * @return array<int, array<string, string>>
     */
    private function partFourPlanRows(array $cells, int $startRow, int $endRow, array $map): array
    {
        $rows = [];
        $dataStarted = false;
        $scanned = 0;

        foreach (range($startRow, $endRow - 1) as $rowNumber) {
            $scanned++;

            $entry = [];
            $filled = 0;

            foreach ($map as $field => $column) {
                $value = trim($cells[$column.$rowNumber] ?? '');
                $entry[$field] = $value;

                if ($value !== '') {
                    $filled++;
                }
            }

            if ($filled === 0) {
                continue;
            }

            // The Feedback caption and the signer block end the table —
            // neither is a plan row, and nothing after them is.
            foreach ($entry as $value) {
                $normalized = $this->normalizeLabel($value);

                if (str_starts_with($normalized, 'feedback')
                    || in_array($normalized, ['ratee', 'rater', 'approvingauthority'], true)) {
                    return $rows;
                }
            }

            if (! $dataStarted) {
                // The captions row fills most of the plan's columns at
                // once; layouts without one fall back after a few rows.
                if ($filled >= 3 || $scanned > 4) {
                    $dataStarted = true;
                }

                continue;
            }

            $entry['row'] = $rowNumber;
            $rows[] = $entry;
        }

        return $rows;
    }

    /**
     * The "Feedback:" caption's typed text inside [$startRow, $endRow):
     * either the text typed after the colon on the same cell, or the
     * nearest filled cell below it in the same column.
     *
     * @param  array<string, string>  $cells
     */
    private function feedbackAfter(array $cells, int $startRow, int $endRow): string
    {
        foreach (range($startRow, $endRow - 1) as $rowNumber) {
            foreach (['C', 'B'] as $column) {
                $value = trim($cells[$column.$rowNumber] ?? '');

                if ($value !== '' && preg_match('/^feedback\s*:?(.*)$/is', $value, $m)) {
                    $inline = trim($m[1]);

                    if ($inline !== '') {
                        return $inline;
                    }

                    foreach (range($rowNumber + 1, min($rowNumber + 4, $endRow - 1)) as $below) {
                        $typed = trim($cells[$column.$below] ?? '');

                        if ($typed === '' || in_array($this->normalizeLabel($typed), ['ratee', 'rater', 'approvingauthority'], true)) {
                            continue;
                        }

                        return $typed;
                    }
                }
            }
        }

        return '';
    }

    /**
     * The signer block of any sheet: one row of role labels (RATEE,
     * RATER, APPROVING AUTHORITY, …) with each signer's name a few rows
     * above in the same column.
     *
     * @param  array<string, string>  $cells
     * @return array<int, array{ref: string, role: string, name: string}>
     */
    private function signersOf(array $cells): array
    {
        $rolesRow = null;

        foreach ($cells as $ref => $value) {
            if ($value !== '' && $this->normalizeLabel($value) === 'ratee') {
                $rolesRow = (int) substr($ref, 1);

                break;
            }
        }

        if ($rolesRow === null) {
            return [];
        }

        $signers = [];

        foreach ($cells as $ref => $value) {
            if ($value === '' || (int) substr($ref, 1) !== $rolesRow) {
                continue;
            }

            $column = preg_replace('/\d+/', '', $ref);

            $signers[] = [
                'ref' => $ref,
                'role' => rtrim(str_replace("\n", ' ', $value)),
                'name' => $this->nameAboveIn($cells, $column, $rolesRow),
            ];
        }

        usort($signers, fn (array $a, array $b): int => $this->columnIndex(
            preg_replace('/\d+/', '', $a['ref'])
        ) <=> $this->columnIndex(preg_replace('/\d+/', '', $b['ref'])));

        return $signers;
    }

    /**
     * The nearest non-empty plausible-name cell above a role row in one
     * column (see nameAbove() — same rules, any cell map).
     *
     * @param  array<string, string>  $cells
     */
    private function nameAboveIn(array $cells, string $column, int $rolesRow): string
    {
        for ($rowNumber = $rolesRow - 1; $rowNumber >= max(1, $rolesRow - 4); $rowNumber--) {
            $value = trim($cells[$column.$rowNumber] ?? '');

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
     * One past the last used row of a cell map.
     *
     * @param  array<string, string>  $cells
     */
    private function lastUsedRowOf(array $cells): int
    {
        $maxRow = 0;

        foreach ($cells as $ref => $value) {
            if ($value === '') {
                continue;
            }

            $maxRow = max($maxRow, (int) substr($ref, 1));
        }

        return $maxRow;
    }
}
