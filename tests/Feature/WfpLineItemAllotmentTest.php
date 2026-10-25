<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Livewire\WfpLineItemAllotment;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Support\PurePhpZipReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WfpLineItemAllotmentTest extends TestCase
{
    use RefreshDatabase;

    private function schoolHead(string $name = 'Allotment School'): User
    {
        $district = District::create(['name' => 'District '.$name]);
        $school = School::create(['district_id' => $district->id, 'name' => $name]);

        return User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => $school->id]);
    }

    /** One expenditure line, in its own month's matrix. */
    private function line(User $sh, int $month, array $attributes = [], int $year = 2026): MonthlyExpenditureItem
    {
        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $sh->school_id,
            'year' => $year,
            'month' => $month,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $sh->id,
        ]);

        return $matrix->items()->create(array_merge([
            'position' => 1,
            'section' => MonthlyExpenditureMatrix::SECTION_OPERATIONS,
            'activity_description' => 'Procure supplies',
            'quantity' => 10,
            'unit_cost' => 250,
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
        ], $attributes));
    }

    // ---------------------------------------------------------------- access

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('wfp.lineitems.index'))->assertRedirect(route('login'));
    }

    public function test_the_school_head_can_open_the_report(): void
    {
        $this->actingAs($this->schoolHead())
            ->get(route('wfp.lineitems.index'))
            ->assertOk()
            ->assertSeeLivewire('wfp-line-item-allotment')
            ->assertSee('Per Line Item Expenditure')
            // The Excel download travels with the year the reader is on.
            ->assertSee(route('wfp.lineitems.export', ['year' => (string) now()->year]), false);
    }

    public function test_non_school_head_roles_get_a_404(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $administrator = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $superadmin = User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);

        foreach ([$viewer, $administrator, $superadmin] as $user) {
            $this->actingAs($user)->get(route('wfp.lineitems.index'))->assertNotFound();
        }
    }

    public function test_the_component_refuses_a_guest_and_a_non_school_head(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);

        $this->assertStringContainsString('Page Not Found', Livewire::test(WfpLineItemAllotment::class)->html());
        $this->assertStringContainsString(
            'Page Not Found',
            Livewire::actingAs($viewer)->test(WfpLineItemAllotment::class)->html()
        );
    }

    // --------------------------------------------------------------- figures

    public function test_the_report_rolls_the_years_lines_up_per_account_code(): void
    {
        $sh = $this->schoolHead();

        // One code spent from twice in the year is ONE row with both months
        // in it — that is what makes this the allotment per line item.
        $this->line($sh, 3, ['quantity' => 100, 'unit_cost' => 100]);   // 10,000.00
        $this->line($sh, 9, ['quantity' => 50, 'unit_cost' => 100]);    //  5,000.00
        $this->line($sh, 1, [
            'quantity' => 53,
            'unit_cost' => 1000,
            'account_title' => 'Travelling Expense (Local)',
            'account_code' => '5020101000',
        ]); // 53,000.00

        $component = Livewire::actingAs($sh)->test(WfpLineItemAllotment::class);

        $rows = $component->instance()->lineItems;

        $this->assertCount(2, $rows);

        // Account-code order, with the whole year behind each row.
        $this->assertSame('5020101000', $rows[0]['code']);
        $this->assertSame('Travelling Expense (Local)', $rows[0]['title']);
        $this->assertSame(53000.0, $rows[0]['total']);
        $this->assertSame(77.94, $rows[0]['percent']);

        $this->assertSame('5020301000', $rows[1]['code']);
        $this->assertSame(15000.0, $rows[1]['total']);
        $this->assertSame(22.06, $rows[1]['percent']);

        $this->assertSame(68000.0, $component->instance()->grandTotal);

        // And the same report as the reader sees it: numbered rows, amounts
        // and shares, with the year's total under them.
        $component
            ->assertSee('Travelling Expense (Local)')
            ->assertSee('53,000.00')
            ->assertSee('77.94%')
            ->assertSee('15,000.00')
            ->assertSee('22.06%')
            ->assertSee('68,000.00');
    }

    public function test_an_uncoded_line_is_grouped_by_its_account_title(): void
    {
        $sh = $this->schoolHead();

        $this->line($sh, 4, ['account_code' => null, 'account_title' => 'Electricity Expenses', 'quantity' => 2, 'unit_cost' => 500]);
        $this->line($sh, 5, ['account_code' => null, 'account_title' => 'Electricity Expenses', 'quantity' => 3, 'unit_cost' => 500]);

        $rows = Livewire::actingAs($sh)->test(WfpLineItemAllotment::class)->instance()->lineItems;

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['code']);
        $this->assertSame('Electricity Expenses', $rows[0]['title']);
        $this->assertSame(2500.0, $rows[0]['total']);
    }

    public function test_each_month_reports_its_own_matrix_total(): void
    {
        $sh = $this->schoolHead();

        $this->line($sh, 1, ['quantity' => 53, 'unit_cost' => 1000]); // January
        $this->line($sh, 3, ['quantity' => 100, 'unit_cost' => 100]); // March

        $months = Livewire::actingAs($sh)->test(WfpLineItemAllotment::class)->instance()->months;

        $this->assertCount(12, $months);

        $this->assertSame('January', $months[0]['label']);
        $this->assertSame(53000.0, $months[0]['budget']);
        $this->assertTrue($months[0]['has_matrix']);

        $this->assertSame(10000.0, $months[2]['budget']);

        // A month with no matrix reads as zero rather than vanishing.
        $this->assertSame(0.0, $months[11]['budget']);
        $this->assertFalse($months[11]['has_matrix']);
    }

    public function test_another_schools_lines_are_never_in_the_report(): void
    {
        $mine = $this->schoolHead('My School');
        $other = $this->schoolHead('Other School');

        $this->line($mine, 1, ['quantity' => 1, 'unit_cost' => 100]);
        $this->line($other, 1, [
            'quantity' => 999,
            'unit_cost' => 1000,
            'account_title' => 'Someone Else’s Expense',
            'account_code' => '5029999999',
        ]);

        $component = Livewire::actingAs($mine)->test(WfpLineItemAllotment::class);

        $this->assertSame(100.0, $component->instance()->grandTotal);
        $component->assertDontSee('Someone Else’s Expense');
    }

    public function test_a_line_with_no_quantity_or_unit_cost_contributes_nothing(): void
    {
        $sh = $this->schoolHead();

        $this->line($sh, 1, ['quantity' => null, 'unit_cost' => null]);

        $component = Livewire::actingAs($sh)->test(WfpLineItemAllotment::class);

        $this->assertSame(0.0, $component->instance()->grandTotal);
        $this->assertSame(0.0, $component->instance()->lineItems[0]['percent']);
    }

    // ------------------------------------------------------------------ year

    public function test_the_report_opens_on_the_newest_year_the_school_has_a_matrix_for(): void
    {
        $sh = $this->schoolHead();

        $this->line($sh, 6, ['quantity' => 1, 'unit_cost' => 100], year: 2025);
        $this->line($sh, 6, ['quantity' => 1, 'unit_cost' => 900], year: 2026);

        $component = Livewire::actingAs($sh)->test(WfpLineItemAllotment::class);

        $this->assertSame('2026', $component->instance()->year);
        $this->assertSame(900.0, $component->instance()->grandTotal);

        // The year select switches the whole report.
        $component->set('year', '2025')->assertSee('100.00');
        $this->assertSame(100.0, $component->instance()->grandTotal);
    }

    public function test_a_year_with_no_matrices_falls_back_to_a_year_that_has_them(): void
    {
        $sh = $this->schoolHead();

        $this->line($sh, 6, ['quantity' => 1, 'unit_cost' => 900], year: 2026);

        $component = Livewire::actingAs($sh)->test(WfpLineItemAllotment::class)->set('year', '1999');

        $this->assertSame('2026', $component->instance()->year);
        $this->assertSame(900.0, $component->instance()->grandTotal);
    }

    // ---------------------------------------------------------------- export

    public function test_the_report_exports_as_the_printed_form_in_excel(): void
    {
        $sh = $this->schoolHead('Bino Elem School');

        $this->line($sh, 1, [
            'quantity' => 53,
            'unit_cost' => 1000,
            'account_title' => 'Travelling Expense (Local)',
            'account_code' => '5020101000',
        ]);
        $this->line($sh, 3, ['quantity' => 100, 'unit_cost' => 100]);
        $this->line($sh, 9, ['quantity' => 50, 'unit_cost' => 100]);

        $response = $this->actingAs($sh)->get(route('wfp.lineitems.export', ['year' => 2026]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('per-line-item-expenditure-2026.xlsx');

        $parts = $this->partsOf($response->getContent());

        // Every part must be WELL-FORMED XML: Excel repairs (and mangles) a
        // file whose styles part is malformed, which a zip-level check alone
        // would never catch.
        foreach ($parts as $name => $contents) {
            $this->assertNotFalse(simplexml_load_string($contents), $name.' is not well-formed XML');
        }

        $sheet = $parts['xl/worksheets/sheet1.xml'];
        $styles = $parts['xl/styles.xml'];

        // The form's own letterhead and title.
        $this->assertStringContainsString('REPUBLIC OF THE PHILIPPINES', $sheet);
        $this->assertStringContainsString('DEPARTMENT OF EDUCATION', $sheet);
        $this->assertStringContainsString('BINO ELEM SCHOOL', $sheet);
        $this->assertStringContainsString(
            '2026 ANNUAL MOOE ALLOTMENT ALLOCATED PER LINE ITEM OR OBJECT OF EXPENDITURE',
            $sheet
        );

        // Both tables' headings, the months, and the two totals.
        $this->assertStringContainsString('MONTH', $sheet);
        $this->assertStringContainsString('BUDGET', $sheet);
        $this->assertStringContainsString('JANUARY', $sheet);
        $this->assertStringContainsString('DECEMBER', $sheet);
        $this->assertStringContainsString('Grand Total', $sheet);

        // The year's lines rolled up per account code, in code order.
        $this->assertStringContainsString('Travelling Expense (Local)', $sheet);
        $this->assertStringContainsString('5020101000', $sheet);
        $this->assertLessThan(
            strpos($sheet, '5020301000'),
            strpos($sheet, '5020101000')
        );

        // Money as real NUMBERS a reviewer can sum, and each share as the
        // fraction the "0.00%" format multiplies back up.
        $this->assertStringContainsString('<v>53000</v>', $sheet);
        $this->assertStringContainsString('<v>15000</v>', $sheet);
        $this->assertStringContainsString('<v>68000</v>', $sheet);
        $this->assertStringContainsString('<v>0.7794</v>', $sheet);
        $this->assertStringContainsString('<v>0.2206</v>', $sheet);
        $this->assertStringContainsString('formatCode="0.00%"', $styles);

        // The signature block names the exporter as the prepared-by.
        $this->assertStringContainsString(strtoupper($sh->name), $sheet);
        $this->assertStringContainsString('Senior Bookkeeper', $sheet);
        $this->assertStringContainsString('Schools Division Superintendent', $sheet);
    }

    public function test_the_exported_columns_are_auto_fitted_to_their_content(): void
    {
        $shortTitle = 'Office Supplies';
        $longTitle = 'Office Supplies Expenses for All Grade Levels';

        $short = $this->fittedWidths($shortTitle, 'Short Title School');
        $long = $this->fittedWidths($longTitle, 'Long Title School');

        // The Account Title column is measured against what was written in it:
        // the longer title gets the wider column, and the two are not both left
        // sitting at the column's floor.
        $this->assertGreaterThan($short['E'], $long['E']);
        $this->assertGreaterThan(22.0, $long['E']);

        // Wide enough to show the title without the reader dragging an edge.
        $this->assertGreaterThanOrEqual(mb_strlen($longTitle), $long['E']);

        // The money column fits "1,234.50", and the two tables are separated
        // by a narrow spacer column.
        $this->assertGreaterThanOrEqual(mb_strlen('1,234.50'), $long['G']);
        $this->assertSame(2.6, $long['C']);
    }

    public function test_an_over_long_title_wraps_inside_the_column_ceiling(): void
    {
        $sh = $this->schoolHead();

        $this->line($sh, 1, ['account_title' => str_repeat('Very Long Account Title ', 4), 'account_code' => '5020301000']);

        $widths = $this->columnWidths($this->partsOf(
            $this->actingAs($sh)->get(route('wfp.lineitems.export'))->getContent()
        )['xl/worksheets/sheet1.xml']);

        // Capped, not as wide as the title: the cell wraps instead.
        $this->assertSame(46.0, $widths['E']);
        $this->assertStringContainsString('wrapText="1"', $this->partsOf(
            $this->actingAs($sh)->get(route('wfp.lineitems.export'))->getContent()
        )['xl/styles.xml']);
    }

    public function test_a_year_with_no_matrices_exports_the_newest_year_that_has_them(): void
    {
        $sh = $this->schoolHead();

        $this->line($sh, 6, ['quantity' => 1, 'unit_cost' => 900], year: 2026);

        $this->actingAs($sh)
            ->get(route('wfp.lineitems.export', ['year' => 1999]))
            ->assertOk()
            ->assertDownload('per-line-item-expenditure-2026.xlsx');
    }

    public function test_another_schools_figures_are_never_in_the_export(): void
    {
        $mine = $this->schoolHead('My School');
        $other = $this->schoolHead('Other School');

        $this->line($mine, 1, ['quantity' => 1, 'unit_cost' => 100]);
        $this->line($other, 1, [
            'account_title' => 'Someone Else’s Expense',
            'account_code' => '5029999999',
            'quantity' => 999,
            'unit_cost' => 1000,
        ]);

        $sheet = $this->partsOf(
            $this->actingAs($mine)->get(route('wfp.lineitems.export'))->getContent()
        )['xl/worksheets/sheet1.xml'];

        $this->assertStringNotContainsString('Someone Else’s Expense', $sheet);
        $this->assertStringNotContainsString('5029999999', $sheet);
        $this->assertStringContainsString('<v>100</v>', $sheet);
    }

    public function test_the_export_names_the_certifying_officer(): void
    {
        $sh = $this->schoolHead();
        $budget = User::factory()->create([
            'role' => 'budget',
            'is_superadmin' => false,
            'name' => 'Maria Budget',
            'position' => 'Budget Officer II',
        ]);

        SchoolAllotment::create([
            'school_id' => $sh->school_id,
            'year' => (int) now()->year,
            'amount' => 10000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        $sheet = $this->partsOf(
            $this->actingAs($sh)->get(route('wfp.lineitems.export'))->getContent()
        )['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('Certifying Availability of Allotment:', $sheet);
        $this->assertStringContainsString('MARIA BUDGET', $sheet);
        $this->assertStringContainsString('Budget Officer II', $sheet);
        $this->assertStringContainsString('Certified', $sheet);
    }

    public function test_a_guest_and_non_school_head_roles_cannot_export(): void
    {
        $this->get(route('wfp.lineitems.export'))->assertRedirect(route('login'));

        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $administrator = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $superadmin = User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);

        foreach ([$viewer, $administrator, $superadmin] as $user) {
            $this->actingAs($user)->get(route('wfp.lineitems.export'))->assertNotFound();
        }
    }

    public function test_a_school_head_with_no_school_has_nothing_to_export(): void
    {
        $sh = User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => null]);

        $this->actingAs($sh)->get(route('wfp.lineitems.export'))->assertNotFound();
    }

    // --------------------------------------------------------------- sidebar

    public function test_the_sidebar_offers_the_report_at_the_bottom_of_the_wfp_group(): void
    {
        $html = Livewire::actingAs($this->schoolHead())->test(Sidebar::class)->html();

        $this->assertStringContainsString(route('wfp.lineitems.index'), $html);

        // Last of the group's pages: the report sits under the matrix and the
        // plan it is derived from.
        $this->assertGreaterThan(
            strpos($html, route('wfp.matrices.index')),
            strpos($html, route('wfp.lineitems.index'))
        );
    }

    public function test_the_superadmin_sees_no_report_entry_in_the_sidebar(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);

        Livewire::actingAs($superadmin)
            ->test(Sidebar::class)
            ->assertDontSee(route('wfp.lineitems.index'), false);
    }

    // --------------------------------------------------------------- helpers

    /**
     * The workbook's parts, by name, read back out of the response bytes —
     * through the same pure-PHP reader the app itself falls back on.
     *
     * @return array<string, string>
     */
    private function partsOf(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'allotment-').'.xlsx';
        file_put_contents($path, $bytes);

        $parts = (new PurePhpZipReader($path))->readAll();

        unlink($path);

        return $parts;
    }

    /**
     * Export one school's report whose only line carries this title, and hand
     * back the widths its columns were fitted to.
     *
     * @return array<string, float>
     */
    private function fittedWidths(string $accountTitle, string $schoolName): array
    {
        $sh = $this->schoolHead($schoolName);

        $this->line($sh, 1, [
            'account_title' => $accountTitle,
            'account_code' => '5020301000',
            'quantity' => 100,
            'unit_cost' => 1234.5,
        ]);

        $sheet = $this->partsOf(
            $this->actingAs($sh)->get(route('wfp.lineitems.export'))->getContent()
        )['xl/worksheets/sheet1.xml'];

        return $this->columnWidths($sheet);
    }

    /**
     * The set column widths, keyed by column letter — the auto-fit the report
     * ships with.
     *
     * @return array<string, float>
     */
    private function columnWidths(string $sheet): array
    {
        $widths = [];
        $index = 0;

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $column) {
            $index++;

            if (preg_match('/<col min="'.$index.'"[^>]*width="([0-9.]+)"/', $sheet, $m) === 1) {
                $widths[$column] = (float) $m[1];
            }
        }

        return $widths;
    }
}
