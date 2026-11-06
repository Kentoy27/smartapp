<?php

namespace Tests\Feature;

use App\Livewire\SchoolDivisionMapper;
use App\Livewire\Sidebar;
use App\Livewire\UsersTable;
use App\Livewire\WfpLineItemAllotment;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\SchoolAllotmentAudit;
use App\Models\SchoolDivisionSetting;
use App\Models\User;
use App\Models\WfpPlan;
use App\Support\WfpAllotment;
use App\Support\WfpGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The High School separation: classification, division-scoped records,
 * independent balances, the sidebar suffixes, the division switcher and the
 * administrator's explicit mapping of legacy records.
 */
class SchoolDivisionSeparationTest extends TestCase
{
    use RefreshDatabase;

    private function school(string $name = 'High School One', ?string $schoolId = null): School
    {
        $district = District::create(['name' => 'District '.$name]);

        return School::create([
            'district_id' => $district->id,
            'name' => $name,
            'school_id' => $schoolId,
        ]);
    }

    /**
     * Configure the divisions a school operates.
     *
     * @param  array<int, string>  $divisions
     */
    private function offer(School $school, array $divisions): void
    {
        foreach ($divisions as $division) {
            SchoolDivisionSetting::create([
                'school_id' => $school->id,
                'division_code' => $division,
                'is_active' => true,
            ]);
        }
    }

    /**
     * A school-side account classified into one or more divisions.
     *
     * @param  array<int, string>|null  $access
     */
    private function schoolHead(School $school, string $sublevel, ?array $access = null): User
    {
        return User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
            'school_level' => 'HIGH_SCHOOL',
            'school_sublevel' => $sublevel,
            'division_access' => $access ?? [$sublevel],
        ]);
    }

    private function matrix(School $school, string $division, int $month = 1, int $year = 2026): MonthlyExpenditureMatrix
    {
        return MonthlyExpenditureMatrix::create([
            'school_id' => $school->id,
            'school_division' => $division,
            'year' => $year,
            'month' => $month,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
        ]);
    }

    private function line(MonthlyExpenditureMatrix $matrix, float $quantity, float $unitCost): MonthlyExpenditureItem
    {
        $item = new MonthlyExpenditureItem([
            'matrix_id' => $matrix->id,
            'position' => 1,
            'section' => MonthlyExpenditureMatrix::SECTION_OPERATIONS,
            'activity_description' => 'Procure supplies',
            'quantity' => $quantity,
            'unit' => 'ream',
            'unit_cost' => $unitCost,
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
        ]);
        $item->syncTotal();
        $item->save();

        return $item;
    }

    /* ------------------------------------------------ classification (users) */

    public function test_a_high_school_account_stores_its_sublevel_and_both_divisions(): void
    {
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $district = District::create(['name' => 'Division A']);
        $school = School::create(['district_id' => $district->id, 'name' => 'A School', 'school_id' => '111111']);
        $this->offer($school, ['JUNIOR_HIGH', 'SENIOR_HIGH']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'user')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id)
            ->set('division_access', ['JUNIOR_HIGH', 'SENIOR_HIGH'])
            ->set('name', 'High School Head')
            ->set('username', 'highschoolhead')
            ->set('email', 'high@example.com')
            ->set('password', 'password123')
            ->call('saveUser')
            ->assertHasNoErrors();

        $head = User::where('username', 'highschoolhead')->firstOrFail();

        $this->assertSame('HIGH_SCHOOL', $head->school_level);
        $this->assertSame('JUNIOR_HIGH', $head->school_sublevel);
        $this->assertSame(['JUNIOR_HIGH', 'SENIOR_HIGH'], $head->division_access);
        $this->assertSame(['JUNIOR_HIGH', 'SENIOR_HIGH'], $head->authorizedDivisions());
        $this->assertSame('JUNIOR_HIGH', $head->defaultDivision());
    }

    public function test_an_elementary_school_account_stores_elementary_access(): void
    {
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $district = District::create(['name' => 'Division A']);
        $school = School::create(['district_id' => $district->id, 'name' => 'A School', 'school_id' => '111111']);
        $this->offer($school, ['ELEMENTARY']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'user')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id)
            ->set('division_access', ['ELEMENTARY'])
            ->set('name', 'Elementary Head')
            ->set('username', 'elementaryhead')
            ->set('email', 'elementary@example.com')
            ->set('password', 'password123')
            ->call('saveUser')
            ->assertHasNoErrors();

        $head = User::where('username', 'elementaryhead')->firstOrFail();

        $this->assertSame('ELEMENTARY', $head->school_level);
        $this->assertSame('NONE', $head->school_sublevel);
        $this->assertSame(['ELEMENTARY'], $head->division_access);
        $this->assertSame(['ELEMENTARY'], $head->authorizedDivisions());
        $this->assertSame('ELEMENTARY', $head->defaultDivision());
    }

    public function test_an_integrated_school_can_assign_all_three_divisions(): void
    {
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $district = District::create(['name' => 'Division A']);
        $school = School::create(['district_id' => $district->id, 'name' => 'Integrated School', 'school_id' => '222222']);
        $this->offer($school, ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH']);

        $component = Livewire::actingAs($admin)->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'user')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id);

        // The form offers every division the school operates.
        $this->assertSame(
            ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH'],
            array_column($component->instance()->divisionAccessOptions(), 'value'),
        );

        $component
            ->set('division_access', ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH'])
            ->set('name', 'Integrated School Head')
            ->set('username', 'integratedhead')
            ->set('email', 'integrated@example.com')
            ->set('password', 'password123')
            ->call('saveUser')
            ->assertHasNoErrors();

        $head = User::where('username', 'integratedhead')->firstOrFail();

        $this->assertSame(['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH'], $head->division_access);
        $this->assertSame(['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH'], $head->authorizedDivisions());
        $this->assertTrue($head->canSwitchDivision());
        $this->assertSame('JUNIOR_HIGH', $head->defaultDivision());
    }

    public function test_a_user_receives_only_the_divisions_assigned_to_them(): void
    {
        $school = $this->school('Integrated School');
        $this->offer($school, ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH']);

        $head = $this->schoolHead($school, 'JUNIOR_HIGH');

        $this->assertSame(['JUNIOR_HIGH'], $head->authorizedDivisions());
        $this->assertFalse($head->canSwitchDivision());
        $this->assertFalse($head->canAccessDivision('ELEMENTARY'));
        $this->assertFalse($head->canAccessDivision('SENIOR_HIGH'));
    }

    public function test_the_top_navigation_lists_every_assigned_division(): void
    {
        $school = $this->school('Integrated School');
        $this->offer($school, ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH']);

        $head = $this->schoolHead($school, 'JUNIOR_HIGH', ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH']);

        $this->actingAs($head)->get(route('home'))
            ->assertOk()
            ->assertSee('id="divisionSwitcher"', false)
            ->assertSee('value="ELEMENTARY"', false)
            ->assertSee('value="JUNIOR_HIGH"', false)
            ->assertSee('value="SENIOR_HIGH"', false);

        // A single-division account gets no switcher, so no other division
        // can be reached from the header either.
        $jhsOnly = $this->schoolHead($school, 'JUNIOR_HIGH', ['JUNIOR_HIGH']);

        $this->actingAs($jhsOnly)->get(route('home'))
            ->assertOk()
            ->assertDontSee('id="divisionSwitcher"', false);
    }

    public function test_a_division_the_school_does_not_offer_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $district = District::create(['name' => 'Division A']);
        $school = School::create(['district_id' => $district->id, 'name' => 'Elementary School', 'school_id' => '111111']);
        $this->offer($school, ['ELEMENTARY']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'user')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id)
            ->set('division_access', ['ELEMENTARY', 'JUNIOR_HIGH']) // crafted
            ->set('name', 'Crafted Head')
            ->set('username', 'craftedhead')
            ->set('email', 'crafted@example.com')
            ->set('password', 'password123')
            ->call('saveUser')
            ->assertHasErrors(['division_access.1']);

        $this->assertNull(User::where('username', 'craftedhead')->first());
    }

    public function test_a_school_account_must_choose_at_least_one_division(): void
    {
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $district = District::create(['name' => 'Division A']);
        $school = School::create(['district_id' => $district->id, 'name' => 'A School', 'school_id' => '111111']);
        $this->offer($school, ['ELEMENTARY']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'user')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id)
            ->set('division_access', [])
            ->set('name', 'No Division Head')
            ->set('username', 'nodivisionhead')
            ->set('email', 'nodivision@example.com')
            ->set('password', 'password123')
            ->call('saveUser')
            ->assertHasErrors(['division_access']);

        $this->assertNull(User::where('username', 'nodivisionhead')->first());
    }

    public function test_editing_an_account_does_not_erase_its_division_access(): void
    {
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $school = $this->school('Integrated School');
        $this->offer($school, ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH']);

        $head = $this->schoolHead($school, 'JUNIOR_HIGH', ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openEditModal', $head->id)
            ->set('name', 'Renamed Head')
            ->set('email', 'renamed@example.com')
            ->call('saveUser')
            ->assertHasNoErrors();

        $head->refresh();

        $this->assertSame('Renamed Head', $head->name);
        $this->assertSame(['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH'], $head->division_access);
        $this->assertSame(['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH'], $head->authorizedDivisions());
    }

    public function test_a_non_school_role_cannot_carry_a_classification(): void
    {
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'budget')
            ->set('division_access', ['SENIOR_HIGH']) // crafted
            ->set('name', 'Budget User')
            ->set('username', 'budgetuser')
            ->set('email', 'budget@example.com')
            ->set('password', 'password123')
            ->call('saveUser')
            ->assertHasNoErrors();

        $budget = User::where('username', 'budgetuser')->firstOrFail();

        $this->assertSame('ELEMENTARY', $budget->school_level);
        $this->assertSame('NONE', $budget->school_sublevel);
        $this->assertNull($budget->division_access);
        $this->assertSame('budget', $budget->role);
    }

    public function test_the_legacy_high_backfill_adds_high_school_divisions(): void
    {
        $school = $this->school('Legacy NHS');

        SchoolDivisionSetting::create([
            'school_id' => $school->id,
            'division_code' => 'ELEMENTARY',
            'is_active' => true,
        ]);

        $head = User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
            'school_level' => 'HIGH',
            'school_sublevel' => 'NONE',
            'division_access' => null,
        ]);

        $migration = require database_path('migrations/2026_11_05_000001_backfill_legacy_high_school_divisions.php');
        $migration->up();

        $this->assertSame('HIGH_SCHOOL', $head->fresh()->school_level);
        $this->assertSame(
            ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH'],
            $school->fresh()->availableDivisions(),
        );

        // The backfill is idempotent.
        $migration->up();
        $this->assertSame(3, $school->fresh()->divisionSettings()->count());
    }

    public function test_the_integrated_school_backfill_adds_elementary(): void
    {
        $integrated = $this->school('Hinapunan IS');
        $this->offer($integrated, ['JUNIOR_HIGH', 'SENIOR_HIGH']);

        $highSchool = $this->school('Bula NHS');
        $this->offer($highSchool, ['JUNIOR_HIGH', 'SENIOR_HIGH']);

        $migration = require database_path('migrations/2026_11_05_000002_add_elementary_to_integrated_schools.php');
        $migration->up();

        $this->assertSame(
            ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH'],
            $integrated->fresh()->availableDivisions(),
        );
        $this->assertSame(
            ['JUNIOR_HIGH', 'SENIOR_HIGH'],
            $highSchool->fresh()->availableDivisions(),
        );

        // The backfill is idempotent.
        $migration->up();
        $this->assertSame(3, $integrated->fresh()->divisionSettings()->count());
    }

    public function test_checking_a_division_clears_the_missing_division_error(): void
    {
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $school = $this->school('A School');
        $this->offer($school, ['ELEMENTARY', 'JUNIOR_HIGH']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'user')
            ->set('school_division', (string) $school->district_id)
            ->set('school_id_choice', (string) $school->id)
            ->set('division_access', [])
            ->set('name', 'No Division Head')
            ->set('username', 'nodivisionhead')
            ->set('email', 'nodivision@example.com')
            ->set('password', 'password123')
            ->call('saveUser')
            ->assertHasErrors(['division_access'])
            ->set('division_access', ['ELEMENTARY'])
            ->assertHasNoErrors(['division_access']);
    }

    /* ------------------------------------------------------- sidebar labels */

    public function test_the_sidebar_names_the_junior_high_division(): void
    {
        $school = $this->school();
        $head = $this->schoolHead($school, 'JUNIOR_HIGH');

        $items = collect(Livewire::actingAs($head)->test(Sidebar::class)->instance()->items());
        $wfp = $items->firstWhere('path', 'wfp');

        $this->assertSame('WFP — Junior High', $wfp['label']);

        $labels = array_column($wfp['children'], 'label');

        $this->assertContains('Monthly Expenditure Matrix — Junior High', $labels);
        $this->assertContains('Per Line Item Expenditure — Junior High', $labels);
        $this->assertContains('Work & Financial Plan — Junior High', $labels);
    }

    public function test_the_sidebar_names_the_senior_high_division(): void
    {
        $school = $this->school();
        $head = $this->schoolHead($school, 'SENIOR_HIGH');

        $items = collect(Livewire::actingAs($head)->test(Sidebar::class)->instance()->items());
        $wfp = $items->firstWhere('path', 'wfp');

        $this->assertSame('WFP — Senior High', $wfp['label']);

        $labels = array_column($wfp['children'], 'label');

        $this->assertContains('Monthly Expenditure Matrix — Senior High', $labels);
        $this->assertContains('Work & Financial Plan — Senior High', $labels);
    }

    public function test_an_elementary_sidebar_carries_no_suffix(): void
    {
        $school = $this->school('Elementary School');
        $head = User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);

        $items = collect(Livewire::actingAs($head)->test(Sidebar::class)->instance()->items());
        $wfp = $items->firstWhere('path', 'wfp');

        $this->assertSame('WFP', $wfp['label']);
        $this->assertNotContains('Monthly Expenditure Matrix — Elementary', array_column($wfp['children'], 'label'));
    }

    /* --------------------------------------- independent records & balances */

    public function test_junior_and_senior_high_allotments_are_independent(): void
    {
        $school = $this->school();

        SchoolAllotment::create([
            'school_id' => $school->id,
            'school_division' => 'JUNIOR_HIGH',
            'year' => 2026,
            'amount' => 1000,
        ]);
        SchoolAllotment::create([
            'school_id' => $school->id,
            'school_division' => 'SENIOR_HIGH',
            'year' => 2026,
            'amount' => 2000,
        ]);

        $this->line($this->matrix($school, 'JUNIOR_HIGH', 3), 100, 5); // 500.00
        $this->line($this->matrix($school, 'SENIOR_HIGH', 3), 10, 5);  //  50.00

        // Each division reads its own allotment.
        $this->assertSame(1000.0, WfpAllotment::allotment($school->id, 2026, 'JUNIOR_HIGH'));
        $this->assertSame(2000.0, WfpAllotment::allotment($school->id, 2026, 'SENIOR_HIGH'));

        // Each division consumes only its own lines.
        $this->assertSame(
            [$school->id => 500.0],
            WfpAllotment::consumedBySchool([$school->id], 2026, 'JUNIOR_HIGH'),
        );
        $this->assertSame(
            [$school->id => 50.0],
            WfpAllotment::consumedBySchool([$school->id], 2026, 'SENIOR_HIGH'),
        );

        // The report's line items belong to one division each.
        $jhsLines = WfpAllotment::lineItems($school->id, 2026, 'JUNIOR_HIGH');
        $shsLines = WfpAllotment::lineItems($school->id, 2026, 'SENIOR_HIGH');

        $this->assertSame(500.0, WfpAllotment::total($jhsLines));
        $this->assertSame(50.0, WfpAllotment::total($shsLines));

        // The month column reads the division's own matrices.
        $jhsMonths = collect(WfpAllotment::months($school->id, 2026, 'JUNIOR_HIGH'))->keyBy('label');
        $this->assertSame(500.0, $jhsMonths['March']['budget'] ?? null);

        // The balances never cross.
        $this->assertSame(500.0, WfpAllotment::balance(1000.0, WfpAllotment::total($jhsLines))['remaining']);
        $this->assertSame(1950.0, WfpAllotment::balance(2000.0, WfpAllotment::total($shsLines))['remaining']);

        // The overview is read per division.
        $this->assertSame(500.0, WfpAllotment::overview(2026, 'JUNIOR_HIGH')['consumed_total']);
        $this->assertSame(1950.0, WfpAllotment::overview(2026, 'SENIOR_HIGH')['remaining_total']);
    }

    public function test_wfp_generation_uses_only_the_plans_division(): void
    {
        $school = $this->school();

        $this->line($this->matrix($school, 'JUNIOR_HIGH', 2), 10, 100);
        $this->line($this->matrix($school, 'SENIOR_HIGH', 2), 99, 100);

        $plan = WfpPlan::create([
            'school_id' => $school->id,
            'school_division' => 'JUNIOR_HIGH',
            'year' => 2026,
            'status' => WfpPlan::STATUS_DRAFT,
        ]);

        WfpGenerator::generate($plan);

        $items = $plan->items()->get();

        $this->assertCount(1, $items);
        $this->assertEquals(1000.0, (float) $items->first()->financial_target);
    }

    public function test_the_report_component_reads_the_heads_own_division(): void
    {
        $school = $this->school();
        $jhs = $this->schoolHead($school, 'JUNIOR_HIGH');
        $this->schoolHead($school, 'SENIOR_HIGH');

        $this->line($this->matrix($school, 'JUNIOR_HIGH', 4), 100, 5);
        $this->line($this->matrix($school, 'SENIOR_HIGH', 4), 10, 5);

        $component = Livewire::actingAs($jhs)->test(WfpLineItemAllotment::class);

        $this->assertSame(500.0, WfpAllotment::total($component->instance()->lineItems));

        // A crafted session cannot move the reader out of their divisions.
        session(['active_school_division' => 'SENIOR_HIGH']);

        $component = Livewire::actingAs($jhs)->test(WfpLineItemAllotment::class);

        $this->assertSame(500.0, WfpAllotment::total($component->instance()->lineItems));
    }

    /* --------------------------------------------------- cross-division denial */

    public function test_a_junior_high_head_cannot_open_a_senior_high_matrix(): void
    {
        $school = $this->school();
        $jhs = $this->schoolHead($school, 'JUNIOR_HIGH');

        $own = $this->matrix($school, 'JUNIOR_HIGH', 1);
        $other = $this->matrix($school, 'SENIOR_HIGH', 2);

        $this->actingAs($jhs)->get(route('wfp.matrices.edit', $own))->assertOk();
        $this->actingAs($jhs)->get(route('wfp.matrices.edit', $other))->assertNotFound();
        $this->actingAs($jhs)->get(route('wfp.matrices.export', $other))->assertNotFound();
    }

    public function test_a_junior_high_head_cannot_open_a_senior_high_plan(): void
    {
        $school = $this->school();
        $jhs = $this->schoolHead($school, 'JUNIOR_HIGH');

        $plan = WfpPlan::create([
            'school_id' => $school->id,
            'school_division' => 'SENIOR_HIGH',
            'year' => 2026,
            'status' => WfpPlan::STATUS_DRAFT,
        ]);

        $this->actingAs($jhs)->get(route('wfp.plans.edit', $plan))->assertNotFound();
        $this->actingAs($jhs)->get(route('wfp.plans.export', $plan))->assertNotFound();
    }

    public function test_a_matrix_list_only_shows_the_heads_own_division(): void
    {
        $school = $this->school();
        $jhs = $this->schoolHead($school, 'JUNIOR_HIGH');

        $own = $this->matrix($school, 'JUNIOR_HIGH', 1);
        $other = $this->matrix($school, 'SENIOR_HIGH', 2);

        $rows = Livewire::actingAs($jhs)
            ->test(\App\Livewire\MemMatrixList::class)
            ->instance()
            ->rows;

        $ids = collect($rows->items())->pluck('id')->all();

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    /* --------------------------------------------------------- division switch */

    public function test_a_dual_access_head_can_switch_divisions(): void
    {
        $school = $this->school();
        $head = $this->schoolHead($school, 'JUNIOR_HIGH', ['JUNIOR_HIGH', 'SENIOR_HIGH']);

        $this->assertSame('JUNIOR_HIGH', $head->defaultDivision());
        $this->assertSame('JUNIOR_HIGH', $head->activeDivision());
        $this->assertTrue($head->canSwitchDivision());

        $this->actingAs($head)
            ->post(route('division.switch'), ['division' => 'SENIOR_HIGH'])
            ->assertRedirect();

        $this->assertSame('SENIOR_HIGH', $head->activeDivision());
    }

    public function test_the_division_switcher_is_only_shown_to_school_heads(): void
    {
        $school = $this->school();
        $head = $this->schoolHead($school, 'JUNIOR_HIGH', ['JUNIOR_HIGH', 'SENIOR_HIGH']);

        $this->actingAs($head)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('id="divisionSwitcher"', false);

        foreach ([
            User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'budget', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]),
        ] as $nonSchoolHead) {
            $this->actingAs($nonSchoolHead)
                ->get(route('home'))
                ->assertOk()
                ->assertDontSee('id="divisionSwitcher"', false);
        }
    }

    public function test_a_division_switch_cannot_reach_an_unauthorized_division(): void
    {
        $school = $this->school();
        $head = $this->schoolHead($school, 'JUNIOR_HIGH');

        $this->assertFalse($head->canSwitchDivision());

        $this->actingAs($head)
            ->post(route('division.switch'), ['division' => 'SENIOR_HIGH'])
            ->assertForbidden();

        $this->assertSame('JUNIOR_HIGH', $head->activeDivision());
    }

    /* ------------------------------------------------------------ admin mapping */

    public function test_an_administrator_maps_elementary_records_to_a_division(): void
    {
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $school = $this->school();

        $matrix = $this->matrix($school, 'ELEMENTARY', 1);
        $allotment = SchoolAllotment::create([
            'school_id' => $school->id,
            'school_division' => 'ELEMENTARY',
            'year' => 2026,
            'amount' => 1500,
        ]);

        Livewire::actingAs($admin)
            ->test(SchoolDivisionMapper::class)
            ->set('school', (string) $school->id)
            ->set('sourceDivision', 'ELEMENTARY')
            ->set('targetDivision', 'JUNIOR_HIGH')
            ->call('map')
            ->assertHasNoErrors();

        $this->assertSame('JUNIOR_HIGH', $matrix->refresh()->school_division);
        $this->assertSame('JUNIOR_HIGH', $allotment->refresh()->school_division);
        $this->assertDatabaseHas('school_allotment_audits', [
            'school_id' => $school->id,
            'school_division' => 'JUNIOR_HIGH',
            'action' => SchoolAllotmentAudit::ACTION_MAPPED,
        ]);
    }

    public function test_a_conflicting_target_record_is_left_untouched(): void
    {
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $school = $this->school();

        $legacy = $this->matrix($school, 'ELEMENTARY', 1);
        $existing = $this->matrix($school, 'JUNIOR_HIGH', 1);

        $component = Livewire::actingAs($admin)
            ->test(SchoolDivisionMapper::class)
            ->set('school', (string) $school->id)
            ->set('sourceDivision', 'ELEMENTARY')
            ->set('targetDivision', 'JUNIOR_HIGH')
            ->call('map')
            ->assertHasNoErrors();

        // Both rows stay exactly where they were; the conflict is reported.
        $this->assertSame('ELEMENTARY', $legacy->refresh()->school_division);
        $this->assertSame('JUNIOR_HIGH', $existing->refresh()->school_division);
        $this->assertCount(1, $component->get('conflictNotes'));
    }

    public function test_the_mapping_page_is_administrator_only(): void
    {
        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);
        $superadmin = User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);

        $this->actingAs($budget)->get(route('division-mapping.index'))->assertNotFound();
        $this->actingAs($superadmin)->get(route('division-mapping.index'))->assertNotFound();
    }
}
