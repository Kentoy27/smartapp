<?php

namespace Tests\Unit;

use App\Support\WfpTemplateFiller;
use RuntimeException;
use Tests\Concerns\BuildsWfpTemplates;
use Tests\TestCase;

class WfpTemplateFillerTest extends TestCase
{
    use BuildsWfpTemplates;

    public function test_it_locates_the_table(): void
    {
        $bytes = $this->wfpTemplateBytes(3);
        $layout = WfpTemplateFiller::locate(WfpTemplateFiller::readParts($bytes));

        $this->assertSame(3, $layout['header_row']);
        $this->assertSame('WFP', $layout['sheet_name']);
        $this->assertSame('B', $layout['columns']['objective']);
        $this->assertSame('C', $layout['columns']['program_activity']);
        $this->assertSame('F', $layout['columns']['physical_target']);
        $this->assertSame('H', $layout['columns']['financial_target']);
        $this->assertSame('J', $layout['columns']['account_code']);
        $this->assertSame([4, 5, 6], $layout['data_rows']);
        $this->assertSame(7, $layout['total_row']);
    }

    public function test_it_fills_rows_in_the_right_columns_and_preserves_the_template(): void
    {
        $bytes = $this->wfpTemplateBytes(3);
        $layout = WfpTemplateFiller::locate(WfpTemplateFiller::readParts($bytes));

        $rows = [
            [
                'objective' => 'Objective A',
                'program_activity' => 'Procure supplies',
                'physical_target' => 10,
                'physical_unit' => 'ream',
                'financial_target' => 2500,
                'account_title' => 'Office Supplies',
                'account_code' => '5020301000',
            ],
            [
                'program_activity' => 'Build a fence',
                'physical_target' => 5,
                'financial_target' => 500,
            ],
        ];

        $filled = WfpTemplateFiller::fill($bytes, $rows, $layout);
        $sheet = $this->wfpSheetXml($filled);

        // Headings are untouched.
        $this->assertSame('Objectives', $this->wfpCellText($sheet, 'B3'));
        $this->assertSame('Programs, Projects, & Activities', $this->wfpCellText($sheet, 'C3'));
        $this->assertSame('Financial Target', $this->wfpCellText($sheet, 'H3'));

        // Values land in the resolved columns.
        $this->assertSame('Objective A', $this->wfpCellText($sheet, 'B4'));
        $this->assertSame('Procure supplies', $this->wfpCellText($sheet, 'C4'));
        $this->assertSame('10.00', $this->wfpCellText($sheet, 'F4'));
        $this->assertSame('2500.00', $this->wfpCellText($sheet, 'H4'));
        $this->assertSame('Build a fence', $this->wfpCellText($sheet, 'C5'));
        $this->assertSame('500.00', $this->wfpCellText($sheet, 'H5'));

        // The written cell keeps the column's style.
        $this->assertSame('5', $this->wfpCellStyle($sheet, 'C4'));

        // The TOTAL formula still spans the whole data band…
        $this->assertSame('SUM(F4:F6)', $this->wfpCellFormula($sheet, 'F7'));
        $this->assertSame('SUM(H4:H6)', $this->wfpCellFormula($sheet, 'H7'));

        // …the merge outside the band is intact, and the dimension is present.
        $this->assertStringContainsString('<mergeCell ref="A1:M1"/>', $sheet);
        $this->assertStringContainsString('<dimension ref="A1:M7"/>', $sheet);
    }

    public function test_it_clones_rows_past_the_blank_band_and_extends_the_total(): void
    {
        $bytes = $this->wfpTemplateBytes(3);
        $layout = WfpTemplateFiller::locate(WfpTemplateFiller::readParts($bytes));

        $rows = array_fill(0, 5, ['program_activity' => 'Activity X', 'financial_target' => 100]);

        $filled = WfpTemplateFiller::fill($bytes, $rows, $layout);
        $sheet = $this->wfpSheetXml($filled);

        // Rows 7 and 8 are clones carrying the 4th and 5th values.
        $this->assertSame('Activity X', $this->wfpCellText($sheet, 'C7'));
        $this->assertSame('Activity X', $this->wfpCellText($sheet, 'C8'));
        $this->assertSame('100.00', $this->wfpCellText($sheet, 'H8'));

        // The TOTAL row moved down and its range now covers the new rows.
        $this->assertSame('SUM(F4:F8)', $this->wfpCellFormula($sheet, 'F9'));
        $this->assertSame('TOTAL', $this->wfpCellText($sheet, 'A9'));
    }

    public function test_it_leaves_the_header_unlocated_when_no_labels_match(): void
    {
        $bytes = $this->wfpTemplateBytes(3, []); // a header row with no recognised labels
        $layout = WfpTemplateFiller::locate(WfpTemplateFiller::readParts($bytes));

        $this->assertNull($layout['header_row']);
        $this->assertSame([], $layout['columns']);
    }

    public function test_fill_refuses_a_workbook_it_cannot_read(): void
    {
        $bytes = $this->wfpTemplateBytes(3);

        $this->expectException(RuntimeException::class);

        WfpTemplateFiller::fill($bytes, [], [
            'sheet_path' => 'xl/worksheets/sheet1.xml',
            'header_row' => null,
            'columns' => [],
            'months' => [],
            'data_rows' => [],
            'total_row' => null,
        ]);
    }
}
