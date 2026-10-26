<?php

namespace Tests\Unit;

use App\Support\PurePhpZipWriter;
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

        // The TOTAL formula still spans the surviving data band…
        $this->assertSame('SUM(F4:F5)', $this->wfpCellFormula($sheet, 'F6'));
        $this->assertSame('SUM(H4:H5)', $this->wfpCellFormula($sheet, 'H6'));

        // …the unused third line has been trimmed, the merge outside the band
        // is intact, and the dimension covers what is left.
        $this->assertStringNotContainsString('<row r="7"', $sheet);
        $this->assertStringContainsString('<mergeCell ref="A1:M1"/>', $sheet);
        $this->assertStringContainsString('<dimension ref="A1:M6"/>', $sheet);
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

        // The header block is untouched; the TOTAL row moved up under the two
        // filled lines and its range shrank to match.
        $this->assertSame('Financial Target (2025)', $this->wfpCellText($sheet, 'G3'));
        $this->assertSame('Object Code', $this->wfpCellText($sheet, 'H4'));
        $this->assertSame('Jan', $this->wfpCellText($sheet, 'C5'));
        $this->assertSame('SUM(G6:G7)', $this->wfpCellFormula($sheet, 'G8'));
        $this->assertStringNotContainsString('<row r="9"', $sheet);
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

        // No lines: the band is trimmed to its one working row, and that row
        // is cleared by the blank-out pass — except for the template's own
        // computed cell, which must survive rather than be hard-coded away.
        $filled = WfpTemplateFiller::fill($bytes, [], $layout);
        $sheet = $this->wfpSheetXml($filled);

        $this->assertSame('SUM(G6:J6)', $this->wfpCellFormula($sheet, 'K6'));
        $this->assertSame('', $this->wfpCellText($sheet, 'G6'));
    }

    public function test_a_hidden_template_line_is_shown_when_it_carries_data(): void
    {
        $bytes = $this->wfpMonthlyTemplateBytes(3);

        // The shipped template hides some of its blank lines (row 15, where
        // the first generated line lands, is hidden), so a fill must lift the
        // flag on any line it writes into.
        $parts = WfpTemplateFiller::readParts($bytes);
        $parts['xl/worksheets/sheet1.xml'] = str_replace(
            '<row r="6">',
            '<row r="6" hidden="1">',
            $parts['xl/worksheets/sheet1.xml'],
        );
        $bytes = PurePhpZipWriter::create($parts);
        $layout = WfpTemplateFiller::locate(WfpTemplateFiller::readParts($bytes));

        $filled = WfpTemplateFiller::fill($bytes, [
            ['month' => 1, 'program_activity' => 'Pay rent', 'financial_target' => 8000, 'account_code' => '5020301000'],
        ], $layout);
        $sheet = $this->wfpSheetXml($filled);

        $this->assertDoesNotMatchRegularExpression('/<row\b(?=[^>]*\br="6")[^>]*\bhidden="1"/', $sheet);
        $this->assertSame('Pay rent', $this->wfpCellText($sheet, 'B6'));
        $this->assertSame('8000.00', $this->wfpCellText($sheet, 'G6'));
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

        // The Title block and the header block are the form's own and must
        // survive a fill.
        $this->assertSame('CY 2026 Work and Financial Plan', $this->wfpCellText($sheet, 'A9'));
        $this->assertSame('Major Outputs', $this->wfpCellText($sheet, 'C11'));
        $this->assertSame('Object Code', $this->wfpCellText($sheet, 'S12'));

        // The form is trimmed to the two lines written: the TOTAL row sits
        // under them, its ranges cover exactly them, the template's hidden
        // flags are gone from what is left, and the signature block moved up
        // with the cut.
        $this->assertSame('Total', $this->wfpCellText($sheet, 'A16'));
        $this->assertSame('SUM(R14:R15)', $this->wfpCellFormula($sheet, 'R16'));
        $this->assertSame('SUM(R16:AN16)', $this->wfpCellFormula($sheet, 'AP16'));

        // Q, AP and AT were shared-formula DEPENDENTS in the template, whose
        // masters sat in the band that was removed. They must come out as
        // ordinary formulas at the TOTAL row's new position: a dependent whose
        // master is gone makes Excel repair the workbook (and discard cell
        // information with it).
        $this->assertSame('SUM(E16:P16)', $this->wfpCellFormula($sheet, 'Q16'));
        $this->assertSame('AP16', $this->wfpCellFormula($sheet, 'AT16'));
        $this->assertStringNotContainsString('t="shared"', $sheet);
        $this->assertSheetCellsAreConsistent($sheet);

        $this->assertDoesNotMatchRegularExpression('/<row\b[^>]*\bhidden="1"/', $sheet);
        $this->assertSame('Prepared by:', $this->wfpCellText($sheet, 'B19'));

        // The template's saved view points at row 32 of the blank band that
        // was just removed. With rows 1-13 frozen, Excel would reopen the
        // form on an empty grid and hide the filled line above the viewport,
        // so the view must open on the first data row instead.
        $this->assertMatchesRegularExpression('/<pane\b[^>]*\bySplit="13"[^>]*\btopLeftCell="A14"/', $sheet);
        $this->assertMatchesRegularExpression('/<selection\b[^>]*\bpane="bottomLeft"[^>]*\bsqref="A14"/', $sheet);

        // The print area shrank with the sheet, so the form prints only what
        // it actually holds instead of the template's 190-row band.
        $this->assertStringContainsString(
            'WFP!$A$1:$AV$28',
            WfpTemplateFiller::readParts($filled)['xl/workbook.xml'],
        );

        // The template's cached totals predate this fill; without a calcChain
        // Excel must be told to recalculate them on open.
        $this->assertStringContainsString(
            'fullCalcOnLoad="1"',
            WfpTemplateFiller::readParts($filled)['xl/workbook.xml'],
        );
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

        // Its per-month row totals moved with it: the formulas used to be
        // shared-formula dependents, which Excel resolved by position. Expanded
        // formulas must be shifted to the TOTAL row's new place instead.
        $totalRow = $layout['total_row'] + 1;
        $this->assertSame('SUM(E'.$totalRow.':P'.$totalRow.')', $this->wfpCellFormula($sheet, 'Q'.$totalRow));
        $this->assertSame('SUM(R'.$totalRow.':AN'.$totalRow.')', $this->wfpCellFormula($sheet, 'AP'.$totalRow));
        $this->assertSame('AP'.$totalRow, $this->wfpCellFormula($sheet, 'AT'.$totalRow));
        $this->assertStringNotContainsString('t="shared"', $sheet);
        $this->assertSheetCellsAreConsistent($sheet);

        // The print area grew with the sheet, so the clone is on the printout.
        $this->assertStringContainsString(
            'WFP!$A$1:$AV$'.(190 + 1),
            WfpTemplateFiller::readParts($filled)['xl/workbook.xml'],
        );

        // Clones of a hidden template line are shown too.
        $this->assertDoesNotMatchRegularExpression('/<row\b[^>]*\bhidden="1"/', $sheet);
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

        // The lines around it are the form's own and stay as shipped: a bare
        // cell write does not stamp the generated form's letterhead, and the
        // title is not touched.
        $this->assertSame('', (string) $this->wfpCellText($sheet, 'A5'));
        $this->assertSame('', (string) $this->wfpCellText($sheet, 'A7'));
        $this->assertSame('CY 2026 Work and Financial Plan', $this->wfpCellText($sheet, 'A9'));
    }

    public function test_the_title_cells_compose_the_letterhead_school_and_year(): void
    {
        $cells = WfpTemplateFiller::titleCells('BALISONG ELEMENTARY SCHOOL', 2027);

        $this->assertSame('Negros Island Region', $cells['A4'] ?? null);
        $this->assertSame('Schools Division Office of Kabankalan City', $cells['A5'] ?? null);
        $this->assertSame('BALISONG ELEMENTARY SCHOOL', $cells['A6'] ?? null);
        $this->assertSame('CY 2027 Work and Financial Plan', $cells['A9'] ?? null);
        $this->assertSame('Physical Target (2027)', $cells['E11'] ?? null);
        $this->assertSame('Financial Target (2027)', $cells['R11'] ?? null);
    }

    public function test_generated_lines_are_white_unbold_and_wrapped_but_the_account_title_stays_gray(): void
    {
        $bytes = $this->shippedBytes();
        $layout = $this->shippedLayout($bytes);

        $filled = WfpTemplateFiller::fill($bytes, [
            [
                '__account_title__' => true,
                'account_title' => 'Training Expense',
                'account_code' => '5020301000',
            ],
            [
                'program_activity' => 'To provide snacks for the training participants of the school-based training on action research',
                'financial_target' => 2500,
                'account_code' => '5020301000',
            ],
        ], $layout);

        $parts = WfpTemplateFiller::readParts($filled);
        $sheet = $parts['xl/worksheets/sheet1.xml'] ?? '';
        $styles = $parts['xl/styles.xml'] ?? '';

        $this->assertNotFalse(simplexml_load_string($styles));

        // The account-title row keeps the template's gray, bold section style.
        $titleStyle = (string) $this->wfpCellStyle($sheet, 'A14');

        $this->assertTrue($this->wfpStyleIsBold($styles, $titleStyle));
        $this->assertStringContainsString('FFB7B7B7', $this->wfpStyleFillXml($styles, $titleStyle));

        // The activity line is white, not bold and wrapped.
        $dataStyle = (string) $this->wfpCellStyle($sheet, 'B15');

        $this->assertFalse($this->wfpStyleIsBold($styles, $dataStyle));
        $this->assertSame('0', $this->wfpStyleFill($styles, $dataStyle));
        $this->assertTrue($this->wfpStyleWraps($styles, $dataStyle));

        // Nor do any other cells on that line: the template's yellow and gray
        // fills and bold columns must not leak into a generated line.
        preg_match('/<row\b[^>]*\br="15"[^>]*>(.*?)<\/row>/s', $sheet, $row);

        $this->assertArrayHasKey(1, $row);

        preg_match_all('/<c\b[^>]*\bs="(\d+)"/', $row[1], $cellStyles);

        $this->assertNotEmpty($cellStyles[1]);

        foreach ($cellStyles[1] as $styleId) {
            $this->assertSame('0', $this->wfpStyleFill($styles, $styleId), 'cell style '.$styleId.' carries a fill');
            $this->assertFalse($this->wfpStyleIsBold($styles, $styleId), 'cell style '.$styleId.' is bold');
        }

        // A formula the template owns survives, but its bold print does not.
        $this->assertSame('SUM(R15:AN15)', $this->wfpCellFormula($sheet, 'AP15'));
        $this->assertFalse($this->wfpStyleIsBold($styles, (string) $this->wfpCellStyle($sheet, 'AP15')));

        // The long description wraps over several lines; the row height was set
        // to fit it, without pinning `customHeight` (Excel may re-fit an edit).
        preg_match('/<row\b(?=[^>]*\br="15")[^>]*\bht="([0-9.]+)"/', $sheet, $height);

        $this->assertArrayHasKey(1, $height);
        $this->assertGreaterThan(21.0, (float) $height[1]);
        $this->assertLessThanOrEqual(409.0, (float) $height[1]);
        $this->assertDoesNotMatchRegularExpression('/<row\b(?=[^>]*\br="15")[^>]*\bcustomHeight=/', $sheet);
    }

    public function test_a_section_row_landing_on_a_tall_template_row_is_reset_to_one_line(): void
    {
        $bytes = $this->shippedBytes();
        $layout = $this->shippedLayout($bytes);

        $filled = WfpTemplateFiller::fill($bytes, [
            ['__account_title__' => true, 'account_title' => 'Electricity Expense', 'account_code' => '5020402000'],
            ['program_activity' => 'One'],
            ['program_activity' => 'Two'],
            ['program_activity' => 'Three'],
            ['program_activity' => 'Four'],
            ['__account_title__' => true, 'account_title' => 'Water Expense', 'account_code' => '5020403000'],
            ['program_activity' => 'Five'],
        ], $layout);

        $parts = WfpTemplateFiller::readParts($filled);
        $sheet = $parts['xl/worksheets/sheet1.xml'] ?? '';
        $styles = $parts['xl/styles.xml'] ?? '';

        // Row 19 shipped in the template at 102pt for a one-line section.
        preg_match('/<row\b[^>]*\br="19"[^>]*>/', $sheet, $row);

        $this->assertArrayHasKey(0, $row);
        $this->assertStringNotContainsString('customHeight', $row[0]);
        $this->assertMatchesRegularExpression('/\bht="([0-9.]+)"/', $row[0], 'the section row has a height');

        preg_match('/\bht="([0-9.]+)"/', $row[0], $height);
        $this->assertLessThan(22.0, (float) $height[1]);

        // It is still the gray, bold section row.
        $titleStyle = (string) $this->wfpCellStyle($sheet, 'A19');

        $this->assertSame('Water Expense', $this->wfpCellText($sheet, 'A19'));
        $this->assertTrue($this->wfpStyleIsBold($styles, $titleStyle));
        $this->assertStringContainsString('FFB7B7B7', $this->wfpStyleFillXml($styles, $titleStyle));

        // Its preserved totals are painted with the same gray, not left yellow.
        foreach (['AP19', 'AQ19', 'AT19'] as $ref) {
            $this->assertSame($titleStyle, (string) $this->wfpCellStyle($sheet, $ref));
        }

        $this->assertSame('SUM(R19:AN19)', $this->wfpCellFormula($sheet, 'AP19'));
        $this->assertNull($this->wfpCellFormula($sheet, 'AQ19'));
        $this->assertSame('AP19', $this->wfpCellFormula($sheet, 'AT19'));

        // The line after it is a normal generated line again.
        $this->assertFalse($this->wfpStyleIsBold($styles, (string) $this->wfpCellStyle($sheet, 'B20')));
        $this->assertSame('0', $this->wfpStyleFill($styles, (string) $this->wfpCellStyle($sheet, 'B20')));
    }

    /**
     * Every cell reference must be unique and must sit in the row element that
     * owns it: Excel treats a duplicate or a mismatched reference as unreadable
     * content and repairs the whole sheet.
     */
    private function assertSheetCellsAreConsistent(string $sheet): void
    {
        preg_match_all('/<row\b[^>]*\br="(\d+)"[^>]*>.*?<\/row>/s', $sheet, $rows, PREG_SET_ORDER);

        $seen = [];

        foreach ($rows as $row) {
            $number = (int) $row[1];
            preg_match_all('/<c\b[^>]*\br="([A-Z]+)(\d+)"/', $row[0], $cells, PREG_SET_ORDER);

            foreach ($cells as $cell) {
                $ref = $cell[1].$cell[2];

                $this->assertSame($number, (int) $cell[2], 'cell '.$ref.' sits inside row '.$number);
                $this->assertArrayNotHasKey($ref, $seen, 'cell '.$ref.' is repeated');
                $seen[$ref] = true;
            }
        }
    }

    /** The style's full `<xf>` element from the workbook's styles.xml. */
    private function wfpStyleXf(string $styles, string $styleId): string
    {
        if (preg_match('/<cellXfs\b[^>]*>(.*?)<\/cellXfs>/s', $styles, $block) !== 1) {
            return '';
        }

        if (preg_match_all('/<xf\b[^>]*\/>|<xf\b[^>]*>.*?<\/xf>/s', $block[1], $xfs) === 0) {
            return '';
        }

        return $xfs[0][(int) $styleId] ?? '';
    }

    private function wfpStyleIsBold(string $styles, string $styleId): bool
    {
        $xf = $this->wfpStyleXf($styles, $styleId);

        if (preg_match('/\bfontId="(\d+)"/', $xf, $fontId) !== 1) {
            return false;
        }

        if (preg_match('/<fonts\b[^>]*>(.*?)<\/fonts>/s', $styles, $block) !== 1) {
            return false;
        }

        if (preg_match_all('/<font\b[^>]*>.*?<\/font>|<font\b[^>]*\/>/s', $block[1], $fonts) === 0) {
            return false;
        }

        $font = $fonts[0][(int) $fontId[1]] ?? '';

        return preg_match('/<b\b(?![^>]*\bval="(?:0|false)")/i', $font) === 1;
    }

    private function wfpStyleFill(string $styles, string $styleId): string
    {
        return preg_match('/\bfillId="(\d+)"/', $this->wfpStyleXf($styles, $styleId), $fill) === 1
            ? $fill[1]
            : '';
    }

    /** The style's full `<fill>` element, for asserting the actual colour. */
    private function wfpStyleFillXml(string $styles, string $styleId): string
    {
        $fillId = $this->wfpStyleFill($styles, $styleId);

        if ($fillId === '' || preg_match('/<fills\b[^>]*>(.*?)<\/fills>/s', $styles, $block) !== 1) {
            return '';
        }

        if (preg_match_all('/<fill\b[^>]*>.*?<\/fill>/s', $block[1], $fills) === 0) {
            return '';
        }

        return $fills[0][(int) $fillId] ?? '';
    }

    private function wfpStyleWraps(string $styles, string $styleId): bool
    {
        return str_contains($this->wfpStyleXf($styles, $styleId), 'wrapText="1"');
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
