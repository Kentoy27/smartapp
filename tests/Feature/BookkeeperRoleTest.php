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
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Models\WfpReportCheck;
use App\Support\PurePhpZipReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The Bookkeeper role: a division-wide account that works the Budget
 * office-style, read-only checking pages — Monthly Expenditure (every
 * school's lines) and Per Line Item Expenditure (each school's annual
 * report) — and signs the report's "Checked as to the Correctness of
 * Account Titles and Codes" block. It never writes a school's plan, and it
 * carries no OPCRF, MOV or AIP destination.
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

        // The password field stays a real field for the role: no auto
        // password, so nothing is derived from the School ID.
        $this->assertFalse($component->instance()->hasAutoPassword());
        $this->assertTrue($component->instance()->belongsToSchool());

        $component
            ->set('school_division', (string) $school->district_id)
            ->set('school_id_choice', (string) $school->id)
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
            ->set('name', 'Bella B. Bookkeeper')
            ->call('saveUser')
            ->assertHasNoErrors();

        $bookkeeper->refresh();

        $this->assertSame('Bella B. Bookkeeper', $bookkeeper->name);
        $this->assertSame('bookkeeper', $bookkeeper->role);
        $this->assertSame($schoolId, $bookkeeper->school_id);
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

        // …while the staff pages, the SH's WFP workspace and the Budget
        // office's own pages are refused by their guards.
        $this->actingAs($bookkeeper)->get(route('opcrf.index'))->assertNotFound();
        $this->actingAs($bookkeeper)->get(route('mov.index'))->assertNotFound();
        $this->actingAs($bookkeeper)->get(route('opcrf.template'))->assertNotFound();
        $this->actingAs($bookkeeper)->get(route('aip.index'))->assertNotFound();
        $this->actingAs($bookkeeper)->get(route('wfp.index'))->assertNotFound();
        $this->actingAs($bookkeeper)->get(route('wfp.matrices.index'))->assertNotFound();
        $this->actingAs($bookkeeper)->get(route('wfp.lineitems.index'))->assertNotFound();
        $this->actingAs($bookkeeper)->get(route('budget.allotments.index'))->assertNotFound();
    }

    public function test_a_bookkeeper_reads_every_schools_lines_on_the_monthly_expenditure_page(): void
    {
        $bookkeeper = $this->bookkeeper();
        $first = $this->schoolHead($this->school());
        $second = $this->schoolHead($this->school());
        $this->line($first, 3, 'Electricity Expense', '5020402000');
        $this->line($second, 4, 'Water Expense', '5020403000');

        // The index: every school, like the Budget office reads it.
        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index'))
            ->assertOk()
            ->assertSee('Monthly Expenditure')
            ->assertSee($first->school->name)
            ->assertSee($second->school->name);

        // One school's month-by-month lines, read-only.
        $this->actingAs($bookkeeper)
            ->get(route('budget.lineitems.index', ['school' => $first->school_id]))
            ->assertOk()
            ->assertSee('Electricity Expense')
            ->assertSee('5020402000');

        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemChecker::class)
            ->call('openSchool', $second->school_id)
            ->assertSee('Water Expense')
            ->assertSee('5020403000');
    }

    public function test_a_bookkeeper_reads_and_exports_any_schools_report(): void
    {
        $bookkeeper = $this->bookkeeper();
        $head = $this->schoolHead($this->school());
        $this->line($head, 3);

        $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.index', ['school' => $head->school_id]))
            ->assertOk()
            ->assertSee('Per Line Item Expenditure')
            ->assertSee($head->school->name)
            ->assertSee('Office Supplies Expenses');

        $response = $this->actingAs($bookkeeper)
            ->get(route('budget.per-line-item.export', ['school' => $head->school_id, 'year' => 2026]));

        $response->assertOk()->assertDownload();
    }

    public function test_the_school_head_wfp_pages_are_refused_for_a_bookkeeper(): void
    {
        $bookkeeper = $this->bookkeeper();
        $head = $this->schoolHead($bookkeeper->school);
        $matrix = $this->line($head, 3)->matrix;

        foreach ([
            MemMatrixList::class,
            MemEditor::class,
            WfpManager::class,
            WfpGenerateButton::class,
            WfpLineItemAllotment::class,
        ] as $component) {
            $html = Livewire::actingAs($bookkeeper)->test($component)->html();

            $this->assertStringContainsString(
                'Page Not Found',
                $html,
                $component.' rendered for a Bookkeeper; the SH workspace is not its interface.'
            );
        }

        // With the model passed, the matrix editor still refuses the mount.
        $html = Livewire::actingAs($bookkeeper)
            ->test(MemEditor::class, ['matrix' => $matrix])
            ->html();

        $this->assertStringContainsString('Page Not Found', $html);

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
        $head = $this->schoolHead($this->school());
        $this->line($head, 3);

        // The Bookkeeper's copy carries the action and the signatory block.
        Livewire::actingAs($bookkeeper)
            ->test(BudgetLineItemAllotment::class)
            ->assertSee('Checked as to the Correctness of Account Titles and Codes')
            ->assertSee('Check report');

        // The Budget office reads the same block but gets no action — it
        // does not stand for the Bookkeeper's office.
        Livewire::actingAs($budget)
            ->test(BudgetLineItemAllotment::class)
            ->assertSee('Checked as to the Correctness of Account Titles and Codes')
            ->assertDontSee('Check report');

        // The School Head's own copy reads the block too, with no action.
        Livewire::actingAs($head)
            ->test(WfpLineItemAllotment::class)
            ->assertSee('Checked as to the Correctness of Account Titles and Codes')
            ->assertDontSee('Check report');
    }

    public function test_a_bookkeeper_signs_any_schools_check_and_the_report_names_them(): void
    {
        $bookkeeper = $this->bookkeeper();
        // The checked school is not the one the bookkeeper account is based
        // at: the check belongs to the report on screen, division-wide.
        $head = $this->schoolHead($this->school());
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

    public function test_the_check_prints_on_the_excel_export(): void
    {
        $bookkeeper = $this->bookkeeper();
        $head = $this->schoolHead($this->school());
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
        $head = $this->schoolHead($this->school());

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
        $head = $this->schoolHead($this->school());
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
        $head = $this->schoolHead($this->school());
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
