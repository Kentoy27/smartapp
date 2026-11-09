<?php

namespace Tests\Feature;

use App\Livewire\BudgetLineItemAllotment;
use App\Livewire\BudgetLineItemChecker;
use App\Livewire\MemEditor;
use App\Livewire\MemMatrixList;
use App\Livewire\Sidebar;
use App\Livewire\UsersTable;
use App\Livewire\WfpGenerateButton;
use App\Livewire\WfpLineItemAllotment;
use App\Livewire\WfpManager;
use App\Livewire\WfpSignatoryActions;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Models\WfpPlan;
use App\Models\WfpReportCheck;
use App\Support\PurePhpZipReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The Bookkeeper role: an account that works the Budget office-style,
 * read-only checking pages — Monthly Expenditure (the lines of the schools
 * it is assigned to) and Per Line Item Expenditure (those schools' annual
 * reports) — and signs the report's "Checked as to the Correctness of
 * Account Titles and Codes" block. Its reach is the schools on its
 * assignment list (user_school_assignments); it never writes a school's
 * plan, and it carries no OPCRF, MOV or AIP destination.
 */
class BookkeeperRoleTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        return User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
    }

    private function budgetOfficer(): User
    {
        return User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);
    }

    private function school(): School
    {
        $suffix = (string) random_int(1000, 9999);
        $district = District::create(['name' => 'Bookkeeping District '.$suffix]);

        return School::create([
            'district_id' => $district->id,
            'name' => 'Bookkeeping Elementary School '.$suffix,
            'school_id' => (string) random_int(100000, 999999),
        ]);
    }

    /** The account under test: a Bookkeeper attached to a school. */
    private function bookkeeper(?School $school = null, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Bella Bookkeeper',
            'position' => 'Bookkeeper I',
            'username' => 'bookkeeper',
            'email' => 'bookkeeper@example.com',
            'password' => Hash::make('password123'),
            'role' => 'bookkeeper',
            'is_superadmin' => false,
            'school_id' => ($school ?? $this->school())->id,
        ], $attributes));
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
    private function line(User $owner, int $month, string $title = 'Office Supplies Expenses', string $code = '5020301000', int $year = 2026): MonthlyExpenditureItem
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
            'account_title' => $title,
            'account_code' => $code,
        ]);
    }

    /**
     * The workbook's parts, read back out of the response bytes — through
     * the same pure-PHP reader the app itself falls back on.
     *
     * @return array<string, string>
     */
    private function partsOf(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'bookkeeper-check-').'.xlsx';
        file_put_contents($path, $bytes);

        $parts = (new PurePhpZipReader($path))->readAll();

        unlink($path);

        return $parts;
    }

    /* ------------------------------------------------------------ the role */

    public function test_an_administrator_can_create_a_bookkeeper_attached_to_a_school(): void
    {
        $admin = $this->administrator();
        $school = $this->school();

        $component = Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->assertSee('Bookkeeper')
            ->set('role', 'bookkeeper')
            // The school cascade is offered for the Bookkeeper like for the
            // SH — the account records the school it is based at.
            ->assertSee('School Division', false);

        // The password field stays a real field for the role; nothing is
        // derived from the School ID.
        $component->assertSee('formPassword', false);
        $this->assertTrue($component->instance()->belongsToSchool());

        $component
            ->set('school_division', (string) $school->district_id)
            ->set('school_id_choice', (string) $school->id)
            ->set('division_access', ['ELEMENTARY'])
            // The School-ID-as-login default stays an SH convenience: the
            // Bookkeeper's login is not touched by picking a school.
            ->assertSet('username', '')
            ->set('name', 'Bella Bookkeeper')
            ->set('username', 'bella')
            ->set('email', 'bella@example.com')
            ->set('password', 'supersecret1')
            ->call('saveUser')
            ->assertHasNoErrors();

        $bookkeeper = User::where('username', 'bella')->firstOrFail();
        $this->assertSame('bookkeeper', $bookkeeper->role);
        $this->assertSame($school->id, $bookkeeper->school_id);
        $this->assertSame('Bookkeeper', $bookkeeper->roleLabel());
        $this->assertFalse($bookkeeper->is_superadmin);
        // The typed password stands; the school ID is never minted as one.
        $this->assertTrue(Hash::check('supersecret1', $bookkeeper->password));
        $this->assertFalse(Hash::check((string) $school->school_id, $bookkeeper->password));
        // The picked base school is also the account's first assignment, so
        // the roster is never empty for an account that just got a school.
        $this->assertSame([$school->id], $bookkeeper->assignedSchoolIds());
    }

    public function test_a_bookkeeper_cannot_be_created_without_a_school(): void
    {
        $admin = $this->administrator();

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'bookkeeper')
            ->set('name', 'No School Bookkeeper')
            ->set('username', 'noschool')
            ->set('email', 'noschool@example.com')
            ->set('password', 'supersecret1')
            ->call('saveUser')
            ->assertHasErrors(['school_division', 'school_id_choice']);

        $this->assertDatabaseMissing('users', ['username' => 'noschool']);
    }

    public function test_editing_a_bookkeeper_prefills_their_school_and_keeps_it(): void
    {
        $admin = $this->administrator();
        $bookkeeper = $this->bookkeeper();
        $schoolId = $bookkeeper->school_id;

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openEditModal', $bookkeeper->id)
            ->assertSet('role', 'bookkeeper')
            ->assertSet('school_division', (string) $bookkeeper->school->district_id)
            ->assertSet('school_id_choice', (string) $bookkeeper->school_id)
            // The roster opens on what is stored, so a save that never
            // touched the picker leaves the assignments exactly as they were.
            ->assertSet('assigned_schools', [$bookkeeper->school_id])
            ->set('name', 'Bella B. Bookkeeper')
            ->call('saveUser')
            ->assertHasNoErrors();

        $bookkeeper->refresh();

        $this->assertSame('Bella B. Bookkeeper', $bookkeeper->name);
        $this->assertSame('bookkeeper', $bookkeeper->role);
        $this->assertSame($schoolId, $bookkeeper->school_id);
        $this->assertSame([$schoolId], $bookkeeper->assignedSchoolIds());
    }

    public function test_the_role_modal_can_assign_the_bookkeeper_role(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create(['is_superadmin' => false, 'role' => 'viewer']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openRoleModal', $target->id)
            ->set('selectedRole', 'bookkeeper')
            ->call('updateRole')
            ->assertHasNoErrors();

        $this->assertSame('bookkeeper', $target->refresh()->role);
        $this->assertFalse($target->is_superadmin);
    }

    /* ------------------------------------------------- the checking pages */

    public function test_a_bookkeeper_gets_the_budget_style_navigation_and_no_staff_pages(): void
    {
        $bookkeeper = $this->bookkeeper();

        Livewire::actingAs($bookkeeper)
            ->test(Sidebar::class)
            ->assertSee('WFP')
            ->assertSee('Monthly Expenditure')
            ->assertSee('Per Line Item Expenditure')
            // The Budget-only destinations are not the Bookkeeper's…
            ->assertDontSee('Work & Financial Plan')
            ->assertDontSee('School Allotments')
            ->assertDontSee('Review AIP')
            // …and neither are the staff pages.
            ->assertDontSee('OPCRF')
            ->assertDontSee('MOV')
            ->assertDontSee('AIP');

        // The checking pages open…
        $this->actingAs($bookkeeper)->get(route('budget.lineitems.index'))->assertOk();
        $this->actingAs($bookkeeper)->get(route('budget.per-line-item.index'))->assertOk();

        // …while the staff pages and the Budget-only pages are refused.
        $this->actingAs($bookkeeper)->get(route('opcrf.index'))->assertNotFound();
        $this->actingAs($bookkeeper)->get(route('mov.index'))->assertNotFound();
        $this->actingAs($bookkeeper)->get(route('opcrf.template'))->assertNotFound();
        $this->actingAs($bookkeeper)->get(route('aip.index'))->assertNotFound();
        $this->actingAs($bookkeeper)->get(route('wfp.index'))->assertOk()->assertSee('Plans in your scope');
        $this->actingAs($bookkeeper)->get(route('wfp.matrices.index'))->assertOk()->assertSee('Monthly Expenditure');
        $this->actingAs($bookkeeper)->get(route('wfp.lineitems.index'))->assertOk()->assertSee('Per Line Item Expenditure');
        $this->actingAs($bookkeeper)->get(route('budget.allotments.index'))->assertNotFound();
    }

    public function test_a_bookkeeper_reads_the_lines_of_assigned_schools_only(): void
    {
        $home = $this->school();
        $bookkeeper = $this->bookkeeper($home);
        $assigned = $this->schoolHead($this->school());
        $outside = $this->schoolHead($this->school());
        $bookkeeper->syncAssignedSchools([$home->id, $assigned->school_id]);
        $this->line($assigned, 3, 'Electricity Expense', '5020402000');
        $this->line($outside, 4, 'Water Expense', '5020403000');

        // The index: the assigned roster — including the school with nothing
        // encoded — and never a school outside it.
        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index'))
            ->assertOk()
            ->assertSee('Monthly Expenditure')
            ->assertSee($home->name)
            ->assertSee($assigned->school->name)
            ->assertDontSee($outside->school->name);

        // One assigned school's month-by-month lines, read-only.
        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index', ['school' => $assigned->school_id]))
            ->assertOk()
            ->assertSee('Electricity Expense')
            ->assertSee('5020402000');

        // An unassigned school is refused — by URL…
        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index', ['school' => $outside->school_id]))
            ->assertNotFound();

        // …and by a crafted Livewire call, refused with the same 404.
        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemChecker::class)
            ->call('openSchool', $outside->school_id)
            ->assertNotFound();

        // The same action opens an assigned school's detail.
        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemChecker::class)
            ->call('openSchool', $assigned->school_id)
            ->assertSee('Electricity Expense')
            ->assertSee('5020402000');
    }

    public function test_a_bookkeeper_reads_and_exports_assigned_schools_reports_only(): void
    {
        $bookkeeper = $this->bookkeeper();
        $head = $this->schoolHead($this->school());
        $bookkeeper->syncAssignedSchools([$bookkeeper->school_id, $head->school_id]);
        $this->line($head, 3);

        // The assigned school's report opens…
        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.index', ['school' => $head->school_id]))
            ->assertOk()
            ->assertSee('Per Line Item Expenditure')
            ->assertSee($head->school->name)
            ->assertSee('Office Supplies Expenses');

        // …and exports.
        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.export', ['school' => $head->school_id, 'year' => 2026]))
            ->assertOk()
            ->assertDownload();

        // An unassigned school's report and export are refused: the export
        // is the report's figures leaving the server.
        $outside = $this->schoolHead($this->school());
        $this->line($outside, 3);

        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.index', ['school' => $outside->school_id]))
            ->assertNotFound();

        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.export', ['school' => $outside->school_id, 'year' => 2026]))
            ->assertNotFound();
    }

    public function test_a_bookkeeper_can_open_wfp_documents_read_only_but_cannot_manage_them(): void
    {
        $bookkeeper = $this->bookkeeper();
        $head = $this->schoolHead($bookkeeper->school);
        $matrix = $this->line($head, 3)->matrix;

        $this->assertStringContainsString('Page Not Found', Livewire::actingAs($bookkeeper)->test(MemMatrixList::class)->html());
        $this->assertStringContainsString('Page Not Found', Livewire::actingAs($bookkeeper)->test(WfpGenerateButton::class)->html());
        $this->assertStringContainsString('Page Not Found', Livewire::actingAs($bookkeeper)->test(WfpLineItemAllotment::class)->html());

        Livewire::actingAs($bookkeeper)
            ->test(WfpManager::class)
            ->assertSee('Work and Financial Plans');

        Livewire::actingAs($bookkeeper)
            ->test(MemEditor::class, ['matrix' => $matrix])
            ->assertSee($matrix->periodLabel())
            ->assertDontSee('Save changes');

        $this->assertTrue($matrix->canBeViewedBy($bookkeeper));
        $this->assertFalse($matrix->managedBy($bookkeeper));

        // The CSV authoring template is the SH's too.
        $this->actingAs($bookkeeper)
            ->get(route('wfp.matrices.template'))
            ->assertNotFound();
    }

    /* ---------------------------------------------------------- the check */

    public function test_the_check_action_is_offered_to_the_bookkeeper_alone(): void
    {
        $bookkeeper = $this->bookkeeper();
        $budget = $this->budgetOfficer();
        // The head works the bookkeeper's base school, so all three copies
        // below read the same report.
        $head = $this->schoolHead($bookkeeper->school);
        $this->line($head, 3);
        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'school_division' => $head->activeDivision(),
            'year' => 2026,
            'amount' => 10000,
        ]);

        // The Bookkeeper's copy carries the action and the signatory block.
        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemAllotment::class)
            ->assertSee('Checked as to the Correctness of Account Titles and Codes')
            ->assertSee('Sign check');

        // The Budget office reads the same block but gets no action — it
        // does not stand for the Bookkeeper's office.
        Livewire::actingAs($budget)
            ->test(BudgetLineItemAllotment::class)
            ->assertSee('Checked as to the Correctness of Account Titles and Codes')
            ->assertDontSee('Sign check')
            ->assertSee('Sign certification');

        // The School Head's own copy reads the block too, with no action.
        Livewire::actingAs($head)
            ->test(WfpLineItemAllotment::class)
            ->assertSee('Checked as to the Correctness of Account Titles and Codes')
            ->assertDontSee('Sign check')
            ->assertDontSee('Sign certification');
    }

    public function test_a_bookkeeper_signs_an_assigned_schools_check_and_the_report_names_them(): void
    {
        $bookkeeper = $this->bookkeeper();
        // The checked school is another of the schools on the bookkeeper's
        // roster, not the one the account is based at: the check belongs to
        // the report on screen.
        $head = $this->schoolHead($this->school());
        $bookkeeper->syncAssignedSchools([$bookkeeper->school_id, $head->school_id]);
        $this->line($head, 3);

        $component = Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemAllotment::class, ['schoolId' => (string) $head->school_id])
            ->assertSet('showCheckModal', false)
            ->call('openCheckModal')
            ->assertSet('showCheckModal', true)
            ->call('check')
            ->assertSet('showCheckModal', false);

        $this->assertDatabaseHas('wfp_report_checks', [
            'school_id' => $head->school_id,
            'year' => 2026,
            'checked_by' => $bookkeeper->id,
        ]);

        // The report now carries the checker, their position and the date.
        $checkedAt = now()->format('M j, Y');

        $component
            ->assertSee('Bella Bookkeeper')
            ->assertSee('Bookkeeper I')
            ->assertSee('Checked '.$checkedAt);

        // The School Head's copy of the same report shows the same names —
        // the check is on the document, not on the checker's screen.
        Livewire::actingAs($head)
            ->test(WfpLineItemAllotment::class)
            ->assertSee('Bella Bookkeeper')
            ->assertSee('Checked '.$checkedAt);

        // …and so does the Budget office's copy.
        Livewire::actingAs($this->budgetOfficer())
            ->test(BudgetLineItemAllotment::class, ['schoolId' => (string) $head->school_id])
            ->assertSee('Bella Bookkeeper');
    }

    public function test_wfp_signatory_actions_are_role_specific(): void
    {
        $bookkeeper = $this->bookkeeper();
        $budget = $this->budgetOfficer();
        $head = $this->schoolHead($bookkeeper->school);
        $this->line($head, 3);
        WfpPlan::create([
            'school_id' => $head->school_id,
            'school_division' => $head->activeDivision(),
            'year' => 2026,
            'status' => WfpPlan::STATUS_DRAFT,
        ]);

        SchoolAllotment::create([
            'school_id' => $head->school_id,
            'school_division' => $head->activeDivision(),
            'year' => 2026,
            'amount' => 10000,
        ]);

        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemChecker::class)
            ->assertSee('Sign check')
            ->assertDontSee('Sign certification');

        Livewire::actingAs($budget)
            ->test(BudgetLineItemChecker::class)
            ->assertSee('Sign certification')
            ->assertDontSee('Sign check');

        Livewire::actingAs($bookkeeper)
            ->test(WfpManager::class)
            ->assertSee('Sign check')
            ->assertDontSee('Sign certification');

        Livewire::actingAs($budget)
            ->test(WfpManager::class)
            ->assertSee('Sign certification')
            ->assertDontSee('Sign check');

        Livewire::actingAs($bookkeeper)
            ->test(WfpSignatoryActions::class, ['schoolId' => $head->school_id, 'year' => 2026])
            ->assertSee('Sign check')
            ->assertDontSee('Sign certification')
            ->call('signCheck')
            ->assertSee('Bella Bookkeeper');

        $this->assertDatabaseHas('wfp_report_checks', [
            'school_id' => $head->school_id,
            'year' => 2026,
            'checked_by' => $bookkeeper->id,
        ]);

        Livewire::actingAs($bookkeeper)
            ->test(WfpSignatoryActions::class, ['schoolId' => $head->school_id, 'year' => 2026])
            ->call('signCertification')
            ->assertNotFound();

        Livewire::actingAs($budget)
            ->test(WfpSignatoryActions::class, ['schoolId' => $head->school_id, 'year' => 2026])
            ->assertSee('Sign certification')
            ->assertDontSee('Sign check')
            ->call('signCertification')
            ->assertSee($budget->name);

        $this->assertDatabaseHas('school_allotments', [
            'school_id' => $head->school_id,
            'year' => 2026,
            'certified_by' => $budget->id,
        ]);

        Livewire::actingAs($budget)
            ->test(WfpSignatoryActions::class, ['schoolId' => $head->school_id, 'year' => 2026])
            ->call('signCheck')
            ->assertNotFound();

    }

    public function test_the_check_prints_on_the_excel_export(): void
    {
        $bookkeeper = $this->bookkeeper();
        $head = $this->schoolHead($this->school());
        $bookkeeper->syncAssignedSchools([$bookkeeper->school_id, $head->school_id]);
        $this->line($head, 3);

        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemAllotment::class, ['schoolId' => (string) $head->school_id])
            ->call('check');

        $response = $this->actingAs($bookkeeper)->get(route('budget.per-line-item.export', [
            'school' => $head->school_id,
            'year' => 2026,
        ]));
        $response->assertOk();

        $sheet = $this->partsOf($response->getContent())['xl/worksheets/sheet1.xml'];

        $this->assertStringContainsString('BELLA BOOKKEEPER', $sheet);
        $this->assertStringContainsString('Bookkeeper I', $sheet);
        $this->assertStringContainsString('Checked '.now()->format('M j, Y'), $sheet);
    }

    public function test_only_a_bookkeeper_can_give_the_check(): void
    {
        $bookkeeper = $this->bookkeeper();
        $budget = $this->budgetOfficer();
        $head = $this->schoolHead($this->school());
        $this->line($head, 3);

        // The Budget officer reaches the report but is refused the action
        // (the abort throws when the guard is probed directly).
        $component = Livewire::actingAs($budget)
            ->test(BudgetLineItemAllotment::class, ['schoolId' => (string) $head->school_id]);

        $thrown = null;

        try {
            $component->instance()->openCheckModal();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(NotFoundHttpException::class, $thrown);

        // Every other role is refused the page itself.
        foreach ([
            $head,
            User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]),
        ] as $outsider) {
            $html = Livewire::actingAs($outsider)->test(BudgetLineItemAllotment::class)->html();

            $this->assertStringContainsString('Page Not Found', $html);
        }

        $this->assertSame(0, WfpReportCheck::count());
    }

    public function test_a_year_with_no_lines_has_nothing_to_check(): void
    {
        $bookkeeper = $this->bookkeeper();
        // The head works the bookkeeper's base school — on its roster.
        $head = $this->schoolHead($bookkeeper->school);

        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemAllotment::class, ['schoolId' => (string) $head->school_id])
            ->call('openCheckModal')
            ->call('check')
            ->assertSet('showCheckModal', false)
            ->assertSet('errorMessage', fn ($value): bool => is_string($value) && str_contains($value, 'nothing to check'));

        $this->assertSame(0, WfpReportCheck::count());
    }

    public function test_writing_the_lines_clears_the_check(): void
    {
        $bookkeeper = $this->bookkeeper();
        // The head works the bookkeeper's base school — on its roster.
        $head = $this->schoolHead($bookkeeper->school);
        $matrix = $this->line($head, 3)->matrix;

        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemAllotment::class, ['schoolId' => (string) $head->school_id])
            ->call('check');

        $this->assertSame(1, WfpReportCheck::count());

        // The SH writes the matrix: any structural edit auto-saves, which
        // clears the year's check.
        Livewire::actingAs($head)
            ->test(MemEditor::class, ['matrix' => $matrix])
            ->call('addRow');

        $this->assertSame(0, WfpReportCheck::count());
    }

    public function test_deleting_a_matrix_clears_the_check(): void
    {
        $bookkeeper = $this->bookkeeper();
        // The head works the bookkeeper's base school — on its roster.
        $head = $this->schoolHead($bookkeeper->school);
        $matrix = $this->line($head, 3)->matrix;

        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemAllotment::class, ['schoolId' => (string) $head->school_id])
            ->call('check');

        $this->assertSame(1, WfpReportCheck::count());

        Livewire::actingAs($head)
            ->test(MemMatrixList::class)
            ->call('openConfirmDelete', $matrix->id)
            ->call('confirmDelete')
            ->assertSet('successMessage', fn ($value): bool => is_string($value) && str_contains($value, 'cleared'));

        $this->assertSame(0, WfpReportCheck::count());
    }
}
