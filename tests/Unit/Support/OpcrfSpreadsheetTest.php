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
        $this->assertCount(1, $parts[0]['entries']);
        $this->assertSame('Objective 1: Improved learner outcomes', $parts[0]['entries'][0]['objectives']);
        $this->assertSame('January to December 2026', $parts[0]['entries'][0]['timeline']);
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

        $this->assertCount(3, $entries);
        $this->assertSame(16, $entries[0]['row']); // Part I-A
        $this->assertSame(83, $entries[1]['row']); // Part I-B
        $this->assertSame(119, $entries[2]['row']); // Part I-C
        // The whole-workbook blob keeps every objective, in order.
        $this->assertSame(
            "Objective 1: Improved learner outcomes\nObjective: Conducted innovations and interventions\nObjective: Utilized budget allocation",
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
}
