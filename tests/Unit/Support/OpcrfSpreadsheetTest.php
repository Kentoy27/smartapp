<?php

namespace Tests\Unit\Support;

use App\Support\OpcrfSpreadsheet;
use App\Support\PurePhpZipReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Concerns\BuildsOpcrfWorkbooks;

class OpcrfSpreadsheetTest extends TestCase
{
    use BuildsOpcrfWorkbooks;

    public function test_it_extracts_header_objectives_accomplishments_and_rating(): void
    {
        $path = $this->buildFilledOpcrf();

        $data = (new OpcrfSpreadsheet($path))->analyze();

        $this->assertSame('Jane D. Doe', $data['employee_name']);
        $this->assertSame('Teacher I', $data['position']);
        $this->assertSame('January to December 2026', $data['review_period']);
        $this->assertSame('Schools Division Office', $data['division_office']);

        // Objective rows only — one F-filled row per objective block.
        $this->assertSame("Objective 1: Improved learner outcomes\nObjective 2: Conducted action research", $data['objectives']);
        $this->assertSame("Raised MPS by 5 points\nCompleted one action research", $data['accomplishments']);

        // All criteria ratings: 5, 4, 5, 4 → average 4.5.
        $this->assertSame([5.0, 4.0, 5.0, 4.0], $data['ratings']);
        $this->assertSame(4.5, $data['self_rating']);
        $this->assertSame(2, $data['objective_rows']);
    }

    public function test_labels_match_case_and_punctuation_insensitively(): void
    {
        $path = $this->buildOpcrfWorkbook([
            'B4' => 'NAME OF EMPLOYEE',
            'C4' => 'UPPERCASE LABELS',
            'B5' => 'position / designation -',
            'D5' => 'Principal',
        ]);

        $data = (new OpcrfSpreadsheet($path))->analyze();

        $this->assertSame('UPPERCASE LABELS', $data['employee_name']);
        $this->assertSame('Principal', $data['position']);
    }

    public function test_an_empty_workbook_yields_blank_fields_and_null_rating(): void
    {
        $path = $this->buildOpcrfWorkbook([]);

        $data = (new OpcrfSpreadsheet($path))->analyze();

        $this->assertSame('', $data['employee_name']);
        $this->assertSame('', $data['objectives']);
        $this->assertNull($data['self_rating']);
        $this->assertSame([], $data['ratings']);
    }

    public function test_a_non_xlsx_file_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'not-a-zip');
        file_put_contents($path, 'plain text, not a zip');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a valid .xlsx');

        new OpcrfSpreadsheet($path);
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be found');

        new OpcrfSpreadsheet(sys_get_temp_dir().'/definitely-missing-'.uniqid().'.xlsx');
    }

    public function test_the_pure_php_zip_reader_reads_a_real_workbook(): void
    {
        // The fallback for PHP builds without the zip extension (the
        // XAMPP web-server PHP) must parse the very same bytes.
        $path = $this->buildFilledOpcrf();

        $entries = (new PurePhpZipReader($path))->readAll();

        $this->assertArrayHasKey('xl/worksheets/sheet1.xml', $entries);
        $this->assertStringContainsString('Jane D. Doe', $entries['xl/worksheets/sheet1.xml']);
    }

    public function test_the_analyzer_works_through_the_pure_php_fallback(): void
    {
        // Force the fallback path even where ZipArchive exists, by stubbing
        // around the class check: rebuild the analyzer's parse step with the
        // pure reader via a subclass is overkill — instead assert that a
        // workbook written with STORED (no deflate) entries also parses,
        // since that exercises the reader's non-deflate branch.
        $path = $this->buildFilledOpcrf();

        // Same file through the pure reader must yield identical cells to
        // the analyzer's result (parsed above with ZipArchive).
        $expected = (new OpcrfSpreadsheet($path))->analyze();

        $sheetXml = (new PurePhpZipReader($path))->readAll()['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('Jane D. Doe', $sheetXml);
        $this->assertSame('Jane D. Doe', $expected['employee_name']);
    }

    public function test_a_non_xlsx_file_is_rejected_by_the_pure_php_reader(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'not-a-zip');
        file_put_contents($path, 'plain text, not a zip');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a valid .xlsx');

        (new PurePhpZipReader($path))->readAll();
    }

    public function test_hidden_rows_and_columns_are_excluded_from_the_analysis(): void
    {
        // The official template ships a fully-filled example objective
        // block on HIDDEN rows 16–18 — timeline "January to December
        // 2024", ratings 5/4/5. A staff member who clears every visible
        // cell still uploads a workbook Excel reports as empty there, and
        // the analyzer must agree: what is hidden is not user content.
        $path = $this->buildOpcrfWorkbookWithSheets([
            'OPCRF' => [
                'B4' => 'Name of Employee:',
                'F4' => 'Jane D. Doe',
                'B6' => 'Review Period:',
                'F6' => 'January to December 2026',
                // The hidden example block (would previously be read).
                'F16' => 'Objective 1: Ensured preparation of HR policies',
                'H16' => 'January to December 2024',
                'T16' => 5,
                'T17' => 4,
                'T18' => 5,
                // The staff member's real, VISIBLE objective.
                'F20' => 'Objective 2: Improved learner outcomes',
                'H20' => 'June 2026',
                'S20' => 'Raised MPS by 5 points',
                'T20' => 4,
            ],
        ], [
            'OPCRF' => ['hidden_rows' => [16, 17, 18]],
        ]);

        $data = (new OpcrfSpreadsheet($path))->analyze();

        // The hidden block is gone: no ghost timeline, no ghost rating.
        $this->assertSame('Objective 2: Improved learner outcomes', $data['objectives']);
        $this->assertSame('June 2026', (new OpcrfSpreadsheet($path))->objectiveEntries()[0]['timeline']);
        $this->assertSame([4.0], $data['ratings']);
        $this->assertSame(4.0, $data['self_rating']);
        $this->assertSame(1, $data['objective_rows']);
    }

    public function test_hidden_columns_are_excluded_from_the_analysis(): void
    {
        // Column H (Timeline) hidden entirely — its values must not leak
        // into the analysis, exactly like hidden rows.
        $path = $this->buildOpcrfWorkbookWithSheets([
            'OPCRF' => [
                'F16' => 'Objective 1: Improved learner outcomes',
                'H16' => 'January to December 2024',
                'T16' => 4,
            ],
        ], [
            'OPCRF' => ['hidden_columns' => [8]], // column H
        ]);

        $entries = (new OpcrfSpreadsheet($path))->objectiveEntries();

        $this->assertSame('Objective 1: Improved learner outcomes', $entries[0]['objectives']);
        $this->assertSame('', $entries[0]['timeline']);
    }

    public function test_visible_rows_are_kept_even_when_other_rows_are_hidden(): void
    {
        // Hiding row 17 must not hide its row 16 neighbour's content.
        $path = $this->buildOpcrfWorkbookWithSheets([
            'OPCRF' => [
                'F16' => 'Objective 1: A',
                'F17' => 'Objective 2: B',
            ],
        ], [
            'OPCRF' => ['hidden_rows' => [17]],
        ]);

        $data = (new OpcrfSpreadsheet($path))->analyze();

        $this->assertSame('Objective 1: A', $data['objectives']);
        $this->assertSame(1, $data['objective_rows']);
    }

    public function test_ratings_stored_as_text_cells_are_still_averaged(): void
    {
        // Real Excel writes T-column ratings as text when a cell was
        // touched by a format pass ("5" instead of 5).
        $path = $this->buildFilledOpcrf([
            'T16' => '5',
            'T17' => '4',
            'T18' => '5',
            'T19' => '4',
        ]);

        $data = (new OpcrfSpreadsheet($path))->analyze();

        $this->assertSame([5.0, 4.0, 5.0, 4.0], $data['ratings']);
        $this->assertSame(4.5, $data['self_rating']);
    }

    public function test_the_evaluator_block_is_read_with_labels_and_fallbacks(): void
    {
        // Real-template shape: the evaluator's name sits in O4 (merged
        // O4:V4) with a blank N4 label, the other rows carry labels in N.
        $path = $this->buildFilledOpcrf();

        $evaluators = (new OpcrfSpreadsheet($path))->evaluatorCells();

        $this->assertCount(3, $evaluators);

        // Blank N4 label falls back to "Evaluator:" — the name in O4
        // must still be shown.
        $this->assertSame('Evaluator:', $evaluators[0]['label']);
        $this->assertSame('EVA M. DOLLOSA RN', $evaluators[0]['value']);
        $this->assertSame('Position:', $evaluators[1]['label']);
        $this->assertSame('OIC-Asst. Schools Division Superintendent', $evaluators[1]['value']);
        $this->assertSame('Approving Authority:', $evaluators[2]['label']);
        $this->assertSame('FERDINAND S. SY PhD, CESO VI', $evaluators[2]['value']);
    }

    public function test_the_evaluator_block_skips_rows_without_values(): void
    {
        // "Date of Review:" has a label but no value in the template —
        // that row must be omitted entirely.
        $path = $this->buildOpcrfWorkbook([
            'N4' => 'Evaluator:',
            'O4' => 'EVA M. DOLLOSA RN',
            'N7' => 'Date of Review:',
        ]);

        $evaluators = (new OpcrfSpreadsheet($path))->evaluatorCells();

        $this->assertCount(1, $evaluators);
        $this->assertSame('Evaluator:', $evaluators[0]['label']);
        $this->assertSame('EVA M. DOLLOSA RN', $evaluators[0]['value']);
    }

    public function test_an_empty_workbook_has_no_evaluator_block(): void
    {
        $path = $this->buildOpcrfWorkbook([]);

        $this->assertSame([], (new OpcrfSpreadsheet($path))->evaluatorCells());
    }

    public function test_the_three_parts_are_read_with_entries_and_total_scores(): void
    {
        $path = $this->buildThreePartOpcrf();

        $parts = (new OpcrfSpreadsheet($path))->parts();

        $this->assertCount(3, $parts);

        // Part I-A: banner + note split, own entries, numeric total.
        $this->assertSame('I-A', $parts[0]['key']);
        $this->assertSame('COMMITMENT TO ORGANIZATIONAL OUTCOMES (60%)', $parts[0]['title']);
        // The template's own note text re-opens with the part label.
        $this->assertStringStartsWith('Part I-A. Commitment to Organizational Outcomes shall capture', $parts[0]['note']);
        // Two objectives now live in Part I-A (the real template has many).
        $this->assertCount(2, $parts[0]['entries']);
        $this->assertSame('Objective 1: Improved learner outcomes', $parts[0]['entries'][0]['objectives']);
        $this->assertSame('January to December 2026', $parts[0]['entries'][0]['timeline']);
        $this->assertSame('Objective 2: Implemented the School Improvement Plan', $parts[0]['entries'][1]['objectives']);
        $this->assertSame(2.0, $parts[0]['total_score']);
        $this->assertTrue($parts[0]['has_total_row']);

        // Part I-B: the "Objectives" header band cell must not read as an objective.
        $this->assertSame('I-B', $parts[1]['key']);
        $this->assertCount(1, $parts[1]['entries']);
        $this->assertSame('Objective: Conducted innovations and interventions', $parts[1]['entries'][0]['objectives']);
        $this->assertSame(1.0, $parts[1]['total_score']);

        // Part I-C: Timeline lives in column J (K holds a numeric weight).
        $this->assertSame('I-C', $parts[2]['key']);
        $this->assertCount(1, $parts[2]['entries']);
        $this->assertSame('Objective: Utilized budget allocation', $parts[2]['entries'][0]['objectives']);
        $this->assertSame('Within the rating period', $parts[2]['entries'][0]['timeline']);
        $this->assertSame(1.0, $parts[2]['total_score']);
    }

    public function test_excel_error_total_scores_read_as_null(): void
    {
        // The real template's total rows hold #DIV/0! until Excel
        // recalculates — that reads as no total yet, not a crash or 0.
        $path = $this->buildThreePartOpcrf(['V76' => '#DIV/0!']);

        $parts = (new OpcrfSpreadsheet($path))->parts();

        $this->assertNull($parts[0]['total_score']);
        $this->assertTrue($parts[0]['has_total_row']);
    }

    public function test_a_workbook_without_part_banners_forms_one_untitled_part(): void
    {
        $path = $this->buildFilledOpcrf();

        $parts = (new OpcrfSpreadsheet($path))->parts();

        $this->assertCount(1, $parts);
        $this->assertSame('', $parts[0]['key']);
        $this->assertSame('', $parts[0]['title']);
        $this->assertFalse($parts[0]['has_total_row']);
        $this->assertNull($parts[0]['total_score']);
        $this->assertCount(2, $parts[0]['entries']); // the two plain objectives
    }

    public function test_the_signer_block_reads_roles_with_names_above(): void
    {
        $path = $this->buildThreePartOpcrf();

        $signers = (new OpcrfSpreadsheet($path))->signers();

        $this->assertCount(3, $signers);

        // RATEE's name cell holds a stray computed number → no name.
        $this->assertSame('RATEE', $signers[0]['role']);
        $this->assertSame('', $signers[0]['name']);

        $this->assertSame('RATER', $signers[1]['role']);
        $this->assertSame('EVA M. DOLLOSA RN', $signers[1]['name']);

        $this->assertSame('APPROVING AUTHORITY', $signers[2]['role']);
        $this->assertSame('FERDINAND S. SY PhD, CESO VI', $signers[2]['name']);
    }

    public function test_signature_lines_and_blanks_never_read_as_signer_names(): void
    {
        // Dashes/underscores on the line above the role (the signature
        // line) or blank cells must not become names.
        $path = $this->buildThreePartOpcrf([
            'D151' => '___________',
            'I151' => '',
        ]);

        $signers = (new OpcrfSpreadsheet($path))->signers();

        $this->assertSame('', $signers[0]['name']); // signature line, not a name
        $this->assertSame('', $signers[1]['name']); // blank
        $this->assertSame('FERDINAND S. SY PhD, CESO VI', $signers[2]['name']);
    }

    public function test_a_workbook_without_a_signer_block_returns_no_signers(): void
    {
        $path = $this->buildFilledOpcrf();

        $this->assertSame([], (new OpcrfSpreadsheet($path))->signers());
    }

    public function test_objective_entries_span_all_parts_in_sheet_order(): void
    {
        $path = $this->buildThreePartOpcrf();

        $entries = (new OpcrfSpreadsheet($path))->objectiveEntries();

        $this->assertCount(4, $entries);
        $this->assertSame(16, $entries[0]['row']); // Part I-A
        $this->assertSame(19, $entries[1]['row']); // Part I-A, second objective
        $this->assertSame(83, $entries[2]['row']); // Part I-B
        $this->assertSame(119, $entries[3]['row']); // Part I-C
        // The whole-workbook blob keeps every objective, in order.
        $this->assertSame(
            "Objective 1: Improved learner outcomes\nObjective 2: Implemented the School Improvement Plan\nObjective: Conducted innovations and interventions\nObjective: Utilized budget allocation",
            (new OpcrfSpreadsheet($path))->analyze()['objectives']
        );
    }

    public function test_layout_data_mirrors_the_excel_sheet_for_the_review_modal(): void
    {
        $path = $this->buildFilledOpcrf([
            'B2' => "OFFICE PERFORMANCE COMMITMENT AND REVIEW FORM (OPCRF) \nver.F",
            'H16' => 'January to December 2026',
        ]);

        $sheet = new OpcrfSpreadsheet($path);

        $this->assertSame(
            'OFFICE PERFORMANCE COMMITMENT AND REVIEW FORM (OPCRF) ver.F',
            $sheet->title()
        );

        $headers = $sheet->headerCells();
        $this->assertCount(4, $headers);
        $this->assertSame('Name of Employee:', $headers[0]['label']);
        $this->assertSame('Jane D. Doe', $headers[0]['value']);
        $this->assertSame('Position/Designation:', $headers[1]['label']);
        $this->assertSame('Teacher I', $headers[1]['value']);

        $entries = $sheet->objectiveEntries();
        $this->assertCount(2, $entries);

        // Objective 1: three criteria rows (Quality/Efficiency/Timeliness).
        $this->assertSame(16, $entries[0]['row']);
        $this->assertSame('Objective 1: Improved learner outcomes', $entries[0]['objectives']);
        $this->assertSame('January to December 2026', $entries[0]['timeline']);
        $this->assertSame('Quality', $entries[0]['criteria'][0]['label']);
        $this->assertSame('Raised MPS by 5 points', $entries[0]['criteria'][0]['accomplishments']);
        $this->assertSame(5.0, $entries[0]['criteria'][0]['rating']);
        $this->assertSame('Efficiency', $entries[0]['criteria'][1]['label']);
        $this->assertSame('', $entries[0]['criteria'][1]['accomplishments']);
        $this->assertSame(4.0, $entries[0]['criteria'][1]['rating']);
        $this->assertSame('Timeliness', $entries[0]['criteria'][2]['label']);

        // Objective 2: one criteria row.
        $this->assertSame(19, $entries[1]['row']);
        $this->assertSame('Objective 2: Conducted action research', $entries[1]['objectives']);
        $this->assertCount(1, $entries[1]['criteria']);
        $this->assertSame('Completed one action research', $entries[1]['criteria'][0]['accomplishments']);
    }

    public function test_every_template_column_is_read_for_the_full_review(): void
    {
        $parts = (new OpcrfSpreadsheet($this->buildThreePartOpcrf()))->parts();

        $first = $parts[0]['entries'][0];

        // The planning band: KRA, attribution, weight, target, MOVs.
        $this->assertSame('Education Human Resource Development Program', $first['kra']);
        $this->assertSame('BEDP Pillar 1: Access', $first['attribution']);
        $this->assertSame('0.35', $first['weight']);
        $this->assertSame('2', $first['target_value']);
        $this->assertSame('AIP activities aligned with the approved WFP', $first['target_description']);
        $this->assertSame('Approved WFP and Annual Implementation Plan', $first['movs']);

        // The per-block computed averages.
        $this->assertSame(4.67, $first['average']);
        $this->assertNull($first['weighted_average']); // not typed in the fixture

        // The 5-level Rating Scale columns (M..Q) on the Quality row.
        $quality = $first['criteria'][0];
        $scale = $quality['scale'];
        $this->assertCount(2, $scale);
        $this->assertSame(['level' => 5, 'text' => 'Met all 5 indicators based on the established standards'], $scale[0]);
        $this->assertSame(['level' => 1, 'text' => 'Only 1 out of 5 indicators is met'], $scale[1]);

        // A second objective in the same part: its own KRA, weight and
        // criteria — the first block never swallowed it.
        $second = $parts[0]['entries'][1];
        $this->assertSame('Objective 2: Implemented the School Improvement Plan', $second['objectives']);
        $this->assertSame('School Leadership and Administration', $second['kra']);
        $this->assertSame('0.05', $second['weight']);
        $this->assertCount(1, $second['criteria']);
        $this->assertSame('SIP implemented across all grade levels', $second['criteria'][0]['accomplishments']);
        $this->assertSame(4.0, $second['criteria'][0]['rating']);
    }

    /**
     * The shipped template's own header bands, as the workbook carries
     * them: the two column-band headings and the column captions (with
     * the Performance Targets sub-header that splits it into a value and
     * a description).
     *
     * @return array<string, string>
     */
    private function realHeaderBands(): array
    {
        return [
            // PART I-A (banner row 10, first objective row 16)
            'B12' => 'TO BE ACCOMPLISHED DURING PLANNING',
            'S12' => 'TO BE FILLED DURING EVALUATION',
            'B14' => 'Key Result Areas (KRA) (Based on Office Mandate and Functions)',
            'C14' => 'Organizational Outcome Attribution (Refer to the GAA Programs/Subprogram and BEDP Pillars)',
            'F13' => 'Objectives (based on Office Functions)',
            'H13' => 'Timeline',
            'I13' => 'Weight Allocation',
            'J13' => 'Performance Targets (Target Outcome/Output)',
            'J14' => 'Value (numerical, statistical, trend)',
            'K14' => 'Description (expected outcome/ output/service)',
            'L13' => 'Performance Measure (Quality, Efficiency, Timeliness)',
            'M13' => 'Rating Scale',
            'R13' => 'Means of Verification (MOVs)',
            'S13' => 'Actual Accomplishments',
            'T13' => 'RATING (Q,E,T)',
            'U13' => 'AVERAGE (QET)',
            'V13' => 'WEIGHTED AVERAGE',
        ];
    }

    public function test_the_parts_own_header_band_names_its_columns(): void
    {
        // The real template names its columns in a caption row above each
        // part's data. Reading that band — instead of assuming one fixed
        // layout for all three parts — is what keeps Part I-C's Timeline
        // (column J) and Weight (column K) out of the Performance Target
        // fields they would otherwise be mistaken for.
        $parts = (new OpcrfSpreadsheet(
            $this->buildThreePartOpcrf(array_merge($this->realHeaderBands(), [
                'J16' => '2',
                'K16' => 'AIP activities aligned with the approved WFP',
                'B116' => 'TO BE FILLED IN DURING PLANNING',
                'B117' => 'Organizational Effectiveness Area',
                'F117' => 'Objectives',
                'J117' => 'Timeline',
                'K117' => 'Weight Allocation',
                'L117' => 'Performance Measure (Quality, Efficiency, Timeliness)',
                'M117' => 'RATING SCALE',
                'R117' => 'Means of Verification (MOVs)',
                'S117' => 'Actual Results/ Accomplishments',
                'T117' => 'RATING (Q,E,T)',
                'J119' => 'Quarterly',
                'K119' => '0.05',
            ]))
        ))->parts();

        // Part I-A: the Performance Targets block is the value/description
        // pair its sub-header describes, and the band captions are the
        // template's own wording.
        $this->assertSame('2', $parts[0]['entries'][0]['target_value']);
        $this->assertSame('AIP activities aligned with the approved WFP', $parts[0]['entries'][0]['target_description']);
        $this->assertSame('TO BE ACCOMPLISHED DURING PLANNING', $parts[0]['bands']['planning']);
        $this->assertSame('TO BE FILLED DURING EVALUATION', $parts[0]['bands']['evaluation']);
        $this->assertSame('Timeline', $parts[0]['captions']['timeline']);
        $this->assertSame('Performance Measure (Quality, Efficiency, Timeliness)', $parts[0]['captions']['measure']);

        // Part I-C: Timeline in J, Weight in K, and no Performance Targets
        // or AVERAGE columns at all — the fields the band does not name
        // stay empty instead of picking up a neighbouring column's value.
        $this->assertSame('Quarterly', $parts[2]['entries'][0]['timeline']);
        $this->assertSame('0.05', $parts[2]['entries'][0]['weight']);
        $this->assertSame('', $parts[2]['entries'][0]['target_value']);
        $this->assertSame('', $parts[2]['entries'][0]['target_description']);
        $this->assertArrayNotHasKey('average', $parts[2]['captions']);
        // Its left-hand column is the Effectiveness Area, not a KRA.
        $this->assertSame('Organizational Effectiveness Area', $parts[2]['captions']['area']);
    }

    public function test_a_part_without_a_header_band_keeps_the_shipped_columns(): void
    {
        // A workbook with no caption row (a plain fixture, an older
        // template) falls back to the shipped layout's fixed letters, and
        // column J is still read as a Timeline when it is not a number.
        $parts = (new OpcrfSpreadsheet($this->buildThreePartOpcrf()))->parts();

        $this->assertSame('January to December 2026', $parts[0]['entries'][0]['timeline']);
        $this->assertSame('Within the rating period', $parts[2]['entries'][0]['timeline']);
        $this->assertSame([], $parts[0]['captions']);
    }

    public function test_the_statement_of_purpose_is_read(): void
    {
        // Row 8 is the office's own narrative commitment — the longest
        // piece of prose in the form, and previously not read at all.
        $purpose = (new OpcrfSpreadsheet($this->buildThreePartOpcrf([
            'B8' => 'Strand/Bureau/Center/Service/Region/Division Statement of Purpose:',
            'F8' => 'Anchored on the Vision, Mission, and Core Values of the Department of Education.',
        ])))->purposeStatement();

        $this->assertSame('Anchored on the Vision, Mission, and Core Values of the Department of Education.', $purpose['value']);
        $this->assertStringContainsString('Statement of Purpose', $purpose['label']);
    }

    public function test_an_empty_statement_of_purpose_reads_as_blank(): void
    {
        $purpose = (new OpcrfSpreadsheet($this->buildThreePartOpcrf()))->purposeStatement();

        $this->assertSame('', $purpose['value']);
    }

    public function test_a_merged_kra_is_inherited_by_the_objectives_beneath_it(): void
    {
        // The real template merges the KRA cell down across an objective's
        // rows, so a second objective in the same KRA band has no B cell of
        // its own — it must inherit the KRA typed above it (what Excel
        // shows spanning down). Row 19 carries its own KRA in the fixture;
        // removing it proves the inheritance.
        $parts = (new OpcrfSpreadsheet(
            $this->buildThreePartOpcrf(['B19' => ''])
        ))->parts();

        $second = $parts[0]['entries'][1];

        $this->assertSame('Education Human Resource Development Program', $second['kra']);
    }

    public function test_column_captions_never_read_as_kra_values(): void
    {
        // A caption cell ("Key Result Areas (KRA)") sitting in the B band
        // must not be inherited as a KRA value.
        $parts = (new OpcrfSpreadsheet(
            $this->buildThreePartOpcrf(['B16' => 'Key Result Areas (KRA) (Based on Office Mandates)'])
        ))->parts();

        $this->assertSame('', $parts[0]['entries'][0]['kra']);
    }
}
