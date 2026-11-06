<?php

namespace Tests\Feature;

use App\Livewire\BudgetLineItemAllotment;
use App\Livewire\BudgetLineItemChecker;
use App\Livewire\Profile\UpdateProfile;
use App\Livewire\UsersTable;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Models\WfpReportCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Bookkeeper's multi-school assignment — the roster behind the
 * school-scoped checking pages.
 *
 * A Bookkeeper keeps the list of schools it is responsible for in Update My
 * Profile (the searchable Assigned Schools picker); an administrator can
 * keep it for them from the Users page. The list is the boundary: Monthly
 * Expenditure, Per Line Item Expenditure, their exports and the report's
 * correctness check read only the schools on it, and a school outside it is
 * refused however its id arrives — a URL, a Livewire call, or a crafted
 * payload.
 */
class BookkeeperSchoolAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /** Distinguishes the accounts a single test creates. */
    private int $bookkeepers = 0;

    /* ------------------------------------------------------------ helpers */

    private function administrator(): User
    {
        return User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
    }

    private function school(): School
    {
        $suffix = (string) random_int(1000, 9999);
        $district = District::create(['name' => 'Assignment District '.$suffix]);

        return School::create([
            'district_id' => $district->id,
            'name' => 'Assignment Elementary School '.$suffix,
            'school_id' => (string) random_int(100000, 999999),
        ]);
    }

    /** A Bookkeeper, stationed at its own school (which seeds the roster). */
    private function bookkeeper(?School $school = null): User
    {
        $this->bookkeepers++;

        return User::create([
            'name' => 'Bella Bookkeeper',
            'position' => 'Bookkeeper I',
            'username' => 'bookkeeper'.$this->bookkeepers,
            'email' => 'bookkeeper'.$this->bookkeepers.'@example.com',
            'password' => Hash::make('password123'),
            'role' => 'bookkeeper',
            'is_superadmin' => false,
            'school_id' => ($school ?? $this->school())->id,
        ]);
    }

    private function schoolHead(School $school): User
    {
        $suffix = (string) random_int(1000, 9999);

        return User::create([
            'name' => 'Maria School Head',
            'position' => 'Principal II',
            'username' => 'schoolhead'.$suffix,
            'email' => 'schoolhead'.$suffix.'@example.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);
    }

    /** One expenditure line, in its own month's matrix. */
    private function line(User $owner, int $month, int $year = 2026): MonthlyExpenditureItem
    {
        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $owner->school_id,
            'year' => $year,
            'month' => $month,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $owner->id,
        ]);

        return $matrix->items()->create([
            'position' => 1,
            'section' => MonthlyExpenditureMatrix::SECTION_OPERATIONS,
            'objective' => 'To keep the school running',
            'activity_description' => 'Procure supplies',
            'quantity' => 10,
            'unit_cost' => 250,
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
        ]);
    }

    /* ------------------------------------------------------- the own roster */

    public function test_a_new_bookkeeper_starts_assigned_to_its_base_school(): void
    {
        $school = $this->school();
        $bookkeeper = $this->bookkeeper($school);

        // The account's base school is its first assignment, so a freshly
        // created Bookkeeper never begins with a school it cannot open.
        $this->assertSame([$school->id], $bookkeeper->assignedSchoolIds());

        Livewire::actingAs($bookkeeper)
            ->test(UpdateProfile::class)
            ->call('openModal')
            ->assertSet('assigned_schools', [$school->id]);
    }

    public function test_the_profile_modal_shows_the_picker_for_bookkeepers_alone(): void
    {
        $bookkeeper = $this->bookkeeper();
        $head = $this->schoolHead($this->school());

        // The Bookkeeper's modal carries the roster picker…
        Livewire::actingAs($bookkeeper)
            ->test(UpdateProfile::class)
            ->call('openModal')
            ->assertSee('Assigned Schools')
            ->assertSee('removing one revokes your access to its records.');

        // …the School Head keeps the read-only line: their school is the
        // administrator's decision, not a form field.
        Livewire::actingAs($head)
            ->test(UpdateProfile::class)
            ->call('openModal')
            ->assertDontSee('Assigned Schools')
            ->assertSee('Your school assignment is managed by an administrator.');
    }

    public function test_the_picker_search_narrows_the_roster(): void
    {
        $home = $this->school();
        $second = $this->school();
        $third = $this->school();
        $bookkeeper = $this->bookkeeper($home);

        Livewire::actingAs($bookkeeper)
            ->test(UpdateProfile::class)
            ->call('openModal')
            ->assertSee($second->name)
            ->assertSee($third->name)
            // Typing a school's name narrows the menu to it — the other
            // school is no longer offered.
            ->set('schoolSearch', $second->name)
            ->assertSee($second->name)
            ->assertDontSee($third->name)
            // A term that matches nothing says so rather than listing all.
            ->set('schoolSearch', 'no such school xyz')
            ->assertSee('No schools match');
    }

    public function test_a_bookkeeper_saves_their_own_assignment_list(): void
    {
        $home = $this->school();
        $second = $this->school();
        $third = $this->school();
        $bookkeeper = $this->bookkeeper($home);
        $bookkeeper->syncAssignedSchools([$home->id, $second->id]);

        Livewire::actingAs($bookkeeper)
            ->test(UpdateProfile::class)
            ->call('openModal')
            ->assertSet('assigned_schools', [$home->id, $second->id])
            // Removing a chip and adding another changes nothing until Save.
            ->call('removeAssignedSchool', $home->id)
            ->call('addAssignedSchool', $third->id)
            ->assertSet('assigned_schools', [$second->id, $third->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('successMessage', fn ($value): bool => is_string($value) && str_contains($value, 'updated'));

        $bookkeeper->refresh();

        // The saved list is exactly what was on screen…
        $this->assertSame([$second->id, $third->id], $bookkeeper->assignedSchoolIds());
        $this->assertDatabaseMissing('user_school_assignments', [
            'user_id' => $bookkeeper->id,
            'school_id' => $home->id,
        ]);
        $this->assertDatabaseHas('user_school_assignments', [
            'user_id' => $bookkeeper->id,
            'school_id' => $third->id,
        ]);
        // …and the base school the administrator set is untouched.
        $this->assertSame($home->id, $bookkeeper->school_id);
    }

    public function test_the_profile_refuses_a_school_that_does_not_exist(): void
    {
        $home = $this->school();
        $bookkeeper = $this->bookkeeper($home);

        Livewire::actingAs($bookkeeper)
            ->test(UpdateProfile::class)
            ->call('openModal')
            // A crafted payload: the id names no school, so the save is
            // refused and nothing is written.
            ->set('assigned_schools', [999999])
            ->call('save')
            ->assertHasErrors(['assigned_schools.0' => 'exists']);

        $this->assertSame([$home->id], $bookkeeper->refresh()->assignedSchoolIds());
    }

    public function test_the_profile_refuses_a_duplicated_school(): void
    {
        $home = $this->school();
        $bookkeeper = $this->bookkeeper($home);

        Livewire::actingAs($bookkeeper)
            ->test(UpdateProfile::class)
            ->call('openModal')
            ->set('assigned_schools', [$home->id, $home->id])
            ->call('save')
            ->assertHasErrors(['assigned_schools.1' => 'distinct']);

        $this->assertSame([$home->id], $bookkeeper->refresh()->assignedSchoolIds());
    }

    public function test_a_non_bookkeeper_profile_ignores_a_crafted_assignment_list(): void
    {
        $head = $this->schoolHead($this->school());
        $elsewhere = $this->school();

        Livewire::actingAs($head)
            ->test(UpdateProfile::class)
            ->call('openModal')
            // The field is not part of a School Head's form at all, so a
            // crafted list is not validated — and never saved.
            ->set('assigned_schools', [$elsewhere->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$head->school_id], $head->refresh()->assignedSchoolIds());
    }

    public function test_each_bookkeeper_keeps_their_own_roster(): void
    {
        $firstHome = $this->school();
        $shared = $this->school();
        $outside = $this->school();

        $first = $this->bookkeeper($firstHome);
        $second = $this->bookkeeper($shared);
        $first->syncAssignedSchools([$firstHome->id, $shared->id]);

        $this->assertSame([$firstHome->id, $shared->id], $first->assignedSchoolIds());
        $this->assertSame([$shared->id], $second->assignedSchoolIds());

        // The first account saves its profile: only its own roster moves.
        Livewire::actingAs($first)
            ->test(UpdateProfile::class)
            ->call('openModal')
            ->assertSet('assigned_schools', [$firstHome->id, $shared->id])
            ->call('removeAssignedSchool', $shared->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$firstHome->id], $first->refresh()->assignedSchoolIds());
        $this->assertSame([$shared->id], $second->refresh()->assignedSchoolIds());

        // And each checking page lists its own roster alone.
        $this->actingAs($second)
            ->get(route('budget.lineitems.index'))
            ->assertOk()
            ->assertSee($shared->name)
            ->assertDontSee($firstHome->name)
            ->assertDontSee($outside->name);
    }

    /* ------------------------------------------------- the administrator's side */

    public function test_an_administrator_edits_a_bookkeepers_roster(): void
    {
        $admin = $this->administrator();
        $home = $this->school();
        $extra = $this->school();
        $bookkeeper = $this->bookkeeper($home);

        // The modal opens on the stored roster, and adding a school saves it.
        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openEditModal', $bookkeeper->id)
            ->assertSet('assigned_schools', [$home->id])
            ->call('addAssignedSchool', $extra->id)
            ->assertSet('assigned_schools', [$home->id, $extra->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertSame([$home->id, $extra->id], $bookkeeper->refresh()->assignedSchoolIds());

        // A second visit takes one back off the list.
        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openEditModal', $bookkeeper->id)
            ->assertSet('assigned_schools', [$home->id, $extra->id])
            ->call('removeAssignedSchool', $home->id)
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertSame([$extra->id], $bookkeeper->refresh()->assignedSchoolIds());
        // The base school the account is stationed at is separate.
        $this->assertSame($home->id, $bookkeeper->school_id);
    }

    public function test_an_administrator_can_revoke_every_assignment(): void
    {
        $admin = $this->administrator();
        $home = $this->school();
        $bookkeeper = $this->bookkeeper($home);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openEditModal', $bookkeeper->id)
            ->call('removeAssignedSchool', $home->id)
            ->assertSet('assigned_schools', [])
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertSame([], $bookkeeper->refresh()->assignedSchoolIds());

        // The checking pages still open, saying there is nothing assigned —
        // and the school the account was revoked from is refused.
        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index'))
            ->assertOk()
            ->assertSee('not assigned to any school yet');

        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.index'))
            ->assertOk()
            ->assertSee('not assigned to any school yet');

        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index', ['school' => $home->id]))
            ->assertNotFound();
    }

    public function test_an_administrator_assigns_several_schools_when_creating_a_bookkeeper(): void
    {
        $admin = $this->administrator();
        $home = $this->school();
        $extra = $this->school();

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'bookkeeper')
            ->set('school_division', (string) $home->district_id)
            // Picking the base school puts it on the list the moment it is
            // picked; the administrator then adds a second school.
            ->set('school_id_choice', (string) $home->id)
            ->call('addAssignedSchool', $extra->id)
            ->assertSet('assigned_schools', [$home->id, $extra->id])
            ->set('division_access', ['ELEMENTARY'])
            ->set('name', 'Bella Bookkeeper')
            ->set('username', 'bella')
            ->set('email', 'bella@example.com')
            ->set('password', 'supersecret1')
            ->call('saveUser')
            ->assertHasNoErrors();

        $created = User::where('username', 'bella')->firstOrFail();

        $this->assertSame($home->id, $created->school_id);
        $this->assertSame([$home->id, $extra->id], $created->assignedSchoolIds());
    }

    public function test_a_deleted_school_leaves_the_roster_and_the_picker(): void
    {
        $home = $this->school();
        $gone = $this->school();
        $bookkeeper = $this->bookkeeper($home);
        $bookkeeper->syncAssignedSchools([$home->id, $gone->id]);

        $gone->delete();

        // The assignment row went with the school, and the roster reads on
        // without it — a deleted school can never be opened again.
        $this->assertDatabaseMissing('user_school_assignments', ['school_id' => $gone->id]);
        $this->assertSame([$home->id], $bookkeeper->assignedSchoolIds());

        Livewire::actingAs($bookkeeper)
            ->test(UpdateProfile::class)
            ->call('openModal')
            ->assertSet('assigned_schools', [$home->id])
            ->assertDontSee($gone->name);
    }

    /* ---------------------------------------------------- the roster boundary */

    public function test_the_checking_pages_list_only_the_rostered_schools(): void
    {
        $home = $this->school();
        $second = $this->school();
        $outside = $this->school();
        $bookkeeper = $this->bookkeeper($home);
        $bookkeeper->syncAssignedSchools([$home->id, $second->id]);
        $this->line($this->schoolHead($second), 3);

        // The school index: the roster, including the school with nothing
        // encoded yet — and never a school outside it.
        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index'))
            ->assertOk()
            ->assertSee($home->name)
            ->assertSee($second->name)
            ->assertDontSee($outside->name);

        // The report picker offers the same roster.
        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemAllotment::class)
            ->assertSee($home->name)
            ->assertSee($second->name)
            ->assertDontSee($outside->name);
    }

    public function test_a_crafted_school_is_refused_on_the_checker_page(): void
    {
        $home = $this->school();
        $outside = $this->school();
        $bookkeeper = $this->bookkeeper($home);

        // The URL…
        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index', ['school' => $outside->id]))
            ->assertNotFound();

        // …and a crafted $wire.set() on the filter, which the server refuses
        // with the same 404 rather than rendering another school's lines.
        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemChecker::class)
            ->set('school', (string) $outside->id)
            ->assertNotFound();
    }

    public function test_a_crafted_school_is_refused_on_the_allotment_page(): void
    {
        $home = $this->school();
        $outside = $this->school();
        $bookkeeper = $this->bookkeeper($home);

        // The URL…
        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.index', ['school' => $outside->id]))
            ->assertNotFound();

        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.export', ['school' => $outside->id, 'year' => 2026]))
            ->assertNotFound();

        // …a crafted $wire.set() on the picker's value…
        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemAllotment::class)
            ->set('schoolId', (string) $outside->id)
            ->assertNotFound();

        // …and the picker's own action, called through the wire.
        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemAllotment::class)
            ->call('chooseSchool', $outside->id)
            ->assertNotFound();
    }

    public function test_revoking_an_assignment_refuses_every_later_request(): void
    {
        $home = $this->school();
        $away = $this->school();
        $bookkeeper = $this->bookkeeper($home);
        $bookkeeper->syncAssignedSchools([$home->id, $away->id]);
        $this->line($this->schoolHead($away), 3);

        // While assigned: the report, its export and its lines are reachable.
        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.index', ['school' => $away->id]))
            ->assertOk()
            ->assertSee($away->name);

        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.export', ['school' => $away->id, 'year' => 2026]))
            ->assertOk();

        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index', ['school' => $away->id]))
            ->assertOk();

        // The assignment is revoked — a profile save or an administrator's
        // edit, both of which replace the list whole.
        $bookkeeper->syncAssignedSchools([$home->id]);

        // The very next request for that school is refused: page, export,
        // lines.
        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.index', ['school' => $away->id]))
            ->assertNotFound();

        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.export', ['school' => $away->id, 'year' => 2026]))
            ->assertNotFound();

        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index', ['school' => $away->id]))
            ->assertNotFound();

        // The component refuses it at mount too…
        $html = Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemChecker::class, ['school' => (string) $away->id])
            ->html();

        $this->assertStringContainsString('Page Not Found', $html);

        // …while the account's own roster still opens, without the school.
        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index'))
            ->assertOk()
            ->assertDontSee($away->name);
    }

    public function test_a_revoked_school_cannot_be_checked_from_a_page_already_open(): void
    {
        $home = $this->school();
        $away = $this->school();
        $bookkeeper = $this->bookkeeper($home);
        $bookkeeper->syncAssignedSchools([$home->id, $away->id]);
        $this->line($this->schoolHead($away), 3);

        // The report is open on the school while it is still assigned.
        $component = Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemAllotment::class, ['schoolId' => (string) $away->id])
            ->assertSet('schoolId', (string) $away->id);

        // The assignment is revoked underneath the open page.
        $bookkeeper->syncAssignedSchools([$home->id]);

        // The check now finds no report to sign — the school resolves to
        // none for this account — and nothing is written.
        $component
            ->call('openCheckModal')
            ->call('check')
            ->assertSet('showCheckModal', false)
            ->assertSet('errorMessage', fn ($value): bool => is_string($value) && str_contains($value, 'Choose a school first'));

        $this->assertSame(0, WfpReportCheck::count());
    }
}
