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

    public function test_it_resolves_a_monthly_two_band_header_block(): void
    {
        $bytes = $this->wfpMonthlyTemplateBytes(3);
        $layout = WfpTemplateFiller::locate(WfpTemplateFiller::readParts($bytes));

        $this->assertSame(3, $layout['header_row']);

        // Parent headings resolve across the whole block, suffix and all.
        $this->assertSame('B', $layout['columns']['program_activity']);
        $this->assertSame('C', $layout['columns']['physical_target']);
        $this->assertSame('G', $layout['columns']['financial_target']);
        $this->assertSame('H', $layout['columns']['account_code']);

        // The two month bands split at the Financial Target parent.
        $this->assertSame([1 => 'G', 2 => 'I', 3 => 'K'], $layout['months']);
        $this->assertSame([1 => 'C', 2 => 'D', 3 => 'E'], $layout['physical_months']);

        // Each month's paired Object Code column is discovered beside it.
        $this->assertSame([1 => 'H', 2 => 'J', 3 => 'L'], $layout['code_columns_by_month']);

        // The sub-header rows are NOT data: the band starts after them.
        $this->assertSame(6, $layout['data_start_row']);
        $this->assertSame([6, 7, 8], $layout['data_rows']);
        $this->assertSame(9, $layout['total_row']);
    }

    public function test_it_fills_month_grained_rows_into_their_own_month_columns(): void
    {
        $bytes = $this->wfpMonthlyTemplateBytes(3);
        $layout = WfpTemplateFiller::locate(WfpTemplateFiller::readParts($bytes));

        $rows = [
            [
                'month' => 2,
                'program_activity' => 'Pay rent',
                'physical_target' => 1,
                'financial_target' => 8000,
                'account_code' => '5020301000',
            ],
            [
                'month' => 3,
                'program_activity' => 'Pay supplies',
                'physical_target' => 2,
                'financial_target' => 1500,
                'account_code' => '5020302000',
            ],
        ];

        $filled = WfpTemplateFiller::fill($bytes, $rows, $layout);
        $sheet = $this->wfpSheetXml($filled);

        // Targets and the object code land in THEIR month's columns…
        $this->assertSame('8000.00', $this->wfpCellText($sheet, 'I6'));
        $this->assertSame('1.00', $this->wfpCellText($sheet, 'D6'));
        $this->assertSame('5020301000', $this->wfpCellText($sheet, 'J6'));
        $this->assertSame('1500.00', $this->wfpCellText($sheet, 'K7'));
        $this->assertSame('2.00', $this->wfpCellText($sheet, 'E7'));
        $this->assertSame('5020302000', $this->wfpCellText($sheet, 'L7'));

        // …and never in another month's.
        $this->assertSame('', $this->wfpCellText($sheet, 'G6'));
        $this->assertSame('', $this->wfpCellText($sheet, 'H6'));

        // The header block and TOTAL row are untouched.
        $this->assertSame('Financial Target (2025)', $this->wfpCellText($sheet, 'G3'));
        $this->assertSame('Object Code', $this->wfpCellText($sheet, 'H4'));
        $this->assertSame('Jan', $this->wfpCellText($sheet, 'C5'));
        $this->assertSame('SUM(G6:G8)', $this->wfpCellFormula($sheet, 'G9'));
    }

    public function test_a_monthless_row_writes_its_code_into_every_monthly_column(): void
    {
        $bytes = $this->wfpMonthlyTemplateBytes(3);
        $layout = WfpTemplateFiller::locate(WfpTemplateFiller::readParts($bytes));

        // The account-code generator's rows: one code per row, no month.
        $rows = [['account_title' => 'MOOE', 'account_code' => '5020301000']];

        $filled = WfpTemplateFiller::fill($bytes, $rows, $layout);
        $sheet = $this->wfpSheetXml($filled);

        $this->assertSame('5020301000', $this->wfpCellText($sheet, 'H6'));
        $this->assertSame('5020301000', $this->wfpCellText($sheet, 'J6'));
        $this->assertSame('5020301000', $this->wfpCellText($sheet, 'L6'));

        // There is no Account Title column in this shape; the row still fills.
        $this->assertSame('', $this->wfpCellText($sheet, 'C6'));
    }

    public function test_a_formula_the_template_owns_is_never_overwritten(): void
    {
        $bytes = $this->wfpMonthlyTemplateBytes(3);
        $layout = WfpTemplateFiller::locate(WfpTemplateFiller::readParts($bytes));

        // One month-grained row: only row 6 is written, so row 8 — which
        // carries K8's row-total formula — is cleared by the blank-out pass.
        $filled = WfpTemplateFiller::fill($bytes, [
            ['month' => 1, 'financial_target' => 250, 'account_code' => '5020301000'],
        ], $layout);

        $sheet = $this->wfpSheetXml($filled);

        // The written row is filled…
        $this->assertSame('250.00', $this->wfpCellText($sheet, 'G6'));

        // …and the template's own computed cell survives the blank-out.
        $this->assertSame('SUM(K6:K7)', $this->wfpCellFormula($sheet, 'K8'));
        $this->assertSame('', $this->wfpCellText($sheet, 'G8'));
    }

    public function test_the_shipped_template_layout_is_recognised(): void
    {
        $layout = $this->shippedLayout();

        // The FY-2026 form: a title block, the header at row 11 with its
        // month/Object Code row beneath, then 164 blank lines and a Total row.
        $this->assertSame(11, $layout['header_row']);
        $this->assertSame(14, $layout['data_start_row']);
        $this->assertSame(177, max($layout['data_rows']));
        $this->assertSame(178, $layout['total_row']);

        $this->assertSame('B', $layout['columns']['program_activity']);
        $this->assertSame('C', $layout['columns']['major_output']);
        $this->assertSame('E', $layout['columns']['physical_target']);
        $this->assertSame('R', $layout['columns']['financial_target']);
        $this->assertSame('S', $layout['columns']['account_code']);

        // Both month bands — physical and financial — are on the one row.
        $this->assertSame([1 => 'E', 2 => 'F', 12 => 'P'], array_intersect_key($layout['physical_months'], [1 => 1, 2 => 1, 12 => 1]));
        $this->assertSame([1 => 'R', 2 => 'T', 12 => 'AN'], array_intersect_key($layout['months'], [1 => 1, 2 => 1, 12 => 1]));
        $this->assertSame('S', $layout['code_columns_by_month'][1]);
        $this->assertSame('AO', $layout['code_columns_by_month'][12]);

        // The header block (parent headings merged over rows 11-13) is not
        // mistaken for blank data lines.
        $this->assertGreaterThanOrEqual($layout['header_row'] + 3, $layout['data_start_row']);
    }

    public function test_the_shipped_template_fills_month_grained_rows(): void
    {
        $bytes = $this->shippedBytes();
        $layout = $this->shippedLayout($bytes);

        $filled = WfpTemplateFiller::fill($bytes, [
            ['month' => 1, 'program_activity' => 'Pay rent', 'financial_target' => 8000, 'account_code' => '5020101000'],
            ['month' => 2, 'program_activity' => 'Buy supplies', 'financial_target' => 1500, 'account_code' => '5020301000'],
        ], $layout);

        $sheet = $this->wfpSheetXml($filled);

        // January lands in R (the January financial column), February in T —
        // never both in one column.
        $this->assertSame('8000.00', $this->wfpCellText($sheet, 'R14'));
        $this->assertSame('1500.00', $this->wfpCellText($sheet, 'T15'));
        $this->assertSame('Pay rent', $this->wfpCellText($sheet, 'B14'));
        $this->assertSame('5020101000', $this->wfpCellText($sheet, 'S14'));

        // The Title block, the header block and the Total row are the form's
        // own and must survive a fill.
        $this->assertSame('CY 2026 Work and Financial Plan', $this->wfpCellText($sheet, 'A9'));
        $this->assertSame('Major Outputs', $this->wfpCellText($sheet, 'C11'));
        $this->assertSame('Object Code', $this->wfpCellText($sheet, 'S12'));
        $this->assertSame('SUM(R14:R177)', $this->wfpCellFormula($sheet, 'R178'));
        $this->assertSame('SUM(R178:AN178)', $this->wfpCellFormula($sheet, 'AP178'));
    }

    public function test_cloned_rows_total_their_own_months(): void
    {
        $bytes = $this->shippedBytes();
        $layout = $this->shippedLayout($bytes);

        // One line more than the form's blank band, so the last line is a clone.
        $rows = [];

        foreach (range(1, count($layout['data_rows']) + 1) as $i) {
            $rows[] = ['program_activity' => 'Line '.$i, 'financial_target' => 100];
        }

        $filled = WfpTemplateFiller::fill($bytes, $rows, $layout);
        $sheet = $this->wfpSheetXml($filled);

        $cloneRow = max($layout['data_rows']) + 1;

        $this->assertSame('Line '.count($rows), $this->wfpCellText($sheet, 'B'.$cloneRow));
        $this->assertSame('SUM(E'.$cloneRow.':P'.$cloneRow.')', $this->wfpCellFormula($sheet, 'Q'.$cloneRow));
        $this->assertSame('SUM(R'.$cloneRow.':AN'.$cloneRow.')', $this->wfpCellFormula($sheet, 'AP'.$cloneRow));

        // The Total row moved below the clone and still spans the whole band.
        $this->assertSame('Total', $this->wfpCellText($sheet, 'A'.($layout['total_row'] + 1)));
        $this->assertSame('SUM(R14:R'.$cloneRow.')', $this->wfpCellFormula($sheet, 'R'.($layout['total_row'] + 1)));
    }

    public function test_it_writes_single_cells_and_keeps_their_style(): void
    {
        $bytes = $this->wfpTemplateBytes(3);

        // A1 already exists (styled 1); C2 does not exist at all and its row
        // is empty, so it must be created in column order.
        $written = WfpTemplateFiller::writeCells($bytes, ['A1' => 'BALISONG ELEMENTARY SCHOOL', 'C2' => 'Stamped']);
        $sheet = $this->wfpSheetXml($written);

        $this->assertSame('BALISONG ELEMENTARY SCHOOL', $this->wfpCellText($sheet, 'A1'));
        $this->assertSame('1', $this->wfpCellStyle($sheet, 'A1'));

        $this->assertSame('Stamped', $this->wfpCellText($sheet, 'C2'));

        // Cells stay in ascending column order (Excel reads that literally).
        $this->assertMatchesRegularExpression('/<row r="2"[^>]*>\s*<c r="C2"/', $sheet);

        // Writing a title-block cell does not disturb the table.
        $layout = WfpTemplateFiller::locate(WfpTemplateFiller::readParts($written));
        $this->assertSame(3, $layout['header_row']);
        $this->assertSame(7, $layout['total_row']);
        $this->assertSame('Objectives', $this->wfpCellText($sheet, 'B3'));
    }

    public function test_writing_no_cells_returns_the_workbook_untouched(): void
    {
        $bytes = $this->wfpTemplateBytes(3);

        $this->assertSame($bytes, WfpTemplateFiller::writeCells($bytes, []));
    }

    public function test_the_shipped_template_takes_the_school_name_into_its_title_block(): void
    {
        $bytes = $this->shippedBytes();

        $written = WfpTemplateFiller::writeCells($bytes, ['A6' => 'BALISONG ELEMENTARY SCHOOL']);
        $sheet = $this->wfpSheetXml($written);

        $this->assertSame('BALISONG ELEMENTARY SCHOOL', $this->wfpCellText($sheet, 'A6'));

        // The lines around it are the form's own and stay as shipped: the
        // region/division/address rows are blank, the title is not touched.
        $this->assertSame('', (string) $this->wfpCellText($sheet, 'A5'));
        $this->assertSame('', (string) $this->wfpCellText($sheet, 'A7'));
        $this->assertSame('CY 2026 Work and Financial Plan', $this->wfpCellText($sheet, 'A9'));
    }

    /** The shipped template's bytes, or a skip when it is not present. */
    private function shippedBytes(): string
    {
        $path = storage_path('forms/WFP-Template.xlsx');

        if (! is_file($path)) {
            $this->markTestSkipped('The shipped WFP template is not present.');
        }

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function shippedLayout(?string $bytes = null): array
    {
        return WfpTemplateFiller::locate(WfpTemplateFiller::readParts($bytes ?? $this->shippedBytes()));
    }
}
