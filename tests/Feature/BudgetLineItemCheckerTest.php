<?php

namespace Tests\Feature;

use App\Livewire\BudgetLineItemChecker;
use App\Livewire\Sidebar;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BudgetLineItemCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function schoolHead(string $name = 'Line Item School'): User
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

    private function allotment(User $schoolHead, float $amount, int $year = 2026): SchoolAllotment
    {
        return SchoolAllotment::create([
            'school_id' => $schoolHead->school_id,
            'year' => $year,
            'amount' => $amount,
        ]);
    }

    private function matrix(User $schoolHead, int $month, int $year = 2026): MonthlyExpenditureMatrix
    {
        return MonthlyExpenditureMatrix::create([
            'school_id' => $schoolHead->school_id,
            'year' => $year,
            'month' => $month,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $schoolHead->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function line(MonthlyExpenditureMatrix $matrix, array $attributes = []): MonthlyExpenditureItem
    {
        return $matrix->items()->create(array_merge([
            'position' => 1,
            'section' => MonthlyExpenditureMatrix::SECTION_OPERATIONS,
            'objective' => 'Keep the office stocked',
            'activity_description' => 'Procure supplies',
            'resource_kind' => 'MOOE',
            'quantity' => 2,
            'unit' => 'ream',
            'unit_cost' => 250,
            'calculated_total' => 500,
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
        ], $attributes));
    }

    // ---------------------------------------------------------------- access

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('budget.lineitems.index'))->assertRedirect(route('login'));
    }

    public function test_the_budget_office_sees_every_school_and_opens_one_schools_lines(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta Elementary School');

        $this->line($this->matrix($alpha, 1), [
            'objective' => 'Keep the office stocked',
            'activity_description' => 'Procure office supplies',
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
            'quantity' => 2,
            'unit_cost' => 250,
        ]);

        $this->line($this->matrix($beta, 2), [
            'activity_description' => 'Pay the electric bill',
            'account_title' => 'Electricity Expense',
            'account_code' => '5020402000',
            'quantity' => 1,
            'unit_cost' => 6000,
        ]);

        $this->actingAs($this->budgetOfficer())
            ->get(route('budget.lineitems.index'))
            ->assertOk()
            ->assertSeeLivewire('budget-line-item-checker')
            ->assertSeeHtml('<h1>Monthly Expenditure</h1>')
            ->assertSee('Alpha Elementary School')
            ->assertSee('Beta Elementary School')
            ->assertSee($alpha->name)
            ->assertSee($beta->name)
            // The index carries counts, not another school's lines.
            ->assertDontSee('Procure office supplies')
            ->assertDontSee('Pay the electric bill');

        Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemChecker::class)
            ->call('openSchool', $alpha->school_id)
            ->assertSet('school', (string) $alpha->school_id)
            ->assertSee('Procure office supplies')
            ->assertSee('JAN')
            ->assertSee('FEB')
            ->assertDontSee('Pay the electric bill')
            ->call('backToSchools')
            ->assertSet('school', '')
            ->assertSee('Alpha Elementary School')
            ->assertSee('Beta Elementary School')
            ->assertDontSee('Procure office supplies');
    }

    public function test_the_school_index_lists_schools_even_when_the_year_has_no_lines(): void
    {
        $this->schoolHead('Alpha Elementary School');

        Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemChecker::class)
            ->assertSee('Alpha Elementary School')
            ->assertSee('0 / 12')
            ->assertDontSee('No expenditure lines have been entered for CY');
    }

    public function test_the_school_index_lists_a_school_with_no_lines_and_counts_its_matrices(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta Elementary School');

        $this->line($this->matrix($alpha, 1), ['quantity' => 2, 'unit_cost' => 100]);
        $this->line($this->matrix($alpha, 2), ['quantity' => 1, 'unit_cost' => 50]);

        $component = Livewire::actingAs($this->budgetOfficer())->test(BudgetLineItemChecker::class);

        $component
            ->assertSee('Alpha Elementary School')
            ->assertSee('Beta Elementary School')
            ->assertSee('2 / 12')
            ->assertSee('0 / 12');

        // Opening the school with no lines shows its empty state, not the
        // other school's lines.
        $component
            ->call('openSchool', $beta->school_id)
            ->assertSee('No expenditure lines have been entered for CY')
            ->assertDontSee('Procure supplies');
    }

    public function test_an_unknown_school_in_the_url_falls_back_to_the_index(): void
    {
        $this->schoolHead('Alpha Elementary School');

        $this->actingAs($this->budgetOfficer())
            ->get(route('budget.lineitems.index', ['school' => 999999]))
            ->assertOk()
            ->assertSee('Alpha Elementary School')
            ->assertSeeHtml('<h1>Monthly Expenditure</h1>');
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
                ->get(route('budget.lineitems.index'))
                ->assertNotFound();
        }
    }

    public function test_the_component_refuses_guests_and_every_other_role(): void
    {
        $this->assertStringContainsString(
            'Page Not Found',
            Livewire::test(BudgetLineItemChecker::class)->html()
        );

        foreach ([
            $this->schoolHead(),
            User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]),
        ] as $user) {
            $this->assertStringContainsString(
                'Page Not Found',
                Livewire::actingAs($user)->test(BudgetLineItemChecker::class)->html()
            );
        }
    }

    public function test_the_sidebar_links_the_page_for_the_budget_role_only(): void
    {
        Livewire::actingAs($this->budgetOfficer())
            ->test(Sidebar::class)
            ->assertSee('WFP')
            ->assertSee('Work & Financial Plan')
            ->assertSee('School Allotments')
            ->assertSee('Monthly Expenditure')
            // The group opens on arrival, so its three pages are one click
            // away — the same shape the School Head's WFP group has.
            ->assertSeeHtml('wire:key="sidebar-wfp"')
            ->assertSeeHtml('id="sidebar-group-wfp"');

        $shHtml = Livewire::actingAs($this->schoolHead())->test(Sidebar::class)->html();
        $this->assertStringNotContainsString(route('budget.lineitems.index'), $shHtml);
    }

    public function test_the_budget_dashboard_links_the_page(): void
    {
        $this->actingAs($this->budgetOfficer())
            ->get(route('home'))
            ->assertOk()
            ->assertSee(route('budget.lineitems.index'));

        $this->actingAs($this->schoolHead())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee(route('budget.lineitems.index'));
    }

    // --------------------------------------------------------------- filters

    public function test_the_year_filter_defaults_to_the_newest_year_with_lines(): void
    {
        $head = $this->schoolHead();
        $this->line($this->matrix($head, 5, 2025), [
            'activity_description' => 'Old line from 2025',
        ]);

        $component = Livewire::actingAs($this->budgetOfficer())->test(BudgetLineItemChecker::class);

        $this->assertSame('2025', $component->get('year'));
        $component
            ->assertSee('Line Item School')
            ->assertDontSee('Old line from 2025')
            ->call('openSchool', $head->school_id)
            ->assertSee('Old line from 2025');
    }

    public function test_opening_a_school_and_picking_a_month_narrows_the_list(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta Elementary School');

        $this->line($this->matrix($alpha, 1), ['activity_description' => 'Alpha January line']);
        $this->line($this->matrix($alpha, 2), ['activity_description' => 'Alpha February line']);
        $this->line($this->matrix($beta, 1), ['activity_description' => 'Beta January line']);
        $this->line($this->matrix($beta, 1, 2025), ['activity_description' => 'Beta old line']);

        $component = Livewire::actingAs($this->budgetOfficer())->test(BudgetLineItemChecker::class);

        // The index lists both schools but no lines.
        $component
            ->assertSee('Alpha Elementary School')
            ->assertSee('Beta Elementary School')
            ->assertDontSee('Alpha January line')
            ->assertDontSee('Beta old line');

        // Opening Alpha shows only Alpha's current-year lines.
        $component
            ->call('openSchool', $alpha->school_id)
            ->assertSee('Alpha January line')
            ->assertSee('Alpha February line')
            ->assertDontSee('Beta January line')
            ->assertDontSee('Beta old line');

        // A month tab narrows the open school's lines further.
        $component
            ->call('selectMonth', 2)
            ->assertSee('Alpha February line')
            ->assertDontSee('Alpha January line')
            ->assertDontSee('Beta January line');
    }

    public function test_the_school_index_searches_school_and_school_head(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta Elementary School');

        $component = Livewire::actingAs($this->budgetOfficer())->test(BudgetLineItemChecker::class);

        $component
            ->set('search', 'Beta Elementary')
            ->assertSee('Beta Elementary School')
            ->assertDontSee('Alpha Elementary School');

        $component
            ->set('search', $alpha->username)
            ->assertSee('Alpha Elementary School')
            ->assertDontSee('Beta Elementary School');

        $component
            ->set('search', $beta->name)
            ->assertSee('Beta Elementary School')
            ->assertDontSee('Alpha Elementary School');
    }

    public function test_the_line_search_matches_activity_and_account(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');

        $this->line($this->matrix($alpha, 1), [
            'activity_description' => 'Procure office supplies',
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
        ]);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemChecker::class)
            ->call('openSchool', $alpha->school_id);

        $component
            ->set('search', 'supplies')
            ->assertSee('Procure office supplies');

        $component
            ->set('search', '5020301000')
            ->assertSee('Procure office supplies');

        $component
            ->set('search', 'electric')
            ->assertDontSee('Procure office supplies')
            ->assertSee('No expenditure lines match');
    }

    // ---------------------------------------------------------------- figures

    public function test_the_summary_counts_the_open_schools_filtered_lines_matrices_and_money(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta Elementary School');

        $this->line($this->matrix($alpha, 1), [
            'quantity' => 2,
            'unit_cost' => 250,
            // Deliberately wrong: the page must recompute quantity × unit cost.
            'calculated_total' => 999,
        ]);
        $this->line($this->matrix($alpha, 2), [
            'quantity' => 3,
            'unit_cost' => 100,
            'calculated_total' => 0,
        ]);
        $this->line($this->matrix($beta, 1), [
            'quantity' => 1,
            'unit_cost' => 6000,
            'calculated_total' => 1,
        ]);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemChecker::class)
            ->call('openSchool', $alpha->school_id);

        $summary = $component->instance()->summary;

        $this->assertSame(2, $summary['lines']);
        $this->assertSame(1, $summary['schools']);
        $this->assertSame(2, $summary['matrices']);
        $this->assertSame(800.0, $summary['total']);

        $component
            ->assertSee('800.00')
            ->assertDontSee('999.00')
            ->assertDontSee('6,000.00');
    }

    public function test_the_total_is_recomputed_from_quantity_and_unit_cost(): void
    {
        $head = $this->schoolHead();
        $this->line($this->matrix($head, 1), [
            'quantity' => 4,
            'unit_cost' => 125.5,
            'calculated_total' => 1,
        ]);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemChecker::class)
            ->call('openSchool', $head->school_id);

        $this->assertSame(502.0, $component->instance()->summary['total']);
        $component->assertSee('502.00');
    }

    public function test_the_month_tabs_total_each_month_and_carry_the_running_balance(): void
    {
        $head = $this->schoolHead();
        $this->allotment($head, 10000);

        $this->line($this->matrix($head, 1), [
            'activity_description' => 'January supplies',
            'quantity' => 1,
            'unit_cost' => 1000,
        ]);
        $this->line($this->matrix($head, 2), [
            'activity_description' => 'February supplies',
            'quantity' => 2,
            'unit_cost' => 1000,
        ]);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemChecker::class)
            ->call('openSchool', $head->school_id);

        $months = collect($component->instance()->months)->keyBy('month');

        $this->assertSame(1000.0, $months[1]['total']);
        $this->assertSame(9000.0, $months[1]['balance']);
        $this->assertSame(2000.0, $months[2]['total']);
        $this->assertSame(7000.0, $months[2]['balance']);
        $this->assertNull($months[3]['total']);
        $this->assertSame(7000.0, $months[3]['balance']);

        $component
            ->assertSee('10,000.00')
            ->assertSee('3,000.00')
            ->assertSee('7,000.00')
            ->assertSee('JAN')
            ->assertSee('FEB');

        $component
            ->call('selectMonth', 2)
            ->assertSet('month', '2')
            ->assertSee('February supplies')
            ->assertDontSee('January supplies');

        $this->assertSame(1, $component->instance()->summary['lines']);

        $component
            ->call('clearMonth')
            ->assertSet('month', '')
            ->assertSee('January supplies')
            ->assertSee('February supplies');
    }

    public function test_the_allotment_strip_reads_the_open_schools_ceiling(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta Elementary School');

        $this->allotment($alpha, 10000);
        $this->allotment($beta, 5000);

        $this->line($this->matrix($alpha, 1), [
            'quantity' => 1,
            'unit_cost' => 1000,
        ]);
        $this->line($this->matrix($beta, 1), [
            'quantity' => 1,
            'unit_cost' => 2000,
        ]);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemChecker::class)
            ->call('openSchool', $alpha->school_id);

        $allocation = $component->instance()->allocation;

        $this->assertSame(10000.0, $allocation['allotted']);
        $this->assertSame(1000.0, $allocation['consumed']);
        $this->assertSame(9000.0, $allocation['remaining']);

        $component->call('openSchool', $beta->school_id);
        $allocation = $component->instance()->allocation;

        $this->assertSame(5000.0, $allocation['allotted']);
        $this->assertSame(2000.0, $allocation['consumed']);
        $this->assertSame(3000.0, $allocation['remaining']);
    }

    public function test_quantities_are_shown_without_trailing_decimal_zeros(): void
    {
        $head = $this->schoolHead();
        $this->line($this->matrix($head, 1), [
            'quantity' => 12.5,
            'unit_cost' => 2,
        ]);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemChecker::class)
            ->call('openSchool', $head->school_id);

        // The decimal:2 cast must not turn the quantity into "12.50": the MEM
        // editor shows 12.5, so the checking page must read the same.
        $component
            ->assertSee('12.5')
            ->assertDontSee('12.50')
            ->assertSee('25.00');
    }

    public function test_the_detail_only_shows_the_open_schools_lines(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta Elementary School');

        $this->line($this->matrix($alpha, 1), ['activity_description' => 'Alpha line']);
        $this->line($this->matrix($beta, 1), ['activity_description' => 'Beta line']);

        $component = Livewire::actingAs($this->budgetOfficer())->test(BudgetLineItemChecker::class);

        $component
            ->assertSee('Alpha Elementary School')
            ->assertSee('Beta Elementary School')
            ->assertDontSee('Alpha line')
            ->assertDontSee('Beta line');

        $component
            ->call('openSchool', $alpha->school_id)
            ->assertSee('Alpha line')
            ->assertDontSee('Beta line');

        $this->assertSame(1, $component->instance()->summary['lines']);
        $this->assertSame(1, $component->instance()->summary['schools']);
        $this->assertSame(1, $component->instance()->summary['matrices']);

        $component
            ->call('openSchool', $beta->school_id)
            ->assertSee('Beta line')
            ->assertDontSee('Alpha line');

        $this->assertSame(1, $component->instance()->summary['lines']);
        $this->assertSame(1, $component->instance()->summary['schools']);
        $this->assertSame(1, $component->instance()->summary['matrices']);

        // Both schools' records are still there; opening one only narrowed
        // the Budget officer's view.
        $this->assertSame(2, MonthlyExpenditureItem::count());
    }
}
