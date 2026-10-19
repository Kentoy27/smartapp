<?php

namespace Tests\Unit;

use App\Models\District;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Support\MemWorkbookImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMemWorkbooks;
use Tests\TestCase;

class MemWorkbookImporterTest extends TestCase
{
    use BuildsMemWorkbooks;
    use RefreshDatabase;

    private function school(): School
    {
        $district = District::create(['name' => 'District 1']);

        return School::create(['district_id' => $district->id, 'name' => 'Test School']);
    }

    /**
     * @param  array<string, array<int, array<string, string>>>  $sheets
     */
    private function workbookPath(array $sheets, bool $splitHeader = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mem-wb-').'.xlsx';
        file_put_contents($path, $this->memWorkbookBytes($sheets, $splitHeader));

        return $path;
    }

    /**
     * The School Head's workbook splits its header over two rows: the parent
     * labels (Objectives, Project/Activity/Expenditure, Resources Needed,
     * Account Title, Account Code) on one and the "Resources Needed"
     * sub-labels (Kind, Quantity, Unit, Unit Cost) on the next. Reading only
     * the row that looks most like a header mapped half the columns and
     * rejected every line as "Activity Description is required".
     */
    public function test_it_reads_a_header_spread_over_two_rows(): void
    {
        $school = $this->school();

        $path = $this->workbookPath([
            'AUG' => [
                ['__section__' => 'Operations-Related Expenses'],
                [
                    'objective' => 'to secure copy of confirmation letter of Fidelity Bond',
                    'activity_description' => 'Reimbursement of travelling expenses of School Head',
                    'resource_kind' => 'Itinerary of Travel w/ complete supporting documents',
                    'quantity' => '1',
                    'unit' => 'pax',
                    'unit_cost' => '1500',
                    'account_title' => 'Travelling Expense (Local)',
                    'account_code' => '5020101000',
                ],
            ],
        ], splitHeader: true);

        $analysis = MemWorkbookImporter::analyzeWorkbook($path, $school->id, 2026);

        $this->assertSame(1, $analysis['validCount']);
        $this->assertSame(0, $analysis['invalidCount']);

        $row = $analysis['valid'][8][0];
        $this->assertSame('to secure copy of confirmation letter of Fidelity Bond', $row['objective']);
        $this->assertSame('Reimbursement of travelling expenses of School Head', $row['activity_description']);
        $this->assertSame('Itinerary of Travel w/ complete supporting documents', $row['resource_kind']);
        $this->assertSame('pax', $row['unit']);
        $this->assertSame('Travelling Expense (Local)', $row['account_title']);
        $this->assertSame('5020101000', $row['account_code']);
        $this->assertEquals(1500.0, $analysis['months'][8]['total']);

        // The sub-label row is part of the header, so it is not read as a line.
        $this->assertSame(1, count($analysis['months'][8]['rows']));

        unlink($path);
    }

    public function test_it_reads_each_month_sheet_and_skips_banners_and_total_rows(): void
    {
        $school = $this->school();

        $path = $this->workbookPath([
            'JAN' => [
                ['__section__' => 'Operations-Related Expenses'],
                ['activity_description' => 'Procure supplies', 'quantity' => '10', 'unit' => 'ream', 'unit_cost' => '250', 'account_title' => 'Office Supplies', 'account_code' => '5020301000'],
                ['activity_description' => 'TOTAL', 'quantity' => '', 'unit_cost' => ''],
            ],
            'FEB' => [
                ['activity_description' => 'Build a fence', 'quantity' => '5', 'unit_cost' => '100', 'account_code' => '5021301000'],
            ],
            'uacs MOOE partial' => [
                ['activity_description' => 'Not a month'],
            ],
        ]);

        $analysis = MemWorkbookImporter::analyzeWorkbook($path, $school->id, 2026);

        $this->assertSame([1, 2], array_keys($analysis['months']));

        // The banner, the TOTAL row and the non-month sheet are not lines.
        $this->assertSame(2, $analysis['validCount']);
        $this->assertSame(0, $analysis['invalidCount']);

        $january = $analysis['valid'][1];
        $this->assertCount(1, $january);
        $this->assertSame('operations', $january[0]['section']); // inherited from the banner
        $this->assertSame('Procure supplies', $january[0]['activity_description']);
        $this->assertSame('5020301000', $january[0]['account_code']);
        $this->assertEquals(2500.0, $analysis['months'][1]['total']);

        unlink($path);
    }

    public function test_a_gray_banner_switches_the_section_for_the_lines_below(): void
    {
        $school = $this->school();

        $path = $this->workbookPath([
            'JAN' => [
                ['__section__' => 'Operations-Related Expenses'],
                ['activity_description' => 'Supplies', 'quantity' => '1', 'unit_cost' => '10'],
                ['__section__' => 'Project-Related Expenses'],
                ['activity_description' => 'Fence', 'quantity' => '1', 'unit_cost' => '20'],
            ],
        ]);

        $analysis = MemWorkbookImporter::analyzeWorkbook($path, $school->id, 2026);

        $rows = $analysis['valid'][1];
        $this->assertSame('operations', $rows[0]['section']);
        $this->assertSame('project', $rows[1]['section']);

        unlink($path);
    }

    /**
     * The sheet merges the Objective and Project/Activity cells over the
     * resource lines that share them, so the words appear once and the lines
     * under them have none of their own. Such a line still belongs to that
     * activity — it is not an empty line.
     */
    public function test_a_merged_objective_and_activity_carry_down_to_the_next_line(): void
    {
        $school = $this->school();

        $path = $this->workbookPath([
            'JAN' => [
                ['__section__' => 'Operations-Related Expenses'],
                ['objective' => 'To equip the school', 'activity_description' => 'Procurement of supplies', 'resource_kind' => 'ribbon', 'quantity' => '1', 'unit' => 'roll', 'unit_cost' => '300', 'account_code' => '5020399000'],
                ['resource_kind' => 'Medals', 'quantity' => '50', 'unit' => 'pcs', 'unit_cost' => '25', 'account_code' => '5020399000'],
            ],
        ]);

        $analysis = MemWorkbookImporter::analyzeWorkbook($path, $school->id, 2026);

        $this->assertSame(2, $analysis['validCount']);
        $this->assertSame(0, $analysis['invalidCount']);

        $continuation = $analysis['valid'][1][1];
        $this->assertSame('To equip the school', $continuation['objective']);
        $this->assertSame('Procurement of supplies', $continuation['activity_description']);
        $this->assertSame('Medals', $continuation['resource_kind']);
        $this->assertEquals(1550.0, $analysis['months'][1]['total']);

        unlink($path);
    }

    /**
     * The School Head may write the money straight into the sheet's own Total
     * Estimated Cost column and leave the unit cost blank ("1 pc, 3,840"). The
     * line is stored as Quantity × Unit Cost, so the unit cost is derived back
     * from the total — otherwise the line imports at zero and the month stops
     * adding up to the sheet's Subtotal.
     */
    public function test_a_line_that_states_only_its_total_keeps_its_money(): void
    {
        $school = $this->school();

        $path = $this->workbookPath([
            'JAN' => [
                [
                    'activity_description' => 'Procurement of Tarpaulin',
                    'quantity' => '1',
                    'unit' => 'pc',
                    'account_title' => 'Printing & Publication Expense',
                    'account_code' => '5029902000',
                    'calculated_total' => '3840',
                ],
            ],
        ], splitHeader: true);

        $analysis = MemWorkbookImporter::analyzeWorkbook($path, $school->id, 2026);

        $this->assertSame(1, $analysis['validCount']);
        $this->assertEquals(3840.0, (float) $analysis['valid'][1][0]['unit_cost']);
        $this->assertEquals(3840.0, $analysis['months'][1]['total']);

        unlink($path);
    }

    /**
     * A month sheet carries a signatories block and a per-account summary list
     * beneath its table. Those rows are not lines the School Head entered, and
     * reporting them as broken lines buries the rows that really do need
     * attention.
     */
    public function test_rows_below_the_last_coded_line_are_not_read_as_lines(): void
    {
        $school = $this->school();

        $path = $this->workbookPath([
            'JAN' => [
                ['activity_description' => 'Supplies', 'quantity' => '1', 'unit_cost' => '10', 'account_code' => '5020301000'],
                ['objective' => 'Prepared by:', 'resource_kind' => 'Checked as the Correctness of'],
                ['objective' => 'JOE MARIE G. CANLOG', 'account_title' => 'Approved:'],
            ],
        ]);

        $analysis = MemWorkbookImporter::analyzeWorkbook($path, $school->id, 2026);

        $this->assertSame(1, $analysis['validCount']);
        $this->assertSame(0, $analysis['invalidCount']);
        $this->assertCount(1, $analysis['months'][1]['rows']);

        unlink($path);
    }

    public function test_it_normalizes_and_flags_a_short_account_code(): void
    {
        $school = $this->school();

        $path = $this->workbookPath([
            'JAN' => [
                ['activity_description' => 'Line', 'quantity' => '1', 'unit_cost' => '10', 'account_code' => '502039900'],
            ],
        ]);

        $analysis = MemWorkbookImporter::analyzeWorkbook($path, $school->id, 2026);

        // 9 digits left-padded to the configured 10.
        $this->assertSame('0502039900', $analysis['valid'][1][0]['account_code']);
        $this->assertStringContainsString('padded', implode(' ', $analysis['warnings']));

        unlink($path);
    }

    public function test_it_flags_the_workbook_when_no_month_sheets_are_present(): void
    {
        $school = $this->school();

        $path = $this->workbookPath([
            'uacs MOOE partial' => [['activity_description' => 'x']],
        ]);

        $analysis = MemWorkbookImporter::analyzeWorkbook($path, $school->id, 2026);

        $this->assertSame([], $analysis['months']);
        $this->assertSame(0, $analysis['validCount']);
        $this->assertStringContainsString('No monthly sheets', implode(' ', $analysis['warnings']));

        unlink($path);
    }

    public function test_commit_creates_the_year_and_fills_only_the_present_months(): void
    {
        $school = $this->school();

        // A July line that predates the import and must survive (July has no sheet).
        $july = MonthlyExpenditureMatrix::create([
            'school_id' => $school->id,
            'year' => 2026,
            'month' => 7,
            'status' => MonthlyExpenditureMatrix::STATUS_FINALIZED,
            'version' => 3,
        ]);
        $july->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Existing line',
        ]);

        $path = $this->workbookPath([
            'JAN' => [
                ['activity_description' => 'Supplies', 'quantity' => '10', 'unit_cost' => '250', 'account_code' => '5020301000'],
            ],
            'FEB' => [
                ['activity_description' => 'Fence', 'quantity' => '5', 'unit_cost' => '100', 'account_code' => '5021301000'],
            ],
        ]);

        $analysis = MemWorkbookImporter::analyzeWorkbook($path, $school->id, 2026);
        $result = MemWorkbookImporter::commit($analysis['valid'], $school->id, 2026, null);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $result['months']);

        // All twelve months now exist for the year.
        $this->assertSame(12, MonthlyExpenditureMatrix::where('school_id', $school->id)->where('year', 2026)->count());

        $january = MonthlyExpenditureMatrix::where('school_id', $school->id)->where('year', 2026)->where('month', 1)->firstOrFail();
        $this->assertSame(1, $january->items()->count());
        $this->assertEquals(2500.0, (float) $january->items()->firstOrFail()->calculated_total);
        // The version advanced, so a WFP generated from before now reads stale.
        $this->assertSame(2, (int) $january->version);

        // A month the workbook did not carry is untouched.
        $july->refresh();
        $this->assertSame('finalized', $july->status);
        $this->assertSame(3, $july->version);
        $this->assertSame(1, $july->items()->count());

        // A month it carried but that had nothing is present and empty (Feb is not — it has a line).
        $march = MonthlyExpenditureMatrix::where('school_id', $school->id)->where('year', 2026)->where('month', 3)->firstOrFail();
        $this->assertSame(0, $march->items()->count());

        unlink($path);
    }

    public function test_commit_replaces_rather_than_appends_on_a_reimport(): void
    {
        $school = $this->school();

        $path = $this->workbookPath([
            'JAN' => [
                ['activity_description' => 'Supplies', 'quantity' => '10', 'unit_cost' => '250'],
            ],
        ]);

        $analysis = MemWorkbookImporter::analyzeWorkbook($path, $school->id, 2026);
        MemWorkbookImporter::commit($analysis['valid'], $school->id, 2026, null);
        MemWorkbookImporter::commit($analysis['valid'], $school->id, 2026, null);

        $january = MonthlyExpenditureMatrix::where('school_id', $school->id)->where('year', 2026)->where('month', 1)->firstOrFail();

        // Still one line, not two — a month is replaced, not appended.
        $this->assertSame(1, $january->items()->count());

        unlink($path);
    }
}
