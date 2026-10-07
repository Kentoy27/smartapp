<?php

namespace Tests\Unit\Support;

use App\Support\OpcrfSpreadsheet;
use Tests\Concerns\BuildsOpcrfWorkbooks;
use Tests\TestCase;

/**
 * The whole-workbook analysis: every tab of the uploaded file is read —
 * PART I's objectives sheet as before, plus the PART II competency tab,
 * the PART III rating summary, the PART IV improvement plans, and any
 * non-standard tabs as raw cell grids.
 */
class OpcrfWorkbookTest extends TestCase
{
    use BuildsOpcrfWorkbooks;

    public function test_every_tab_of_the_workbook_is_loaded(): void
    {
        $spreadsheet = new OpcrfSpreadsheet($this->buildFullOpcrfWorkbook());

        $names = array_column($spreadsheet->sheetNames(), 'name');

        $this->assertSame(
            ['PART I (CY 2025 & SY2025-2026)', 'PART II', 'PART III', 'PART IV'],
            $names
        );
    }

    public function test_part_two_reads_sections_groups_indicators_and_totals(): void
    {
        $spreadsheet = new OpcrfSpreadsheet($this->buildFullOpcrfWorkbook());
        $two = $spreadsheet->partTwo();

        $this->assertCount(2, $two['sections']);

        [$a, $b] = $two['sections'];

        $this->assertSame('II-A', $a['key']);
        $this->assertSame('LEADERSHIP COMPETENCIES (2.5%)', $a['title']);
        $this->assertSame('II-B', $b['key']);

        // Groups carry their numbered indicators and per-indicator ratings.
        $leading = $a['groups'][0];

        $this->assertSame('Leading People', $leading['label']);
        $this->assertSame(
            '1. Uses basic persuasion techniques in a discussion.',
            $leading['indicators'][0]['text']
        );
        $this->assertSame(4.0, $leading['indicators'][0]['rating']);
        $this->assertSame(5.0, $leading['indicators'][1]['rating']);
        $this->assertCount(3, $a['groups']);
        $this->assertCount(1, $b['groups']);
        $this->assertSame('Self-Management', $b['groups'][0]['label']);

        // The weighted-total rows with their cached scores.
        $this->assertCount(2, $two['total_rows']);
        $this->assertSame(4.5, $two['total_rows'][0]['score']);
        $this->assertSame(4.0, $two['total_rows'][1]['score']);

        // The tab's own signer block: the RATER's name sits above their role.
        $roles = array_column($two['signers'], 'role');
        $this->assertContains('RATEE', $roles);
        $this->assertContains('RATER', $roles);

        $rater = $two['signers'][array_search('RATER', $roles, true)];
        $this->assertSame('EVA M. DOLLOSA RN', $rater['name']);
    }

    public function test_part_two_is_empty_without_the_tab(): void
    {
        $spreadsheet = new OpcrfSpreadsheet($this->buildFilledOpcrf());

        $this->assertSame(
            ['sections' => [], 'total_rows' => [], 'signers' => [], 'scale' => ['title' => '', 'levels' => []]],
            $spreadsheet->partTwo()
        );
    }

    public function test_part_three_reads_components_and_agreement(): void
    {
        $spreadsheet = new OpcrfSpreadsheet($this->buildFullOpcrfWorkbook());
        $three = $spreadsheet->partThree();

        $this->assertCount(5, $three['components']);

        [$first, , , , $last] = $three['components'];

        $this->assertSame('PART I', $first['part']);
        $this->assertSame('A.  Commitment to Organizational Outcomes', $first['component']);
        $this->assertSame('0.6', $first['weight']);
        $this->assertSame(4.2, $first['obtained']);

        $this->assertSame('PART II', $last['part']);
        $this->assertSame('B.  Core Behavioural Competencies', $last['component']);
        $this->assertSame('2.5% (0.125)', $last['weight']);
        $this->assertSame(4.0, $last['obtained']);

        $this->assertSame(
            [
                ['role' => 'Employee (Ratee)', 'name' => 'JUAN DELA CRUZ'],
                ['role' => 'Superior (Rater)', 'name' => 'EVA M. DOLLOSA RN'],
            ],
            $three['agreement']
        );
    }

    public function test_part_two_reads_the_remarks_column_and_the_competencies_scale(): void
    {
        $two = (new OpcrfSpreadsheet($this->buildFullOpcrfWorkbook()))->partTwo();

        $indicators = $two['sections'][0]['groups'][0]['indicators'];

        // The rater's own words per indicator (column G) — they used to be
        // parsed nowhere, so the review could not show them at all.
        $this->assertSame('Persuaded the division during the budget hearing.', $indicators[0]['remarks']);
        $this->assertSame('', $indicators[1]['remarks']);

        // The tab's printed rating legend: what each number means.
        $this->assertSame('DepEd Competencies Scale', $two['scale']['title']);
        $this->assertCount(3, $two['scale']['levels']);
        $this->assertSame('5', $two['scale']['levels'][0]['number']);
        $this->assertSame('Role Model', $two['scale']['levels'][0]['adjectival']);
        $this->assertSame('Behavioral indicator is seldom shown.', $two['scale']['levels'][2]['definition']);
        // Highest first, as the form prints it.
        $this->assertSame(['5', '4', '1'], array_column($two['scale']['levels'], 'number'));
    }

    public function test_part_three_reads_the_overall_score_rating_table_and_signatures(): void
    {
        $three = (new OpcrfSpreadsheet($this->buildFullOpcrfWorkbook()))->partThree();

        $this->assertSame(4.25, $three['overall']);
        $this->assertSame('Very Satisfactory', $three['rating']);

        // The rating table, without its own header row.
        $this->assertCount(2, $three['rating_table']);
        $this->assertSame('4.500-5.000', $three['rating_table'][0]['range']);
        $this->assertSame('Outstanding', $three['rating_table'][0]['adjectival']);

        // The signature and date lines under each printed name.
        $this->assertCount(2, $three['signatures']);
        $this->assertSame('Employee (Ratee)', $three['signatures'][0]['role']);
        $this->assertSame('2026-03-31', $three['signatures'][0]['date']);
        $this->assertSame('', $three['signatures'][0]['signature']);
        $this->assertSame('EVA M. DOLLOSA RN', $three['signatures'][1]['signature']);
        $this->assertSame('', $three['signatures'][1]['date']);
    }

    public function test_part_four_reads_both_plans_feedback_and_signers(): void
    {
        $spreadsheet = new OpcrfSpreadsheet($this->buildFullOpcrfWorkbook());
        $four = $spreadsheet->partFour();

        $this->assertCount(1, $four['office_plan']);
        $this->assertSame([
            'gap' => 'Weak ICT infrastructure',
            'area' => 'Learning resources',
            'objective' => 'Digitize learning materials',
            'intervention' => 'Procure tablets and offline content',
            'timeline' => 'June 2026',
            'resources' => 'MOOE funds',
            'row' => 10,
        ], $four['office_plan'][0]);

        $this->assertSame('Plans are achievable within the rating period.', $four['office_feedback']);

        $this->assertCount(1, $four['development_plan']);
        $this->assertSame('Strong classroom management', $four['development_plan'][0]['gap']);
        $this->assertSame('Attend writeshop', $four['development_plan'][0]['intervention']);

        $roles = array_column($four['signers'], 'role');
        $this->assertContains('RATEE', $roles);
    }

    public function test_non_standard_tabs_come_back_as_raw_grids(): void
    {
        $path = $this->buildOpcrfWorkbookWithSheets([
            'OPCRF' => ['B4' => 'Name of Employee:', 'F4' => 'Jane D. Doe'],
            'NOTES' => ['A1' => 'School memo', 'B2' => 'Extra details'],
        ]);

        $spreadsheet = new OpcrfSpreadsheet($path);
        $workbook = $spreadsheet->workbook();

        $this->assertSame('NOTES', $workbook['extra_sheets'][0]['name']);

        $rows = $workbook['extra_sheets'][0]['rows'];

        $this->assertSame(1, $rows[0]['row']);
        $this->assertSame('A', $rows[0]['cells'][0]['column']);
        $this->assertSame('School memo', $rows[0]['cells'][0]['value']);
        $this->assertSame('Extra details', $rows[1]['cells'][0]['value']);
    }

    public function test_workbook_assembles_every_piece_in_one_call(): void
    {
        $spreadsheet = new OpcrfSpreadsheet($this->buildFullOpcrfWorkbook());
        $workbook = $spreadsheet->workbook();

        $this->assertCount(4, $workbook['tabs']);
        $this->assertCount(2, $workbook['part_two']['sections']);
        $this->assertCount(5, $workbook['part_three']['components']);
        $this->assertCount(1, $workbook['part_four']['office_plan']);
        $this->assertSame([], $workbook['extra_sheets']);
    }
}
