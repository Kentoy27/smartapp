<?php

namespace Tests\Feature;

use App\Livewire\BudgetAllotmentMonitor;
use App\Livewire\MemEditor;
use App\Livewire\SchoolAllotmentManager;
use App\Livewire\Sidebar;
use App\Livewire\WfpLineItemAllotment;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Support\WfpAllotment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolAllotmentTest extends TestCase
{
    use RefreshDatabase;

    private function schoolHead(string $name = 'Allotment School'): User
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
    private function line(
        User $sh,
        int $month,
        float $quantity,
        float $unitCost,
        int $year = 2026,
    ): MonthlyExpenditureItem {
        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $sh->school_id,
            'year' => $year,
            'month' => $month,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $sh->id,
        ]);

        return $matrix->items()->create([
            'position' => 1,
            'section' => MonthlyExpenditureMatrix::SECTION_OPERATIONS,
            'activity_description' => 'Procure supplies',
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'calculated_total' => round($quantity * $unitCost, 2),
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
        ]);
    }

    // ---------------------------------------------------------------- access

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('budget.allotments.index'))->assertRedirect(route('login'));
    }

    public function test_the_budget_office_can_open_the_allotment_page(): void
    {
        $head = $this->schoolHead('Bino Elementary School');

        $this->actingAs($this->budgetOfficer())
            ->get(route('budget.allotments.index'))
            ->assertOk()
            ->assertSeeLivewire('school-allotment-manager')
            ->assertSee('School Allotments')
            ->assertSee('Bino Elementary School')
            ->assertSee($head->name);
    }

    public function test_the_administrative_side_can_also_set_allotments(): void
    {
        $administrator = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $superadmin = User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);

        foreach ([$administrator, $superadmin] as $user) {
            $this->actingAs($user)
                ->get(route('budget.allotments.index'))
                ->assertOk();
        }
    }

    public function test_the_school_head_and_viewer_cannot_reach_the_allotment_page(): void
    {
        $sh = $this->schoolHead();
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);

        foreach ([$sh, $viewer] as $user) {
            $this->actingAs($user)
                ->get(route('budget.allotments.index'))
                ->assertNotFound();
        }
    }

    public function test_the_component_refuses_a_guest_and_a_school_head(): void
    {
        $sh = $this->schoolHead();

        $this->assertStringContainsString(
            'Page Not Found',
            Livewire::test(SchoolAllotmentManager::class)->html()
        );

        $this->assertStringContainsString(
            'Page Not Found',
            Livewire::actingAs($sh)->test(SchoolAllotmentManager::class)->html()
        );
    }

    // ------------------------------------------------------------ the amount

    public function test_the_budget_office_sets_a_school_year_allotment(): void
    {
        $head = $this->schoolHead();
        $budget = $this->budgetOfficer();

        Livewire::actingAs($budget)
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->set('amounts.'.$head->school_id, '150000')
            ->call('save', $head->school_id)
            ->assertHasNoErrors();

        $allotment = SchoolAllotment::query()->firstOrFail();

        $this->assertSame((int) $head->school_id, (int) $allotment->school_id);
        $this->assertSame(2026, $allotment->year);
        $this->assertSame(150000.0, (float) $allotment->amount);
        $this->assertSame($budget->id, $allotment->set_by);
    }

    public function test_saving_the_same_school_and_year_updates_the_one_row(): void
    {
        $head = $this->schoolHead();

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026');

        $component
            ->set('amounts.'.$head->school_id, '1000')
            ->call('save', $head->school_id)
            ->assertHasNoErrors();

        $component
            ->set('amounts.'.$head->school_id, '2500.50')
            ->call('save', $head->school_id)
            ->assertHasNoErrors();

        $this->assertSame(1, SchoolAllotment::query()->count());
        $this->assertSame(2500.5, (float) SchoolAllotment::query()->value('amount'));
    }

    public function test_a_blank_amount_clears_the_allotment(): void
    {
        $head = $this->schoolHead();

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => 2026,
            'amount' => 1000,
        ]);

        Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->set('amounts.'.$head->school_id, '')
            ->call('save', $head->school_id)
            ->assertHasNoErrors();

        $this->assertSame(0, SchoolAllotment::query()->count());
    }

    public function test_a_negative_or_non_numeric_amount_is_refused(): void
    {
        $head = $this->schoolHead();

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026');

        $component
            ->set('amounts.'.$head->school_id, '-1')
            ->call('save', $head->school_id)
            ->assertHasErrors('amounts.'.$head->school_id);

        $component
            ->set('amounts.'.$head->school_id, 'not money')
            ->call('save', $head->school_id)
            ->assertHasErrors('amounts.'.$head->school_id);

        $this->assertSame(0, SchoolAllotment::query()->count());
    }

    public function test_an_allotment_is_kept_per_year(): void
    {
        $head = $this->schoolHead();
        $budget = $this->budgetOfficer();

        $component = Livewire::actingAs($budget)->test(SchoolAllotmentManager::class);

        $component
            ->set('year', '2026')
            ->set('amounts.'.$head->school_id, '1000')
            ->call('save', $head->school_id)
            ->assertHasNoErrors();

        $component
            ->set('year', '2027')
            ->set('amounts.'.$head->school_id, '2000')
            ->call('save', $head->school_id)
            ->assertHasNoErrors();

        $this->assertSame(2, SchoolAllotment::query()->count());

        $rows = SchoolAllotment::query()->orderBy('year')->get();

        $this->assertSame(2026, $rows[0]->year);
        $this->assertSame(1000.0, (float) $rows[0]->amount);
        $this->assertSame(2027, $rows[1]->year);
        $this->assertSame(2000.0, (float) $rows[1]->amount);
    }

    // ---------------------------------------------------------- consumption

    public function test_the_page_reads_the_years_matrix_total_as_consumption(): void
    {
        $head = $this->schoolHead();
        $this->line($head, 3, 10, 250.5);

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => 2026,
            'amount' => 5000,
        ]);

        Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->assertSee('2,505.00')
            ->assertSee('2,495.00');
    }

    public function test_consumption_is_summed_per_school_in_one_read(): void
    {
        $mine = $this->schoolHead('Mine School');
        $other = $this->schoolHead('Other School');

        $this->line($mine, 1, 2, 100);    // 200.00
        $this->line($mine, 2, 3, 50.25);  // 150.75
        $this->line($other, 1, 9, 10);    // 90.00

        $consumed = WfpAllotment::consumedBySchool(
            [(int) $mine->school_id, (int) $other->school_id],
            2026
        );

        $this->assertSame(350.75, $consumed[(int) $mine->school_id]);
        $this->assertSame(90.0, $consumed[(int) $other->school_id]);
    }

    public function test_another_years_consumption_is_not_counted(): void
    {
        $head = $this->schoolHead();
        $this->line($head, 1, 10, 100, year: 2025);

        $this->assertSame([], WfpAllotment::consumedBySchool([(int) $head->school_id], 2026));
    }

    // ------------------------------------------------------ the SH's report

    public function test_the_school_head_sees_their_own_allotment_and_remaining_balance(): void
    {
        $head = $this->schoolHead();
        $this->line($head, 1, 10, 250); // 2,500.00

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => (int) now()->year,
            'amount' => 10000,
        ]);

        $component = Livewire::actingAs($head)->test(WfpLineItemAllotment::class);
        $allocation = $component->instance()->allocation;

        $this->assertSame(10000.0, $allocation['allotted']);
        $this->assertSame(2500.0, $allocation['consumed']);
        $this->assertSame(7500.0, $allocation['remaining']);
        $this->assertSame(25.0, $allocation['percent']);
        $this->assertFalse($allocation['is_over']);

        $component
            ->assertSee('Allotted budget')
            ->assertSee('10,000.00')
            ->assertSee('2,500.00')
            ->assertSee('7,500.00')
            ->assertSee('25.00% of the allotted budget consumed.');
    }

    public function test_an_over_allotment_is_reported_as_over_not_as_a_negative_balance(): void
    {
        $head = $this->schoolHead();
        $this->line($head, 2, 10, 150); // 1,500.00

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => (int) now()->year,
            'amount' => 1000,
        ]);

        $component = Livewire::actingAs($head)->test(WfpLineItemAllotment::class);
        $allocation = $component->instance()->allocation;

        $this->assertTrue($allocation['is_over']);
        $this->assertSame(-500.0, $allocation['remaining']);
        $this->assertSame(150.0, $allocation['percent']);

        $component
            ->assertSee('Over allotment')
            ->assertSee('500.00')
            ->assertSee('More spent than allotted');
    }

    public function test_an_unset_allotment_does_not_report_a_negative_balance(): void
    {
        $head = $this->schoolHead();
        $this->line($head, 1, 10, 100); // 1,000.00

        $component = Livewire::actingAs($head)->test(WfpLineItemAllotment::class);
        $allocation = $component->instance()->allocation;

        $this->assertNull($allocation['allotted']);
        $this->assertSame(1000.0, $allocation['consumed']);
        $this->assertSame(0.0, $allocation['remaining']);
        $this->assertFalse($allocation['is_over']);

        $component
            ->assertSee('Not set')
            ->assertSee('No allotment budget has been set for CY '.now()->year.' yet.');
    }

    public function test_a_school_head_never_reads_another_schools_allotment_or_spending(): void
    {
        $mine = $this->schoolHead('My Allotment School');
        $other = $this->schoolHead('Other Allotment School');

        $this->line($mine, 1, 1, 100);
        $this->line($other, 1, 999, 1000);

        SchoolAllotment::create([
            'school_id' => $mine->school_id,
            'year' => (int) now()->year,
            'amount' => 1000,
        ]);

        SchoolAllotment::create([
            'school_id' => $other->school_id,
            'year' => (int) now()->year,
            'amount' => 999000,
        ]);

        $allocation = Livewire::actingAs($mine)
            ->test(WfpLineItemAllotment::class)
            ->instance()
            ->allocation;

        $this->assertSame(1000.0, $allocation['allotted']);
        $this->assertSame(100.0, $allocation['consumed']);
        $this->assertSame(900.0, $allocation['remaining']);
    }

    // ------------------------------------------- the SH's matrix balance

    public function test_the_matrix_editor_shows_the_annual_allotment_and_remaining_balance(): void
    {
        $head = $this->schoolHead();
        $january = $this->line($head, 1, 10, 250); // 2,500.00

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => 2026,
            'amount' => 10000,
        ]);

        $component = Livewire::actingAs($head)
            ->test(MemEditor::class, ['matrix' => $january->matrix]);
        $allocation = $component->instance()->allocation;

        $this->assertSame(10000.0, $allocation['allotted']);
        $this->assertSame(2500.0, $allocation['consumed']);
        $this->assertSame(7500.0, $allocation['remaining']);
        $this->assertSame(25.0, $allocation['percent']);
        $this->assertFalse($allocation['is_over']);

        $months = collect($component->instance()->months())->keyBy('month');

        $this->assertSame(7500.0, $months[1]['balance']);
        // A month with no spending carries the balance forward.
        $this->assertSame(7500.0, $months[2]['balance']);

        $component
            ->assertSee('Allotted budget')
            ->assertSee('10,000.00')
            ->assertSee('2,500.00')
            ->assertSee('7,500.00')
            ->assertSee('Bal. 7,500.00')
            ->assertSee('25.00% of the allotted budget consumed.');
    }

    public function test_the_matrix_balance_follows_the_unsaved_grid(): void
    {
        $head = $this->schoolHead();
        $january = $this->line($head, 1, 10, 250); // 2,500.00
        $this->line($head, 2, 1, 1000);            // 1,000.00

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => 2026,
            'amount' => 10000,
        ]);

        $component = Livewire::actingAs($head)
            ->test(MemEditor::class, ['matrix' => $january->matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure supplies')
            ->set('lineForm.quantity', '3')
            ->set('lineForm.unit_cost', '500')
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $allocation = $component->instance()->allocation;

        // 2,500 saved + 1,500 not yet saved in January, plus February's 1,000.
        $this->assertSame(5000.0, $allocation['consumed']);
        $this->assertSame(5000.0, $allocation['remaining']);

        $months = collect($component->instance()->months())->keyBy('month');

        $this->assertSame(4000.0, $months[1]['total']);
        $this->assertSame(6000.0, $months[1]['balance']);
        $this->assertSame(5000.0, $months[2]['balance']);
    }

    public function test_the_matrix_editor_reports_an_unset_allotment_without_a_negative_balance(): void
    {
        $head = $this->schoolHead();
        $january = $this->line($head, 1, 10, 100); // 1,000.00

        $component = Livewire::actingAs($head)
            ->test(MemEditor::class, ['matrix' => $january->matrix]);
        $allocation = $component->instance()->allocation;

        $this->assertNull($allocation['allotted']);
        $this->assertSame(1000.0, $allocation['consumed']);
        $this->assertSame(0.0, $allocation['remaining']);
        $this->assertFalse($allocation['is_over']);

        $months = collect($component->instance()->months())->keyBy('month');
        $this->assertNull($months[1]['balance']);

        $component
            ->assertSee('Not set')
            ->assertSee('No allotment budget has been set for CY 2026 yet.');
    }

    public function test_the_matrix_editor_flags_an_over_allotment(): void
    {
        $head = $this->schoolHead();
        $january = $this->line($head, 1, 10, 150); // 1,500.00

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => 2026,
            'amount' => 1000,
        ]);

        $component = Livewire::actingAs($head)
            ->test(MemEditor::class, ['matrix' => $january->matrix]);
        $allocation = $component->instance()->allocation;

        $this->assertTrue($allocation['is_over']);
        $this->assertSame(-500.0, $allocation['remaining']);
        $this->assertSame(150.0, $allocation['percent']);

        $months = collect($component->instance()->months())->keyBy('month');
        $this->assertSame(-500.0, $months[1]['balance']);

        $component
            ->assertSee('Over allotment')
            ->assertSee('More spent than allotted')
            ->assertSeeHtml('mem-month-tab__balance is-over');
    }

    public function test_the_matrix_editor_only_reads_its_own_schools_allotment(): void
    {
        $mine = $this->schoolHead('My Budget School');
        $other = $this->schoolHead('Other Budget School');
        $january = $this->line($mine, 1, 1, 100);

        SchoolAllotment::create([
            'school_id' => $mine->school_id,
            'year' => 2026,
            'amount' => 1000,
        ]);

        SchoolAllotment::create([
            'school_id' => $other->school_id,
            'year' => 2026,
            'amount' => 999000,
        ]);

        $allocation = Livewire::actingAs($mine)
            ->test(MemEditor::class, ['matrix' => $january->matrix])
            ->instance()
            ->allocation;

        $this->assertSame(1000.0, $allocation['allotted']);
        $this->assertSame(900.0, $allocation['remaining']);
    }

    // ------------------------------------------------------- certification

    public function test_the_budget_office_certifies_an_allotment_as_available(): void
    {
        $head = $this->schoolHead();
        $budget = $this->budgetOfficer();

        $allotment = SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => 2026,
            'amount' => 150000,
        ]);

        Livewire::actingAs($budget)
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openCertifyModal', $head->school_id)
            ->assertSet('certifyingSchoolId', (int) $head->school_id)
            ->call('certify', $head->school_id)
            ->assertHasNoErrors();

        $allotment->refresh();

        $this->assertTrue($allotment->isCertified());
        $this->assertSame($budget->id, $allotment->certified_by);
        $this->assertNotNull($allotment->certified_at);
    }

    public function test_the_certify_modal_reads_consumption_for_a_school_off_the_visible_page(): void
    {
        $offPage = $this->schoolHead('Alpha Allotment School');
        $this->schoolHead('Beta Allotment School');
        $budget = $this->budgetOfficer();

        $this->line($offPage, 1, 2, 150);

        SchoolAllotment::create([
            'school_id' => $offPage->school_id,
            'year' => 2026,
            'amount' => 1000,
        ]);

        Livewire::actingAs($budget)
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->set('perPage', 1)
            ->call('setPage', 2)
            ->call('openCertifyModal', $offPage->school_id)
            ->assertSee('Beta Allotment School')
            ->assertSee('Consumed so far: 300.00')
            ->assertDontSee('Consumed so far: 0.00');
    }

    public function test_an_allotment_that_was_never_set_cannot_be_certified(): void
    {
        $head = $this->schoolHead();

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('certify', $head->school_id);

        $this->assertSame(0, SchoolAllotment::query()->count());
        $this->assertStringContainsString(
            'before certifying it as available',
            (string) $component->get('errorMessage')
        );
    }

    public function test_changing_the_amount_clears_the_certification(): void
    {
        $head = $this->schoolHead();
        $budget = $this->budgetOfficer();

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => 2026,
            'amount' => 1000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        Livewire::actingAs($budget)
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->set('amounts.'.$head->school_id, '2000')
            ->call('save', $head->school_id)
            ->assertHasNoErrors();

        $allotment = SchoolAllotment::query()->firstOrFail();

        $this->assertSame(2000.0, (float) $allotment->amount);
        $this->assertFalse($allotment->isCertified());
        $this->assertNull($allotment->certified_by);
        $this->assertNull($allotment->certified_at);
    }

    public function test_resaving_the_same_amount_keeps_the_certification(): void
    {
        $head = $this->schoolHead();
        $budget = $this->budgetOfficer();

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => 2026,
            'amount' => 1000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        Livewire::actingAs($budget)
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->set('amounts.'.$head->school_id, '1000.00')
            ->call('save', $head->school_id)
            ->assertHasNoErrors();

        $this->assertTrue(SchoolAllotment::query()->firstOrFail()->isCertified());
    }

    public function test_only_the_budget_role_may_certify_an_allotment(): void
    {
        $head = $this->schoolHead();
        $administrator = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => 2026,
            'amount' => 1000,
        ]);

        Livewire::actingAs($administrator)
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('certify', $head->school_id)
            ->assertNotFound();

        $this->assertFalse(SchoolAllotment::query()->firstOrFail()->isCertified());
    }

    public function test_the_school_head_report_names_the_certifying_officer(): void
    {
        $head = $this->schoolHead();
        $budget = $this->budgetOfficer();
        $budget->forceFill([
            'name' => 'Maria Budget',
            'position' => 'Budget Officer II',
        ])->save();

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => (int) now()->year,
            'amount' => 10000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        $component = Livewire::actingAs($head)->test(WfpLineItemAllotment::class);

        $this->assertSame('Maria Budget', $component->instance()->certification['name']);
        $this->assertSame('Budget Officer II', $component->instance()->certification['position']);

        $component
            ->assertSee('Certifying Availability of Allotment:')
            ->assertSee('Maria Budget')
            ->assertSee('Budget Officer II')
            ->assertSee('Certified');
    }

    public function test_a_school_head_never_reads_another_schools_certification(): void
    {
        $mine = $this->schoolHead('My Certified School');
        $other = $this->schoolHead('Other Certified School');
        $budget = $this->budgetOfficer();
        $budget->forceFill(['name' => 'Maria Budget'])->save();

        SchoolAllotment::create([
            'school_id' => $mine->school_id,
            'year' => (int) now()->year,
            'amount' => 1000,
        ]);

        SchoolAllotment::create([
            'school_id' => $other->school_id,
            'year' => (int) now()->year,
            'amount' => 999000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        $component = Livewire::actingAs($mine)->test(WfpLineItemAllotment::class);

        $this->assertNull($component->instance()->certification);
        $component->assertDontSee('Maria Budget');
    }

    // ----------------------------------------------------------- monitoring

    public function test_the_year_overview_counts_allotments_consumption_and_certifications(): void
    {
        $mine = $this->schoolHead('Overview Mine');
        $other = $this->schoolHead('Overview Other');
        $uncertified = $this->schoolHead('Overview Uncertified');
        $budget = $this->budgetOfficer();

        $this->line($mine, 1, 10, 100);   // 1,000.00, allotted
        $this->line($uncertified, 1, 5, 100); // 500.00, no allotment

        SchoolAllotment::create([
            'school_id' => $mine->school_id,
            'year' => 2026,
            'amount' => 5000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        SchoolAllotment::create([
            'school_id' => $other->school_id,
            'year' => 2026,
            'amount' => 2000,
        ]);

        $overview = WfpAllotment::overview(2026);

        $this->assertSame(3, $overview['schools']);
        $this->assertSame(2, $overview['allotted_schools']);
        $this->assertSame(1, $overview['certified_schools']);
        $this->assertSame(7000.0, $overview['allotted_total']);
        // Only allotted schools' consumption: the uncertified school has no
        // ceiling, so its 500.00 is not read against money never allotted.
        $this->assertSame(1000.0, $overview['consumed_total']);
        $this->assertSame(6000.0, $overview['remaining_total']);
    }

    public function test_the_budget_dashboard_monitors_allotments_and_links_the_page(): void
    {
        $head = $this->schoolHead();
        $budget = $this->budgetOfficer();

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'year' => (int) now()->year,
            'amount' => 5000,
        ]);

        $html = Livewire::actingAs($budget)->test(BudgetAllotmentMonitor::class)->html();

        $this->assertStringContainsString('Allotment monitoring', $html);
        $this->assertStringContainsString('5,000.00', $html);
        $this->assertStringContainsString(route('budget.allotments.index'), $html);
    }

    public function test_the_dashboard_shows_the_monitor_to_the_budget_role_only(): void
    {
        $budget = $this->budgetOfficer();
        $sh = $this->schoolHead();

        $this->actingAs($budget)
            ->get(route('home'))
            ->assertOk()
            ->assertSeeLivewire('budget-allotment-monitor');

        $this->actingAs($sh)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSeeLivewire('budget-allotment-monitor');

        $html = Livewire::actingAs($sh)->test(BudgetAllotmentMonitor::class)->html();
        $this->assertStringContainsString('Page Not Found', $html);
    }

    // --------------------------------------------------------------- sidebar

    public function test_the_budget_sidebar_links_the_allotment_page_and_the_school_head_does_not_see_it(): void
    {
        $budgetHtml = Livewire::actingAs($this->budgetOfficer())->test(Sidebar::class)->html();
        $this->assertStringContainsString(route('budget.allotments.index'), $budgetHtml);

        $shHtml = Livewire::actingAs($this->schoolHead())->test(Sidebar::class)->html();
        $this->assertStringNotContainsString(route('budget.allotments.index'), $shHtml);
    }
}
