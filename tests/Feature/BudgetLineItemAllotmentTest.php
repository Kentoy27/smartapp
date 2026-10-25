<?php

namespace Tests\Feature;

use App\Livewire\BudgetLineItemAllotment;
use App\Livewire\Sidebar;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Support\PurePhpZipReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class BudgetLineItemAllotmentTest extends TestCase
{
    use RefreshDatabase;

    private function schoolHead(string $name = 'Report School'): User
    {
        $district = District::create(['name' => $name.' District']);
        $school = School::create(['district_id' => $district->id, 'name' => $name]);

        return User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);
    }

    private function budgetOfficer(): User
    {
        return User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);
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
        $this->get(route('budget.per-line-item.index'))->assertRedirect(route('login'));
    }

    public function test_the_budget_office_can_open_the_report(): void
    {
        $head = $this->schoolHead('Alpha Elementary School');

        $this->line($head, 1, [
            'quantity' => 53,
            'unit_cost' => 1000,
            'account_title' => 'Travelling Expense (Local)',
            'account_code' => '5020101000',
        ]);

        $this->actingAs($this->budgetOfficer())
            ->get(route('budget.per-line-item.index'))
            ->assertOk()
            ->assertSeeLivewire('budget-line-item-allotment')
            ->assertSee('Per Line Item Expenditure')
            ->assertSee('Alpha Elementary School')
            ->assertSee('Travelling Expense (Local)')
            ->assertSee('53,000.00')
            // The year defaults to the newest year the school has a matrix
            // for, and the export travels with it. (Escaped comparison: the
            // href's & renders as &amp; in the HTML.)
            ->assertSee(route('budget.per-line-item.export', [
                'school' => $head->school_id,
                'year' => '2026',
            ]));
    }

    public function test_only_the_budget_role_can_reach_the_page(): void
    {
        $users = [
            $this->schoolHead(),
            User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)
                ->get(route('budget.per-line-item.index'))
                ->assertNotFound();
        }
    }

    public function test_the_component_refuses_guests_and_every_other_role(): void
    {
        $this->assertStringContainsString(
            'Page Not Found',
            Livewire::test(BudgetLineItemAllotment::class)->html()
        );

        foreach ([
            $this->schoolHead(),
            User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]),
        ] as $user) {
            $this->assertStringContainsString(
                'Page Not Found',
                Livewire::actingAs($user)->test(BudgetLineItemAllotment::class)->html()
            );
        }
    }

    // -------------------------------------------------------------- figures

    public function test_the_report_rolls_the_years_lines_up_per_account_code(): void
    {
        $head = $this->schoolHead();

        // One code spent from twice in the year is ONE row with both months
        // in it — that is what makes this the allotment per line item.
        $this->line($head, 3, ['quantity' => 100, 'unit_cost' => 100]);   // 10,000.00
        $this->line($head, 9, ['quantity' => 50, 'unit_cost' => 100]);    //  5,000.00
        $this->line($head, 1, [
            'quantity' => 53,
            'unit_cost' => 1000,
            'account_title' => 'Travelling Expense (Local)',
            'account_code' => '5020101000',
        ]); // 53,000.00

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemAllotment::class);

        $rows = $component->instance()->lineItems;

        $this->assertCount(2, $rows);
        $this->assertSame('5020101000', $rows[0]['code']);
        $this->assertSame(53000.0, $rows[0]['total']);
        $this->assertSame(77.94, $rows[0]['percent']);
        $this->assertSame('5020301000', $rows[1]['code']);
        $this->assertSame(15000.0, $rows[1]['total']);
        $this->assertSame(22.06, $rows[1]['percent']);

        $this->assertSame(68000.0, $component->instance()->grandTotal);

        $component
            ->assertSee('Travelling Expense (Local)')
            ->assertSee('53,000.00')
            ->assertSee('77.94%')
            ->assertSee('15,000.00')
            ->assertSee('22.06%')
            ->assertSee('68,000.00');
    }

    public function test_another_schools_lines_are_never_in_the_report(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta Elementary School');

        $this->line($alpha, 1, [
            'quantity' => 1,
            'unit_cost' => 100,
            'account_title' => 'Alpha Supplies',
            'account_code' => '5020301000',
        ]);
        $this->line($beta, 1, [
            'quantity' => 999,
            'unit_cost' => 1000,
            'account_title' => 'Someone Else’s Expense',
            'account_code' => '5029999999',
        ]);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemAllotment::class);

        // The first school by name opens, and only its lines are read.
        $this->assertSame((string) $alpha->school_id, $component->instance()->schoolId);
        $component
            ->assertSee('Alpha Supplies')
            ->assertDontSee('Someone Else’s Expense');

        // Opening the other school switches the whole report to it.
        $component
            ->set('schoolId', (string) $beta->school_id)
            ->assertSee('Someone Else’s Expense')
            ->assertDontSee('Alpha Supplies');

        $this->assertSame('999,000.00', number_format($component->instance()->grandTotal, 2));
    }

    public function test_the_allotment_strip_reads_the_open_schools_ceiling(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta Elementary School');

        SchoolAllotment::create(['school_id' => $alpha->school_id, 'year' => 2026, 'amount' => 10000]);
        SchoolAllotment::create(['school_id' => $beta->school_id, 'year' => 2026, 'amount' => 5000]);

        $this->line($alpha, 1, ['quantity' => 1, 'unit_cost' => 1000]);
        $this->line($beta, 1, ['quantity' => 1, 'unit_cost' => 2000]);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemAllotment::class);

        $allocation = $component->instance()->allocation;
        $this->assertSame(10000.0, $allocation['allotted']);
        $this->assertSame(1000.0, $allocation['consumed']);
        $this->assertSame(9000.0, $allocation['remaining']);

        $component->set('schoolId', (string) $beta->school_id);

        $allocation = $component->instance()->allocation;
        $this->assertSame(5000.0, $allocation['allotted']);
        $this->assertSame(2000.0, $allocation['consumed']);
        $this->assertSame(3000.0, $allocation['remaining']);
    }

    public function test_the_form_is_prepared_by_the_schools_school_head(): void
    {
        $head = $this->schoolHead('Alpha Elementary School');
        $head->update(['name' => 'Maria School Head', 'position' => 'Principal II']);

        $budget = $this->budgetOfficer();
        $budget->update(['name' => 'Budget Officer']);

        $component = Livewire::actingAs($budget)->test(BudgetLineItemAllotment::class);

        $this->assertSame($head->id, $component->instance()->preparedBy?->id);
        $component
            ->assertSee('Maria School Head')
            ->assertSee('Principal II');
    }

    // ------------------------------------------------------------------ school / year

    public function test_the_report_opens_on_the_first_school_and_its_newest_year(): void
    {
        // Created Beta first so "first by name" is the decisive rule.
        $beta = $this->schoolHead('Beta Elementary School');
        $alpha = $this->schoolHead('Alpha Elementary School');

        $this->line($alpha, 6, ['quantity' => 1, 'unit_cost' => 100], year: 2025);
        $this->line($alpha, 6, ['quantity' => 1, 'unit_cost' => 900], year: 2026);
        $this->line($beta, 6, ['quantity' => 1, 'unit_cost' => 700], year: 2025);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemAllotment::class);

        $this->assertSame((string) $alpha->school_id, $component->instance()->schoolId);
        $this->assertSame('2026', $component->instance()->year);
        $this->assertSame(900.0, $component->instance()->grandTotal);

        // Switching school keeps the reader's year when the new school has
        // it; Beta has only 2025, so the report opens on that year instead.
        $component
            ->set('schoolId', (string) $beta->school_id)
            ->assertSee('700.00');

        $this->assertSame('2025', $component->instance()->year);
        $this->assertSame(700.0, $component->instance()->grandTotal);
    }

    public function test_a_school_with_the_same_year_keeps_the_readers_year(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta Elementary School');

        $this->line($alpha, 6, ['quantity' => 1, 'unit_cost' => 100], year: 2025);
        $this->line($alpha, 6, ['quantity' => 1, 'unit_cost' => 900], year: 2026);
        $this->line($beta, 6, ['quantity' => 1, 'unit_cost' => 500], year: 2025);
        $this->line($beta, 6, ['quantity' => 1, 'unit_cost' => 700], year: 2026);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemAllotment::class)
            ->set('year', '2025');

        $this->assertSame('2025', $component->instance()->year);

        $component->set('schoolId', (string) $beta->school_id);

        $this->assertSame('2025', $component->instance()->year);
        $this->assertSame(500.0, $component->instance()->grandTotal);
    }

    public function test_the_year_select_switches_the_whole_report(): void
    {
        $head = $this->schoolHead();

        $this->line($head, 6, ['quantity' => 1, 'unit_cost' => 100], year: 2025);
        $this->line($head, 6, ['quantity' => 1, 'unit_cost' => 900], year: 2026);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemAllotment::class);

        $this->assertSame('2026', $component->instance()->year);

        $component->set('year', '2025');

        $this->assertSame('2025', $component->instance()->year);
        $this->assertSame(100.0, $component->instance()->grandTotal);
    }

    public function test_a_year_or_school_that_does_not_exist_falls_back_to_real_ones(): void
    {
        $head = $this->schoolHead();
        $this->line($head, 6, ['quantity' => 1, 'unit_cost' => 900], year: 2026);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemAllotment::class)
            ->set('schoolId', '999999')
            ->set('year', '1999');

        $this->assertSame((string) $head->school_id, $component->instance()->schoolId);
        $this->assertSame('2026', $component->instance()->year);
        $this->assertSame(900.0, $component->instance()->grandTotal);
    }

    // ---------------------------------------------------------------- export

    public function test_the_report_exports_as_the_printed_form_for_the_asked_school(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta Elementary School');

        $this->line($alpha, 1, [
            'quantity' => 53,
            'unit_cost' => 1000,
            'account_title' => 'Travelling Expense (Local)',
            'account_code' => '5020101000',
        ]);
        $this->line($beta, 1, [
            'quantity' => 999,
            'unit_cost' => 1000,
            'account_title' => 'Someone Else’s Expense',
            'account_code' => '5029999999',
        ]);

        $response = $this->actingAs($this->budgetOfficer())->get(route('budget.per-line-item.export', [
            'school' => $alpha->school_id,
            'year' => 2026,
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('per-line-item-expenditure-2026-alpha-elementary-school.xlsx');

        $parts = $this->partsOf($response->getContent());

        foreach ($parts as $name => $contents) {
            $this->assertNotFalse(simplexml_load_string($contents), $name.' is not well-formed XML');
        }

        $sheet = $parts['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('ALPHA ELEMENTARY SCHOOL', $sheet);
        $this->assertStringContainsString('Travelling Expense (Local)', $sheet);
        $this->assertStringContainsString('<v>53000</v>', $sheet);
        $this->assertStringContainsString('Senior Bookkeeper', $sheet);
        $this->assertStringNotContainsString('Someone Else’s Expense', $sheet);
        $this->assertStringNotContainsString('5029999999', $sheet);

        // The form's preparer is the school's SH, not the Budget officer.
        $this->assertStringContainsString(strtoupper($alpha->name), $sheet);
    }

    public function test_a_year_with_no_matrices_exports_the_newest_year_that_has_them(): void
    {
        $head = $this->schoolHead();
        $this->line($head, 6, ['quantity' => 1, 'unit_cost' => 900], year: 2026);

        $this->actingAs($this->budgetOfficer())
            ->get(route('budget.per-line-item.export', [
                'school' => $head->school_id,
                'year' => 1999,
            ]))
            ->assertOk()
            ->assertDownload('per-line-item-expenditure-2026-report-school.xlsx');
    }

    public function test_the_export_requires_a_real_school_and_the_budget_role(): void
    {
        $head = $this->schoolHead();

        // No school, and a school that does not exist: nothing to export.
        $this->actingAs($this->budgetOfficer())
            ->get(route('budget.per-line-item.export'))
            ->assertNotFound();

        $this->actingAs($this->budgetOfficer())
            ->get(route('budget.per-line-item.export', ['school' => 999999]))
            ->assertNotFound();

        // A guest is sent to login; every other role gets a 404. Signed out
        // first: the checks above left the guard authenticated.
        Auth::logout();
        $this->get(route('budget.per-line-item.export', ['school' => $head->school_id]))
            ->assertRedirect(route('login'));

        foreach ([
            $head,
            User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]),
        ] as $user) {
            $this->actingAs($user)
                ->get(route('budget.per-line-item.export', ['school' => $head->school_id]))
                ->assertNotFound();
        }
    }

    // --------------------------------------------------------------- sidebar

    public function test_the_sidebar_links_the_report_for_the_budget_role_only(): void
    {
        Livewire::actingAs($this->budgetOfficer())
            ->test(Sidebar::class)
            ->assertSee('WFP')
            ->assertSee('Per Line Item Expenditure')
            ->assertSee(route('budget.per-line-item.index'), false);

        $shHtml = Livewire::actingAs($this->schoolHead())->test(Sidebar::class)->html();
        $this->assertStringNotContainsString(route('budget.per-line-item.index'), $shHtml);
    }

    public function test_the_budget_dashboard_links_the_report(): void
    {
        $this->actingAs($this->budgetOfficer())
            ->get(route('home'))
            ->assertOk()
            ->assertSee(route('budget.per-line-item.index'));

        $this->actingAs($this->schoolHead())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee(route('budget.per-line-item.index'));
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
        $path = tempnam(sys_get_temp_dir(), 'budget-allotment-').'.xlsx';
        file_put_contents($path, $bytes);

        $parts = (new PurePhpZipReader($path))->readAll();

        unlink($path);

        return $parts;
    }
}
