<?php

namespace Tests\Feature;

use App\Livewire\AccountClassificationsTable;
use App\Livewire\BudgetAllotmentMonitor;
use App\Livewire\BudgetLineItemAllotment;
use App\Livewire\BudgetLineItemChecker;
use App\Livewire\DashboardSummary;
use App\Livewire\DistrictList;
use App\Livewire\DistrictManager;
use App\Livewire\MemEditor;
use App\Livewire\MemMatrixList;
use App\Livewire\MovUploader;
use App\Livewire\NotificationsMenu;
use App\Livewire\OpcrfAnalytics;
use App\Livewire\OpcrfForm;
use App\Livewire\OpcrfMovs;
use App\Livewire\OpcrfParts;
use App\Livewire\OpcrfReview;
use App\Livewire\OpcrfScheduleManager;
use App\Livewire\OpcrfUpload;
use App\Livewire\SchoolAllotmentManager;
use App\Livewire\Sidebar;
use App\Livewire\UsersTable;
use App\Livewire\WfpGenerateButton;
use App\Livewire\WfpLineItemAllotment;
use App\Livewire\WfpLineTemplatesTable;
use App\Livewire\WfpLookupTable;
use App\Livewire\WfpManager;
use App\Livewire\WfpMyUploadsList;
use App\Livewire\WfpPlanEditor;
use App\Livewire\WfpUpload;
use App\Models\District;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\OpcrfSchedule;
use App\Models\OpcrfSubmission;
use App\Models\School;
use App\Models\User;
use App\Models\WfpPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The whole application, exercised rather than asserted about in pieces.
 *
 * The behavioural tests each know what they expect to find. This one knows
 * nothing: it walks every application route as a guest, as a staff member
 * and as a superadmin, and renders every Livewire component in isolation,
 * asserting only that nothing blows up. That catches the bugs a
 * feature-by-feature suite never reaches — a component that references a
 * partial that no longer exists, a route whose controller calls a method
 * that was renamed, a view that throws only for one role.
 *
 * A 403 or 404 is a PASS here — refusing a request is a decision, not a
 * fault. Only a 500, an uncaught exception, or a route that errors is a
 * failure.
 */
class ApplicationSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::create([
            'name' => 'Root Admin',
            'username' => 'rootadmin',
            'email' => 'root@example.com',
            'password' => Hash::make('secret123'),
            'is_superadmin' => true,
        ]);
    }

    private function administrator(): User
    {
        return User::create([
            'name' => 'System Administrator',
            'username' => 'systemadmin',
            'email' => 'systemadmin@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'administrator',
            'is_superadmin' => false,
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'Staff Person',
            'username' => 'staffy',
            'email' => 'staff@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'user',
            'is_superadmin' => false,
        ]);
    }

    private function bookkeeper(): User
    {
        return User::create([
            'name' => 'Book Keeper',
            'username' => 'booky',
            'email' => 'booky@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'bookkeeper',
            'is_superadmin' => false,
        ]);
    }

    /** Every route a signed-in person can reach, with a real id substituted. */
    private function routes(OpcrfSubmission $submission): array
    {
        return [
            '/',
            '/login',
            '/home',
            '/opcrf',
            '/opcrf-review',
            '/opcrf-schedule',
            '/users',
            '/districts',
            '/admin/account-classifications',
            '/admin/account-classifications/template',
            '/api/admin/account-classifications',
            '/movs',
            '/wfp',
            '/wfp/line-items',
            '/wfp/line-items/export',
            '/budget/allotments',
            '/budget/line-items',
            '/budget/per-line-item',
            '/wfp/matrices',
            '/wfp/matrices/template',
            '/wfp/matrices/1',
            '/wfp/plans/1',
            '/wfp/plans/1/export',
            '/admin/wfp-setup',
            '/admin/wfp-setup/lookups/funding-sources',
            '/admin/wfp-setup/lookups/funding-sources/template',
            '/admin/wfp-setup/lookups/funding-sources/export',
            '/admin/wfp-setup/templates',
            '/admin/wfp-setup/templates/template',
            '/admin/wfp-setup/templates/export',
            '/download/opcrf-template',
            '/opcrf/part/1',
            '/opcrf/part/2',
            '/opcrf/part/3',
            '/opcrf/part/4',
            '/opcrf-submissions/'.$submission->id.'/download',
        ];
    }

    public function test_no_route_errors_for_any_role(): void
    {
        Storage::fake('local');
        $admin = $this->superadmin();
        $staff = $this->staff();
        $bookkeeper = $this->bookkeeper();

        $submission = OpcrfSubmission::create([
            'user_id' => $staff->id,
            'reviewer_id' => $admin->id,
            'employee_name' => 'Staff Person',
            'position' => 'Teacher I',
            'review_period' => '2026',
            'division_office' => 'Office',
            'objectives' => 'o',
            'accomplishments' => 'a',
            'self_rating' => 4.0,
            'submitted_at' => now(),
        ]);

        OpcrfSchedule::create([
            'opcrf_year' => (int) now()->year,
            'part_number' => OpcrfSchedule::PART_ONE,
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->addDay(),
            'is_enabled' => true,
        ]);

        $failures = [];

        foreach ($this->routes($submission) as $uri) {
            foreach ([
                'guest' => null,
                'staff' => $staff,
                'bookkeeper' => $bookkeeper,
                'superadmin' => $admin,
            ] as $role => $user) {
                $response = $user === null
                    ? $this->get($uri)
                    : $this->actingAs($user)->get($uri);

                $status = $response->getStatusCode();

                // 403/404/302 are decisions, not faults. 5xx and thrown
                // exceptions are faults.
                if ($status >= 500) {
                    $failures[] = "{$uri} as {$role} → {$status}";
                }
            }
        }

        $this->assertSame([], $failures, "Routes errored:\n".implode("\n", $failures));
    }

    /**
     * Every Livewire component, rendered on its own. A component whose view
     * references a deleted partial, or reads a property that no longer
     * exists, only fails when something renders it — which the per-feature
     * tests only do for the flows they happen to cover.
     */
    public function test_every_livewire_component_renders_for_its_intended_role(): void
    {
        Storage::fake('local');
        $admin = $this->superadmin();
        $staff = $this->staff();

        $submission = OpcrfSubmission::create([
            'user_id' => $staff->id,
            'reviewer_id' => $admin->id,
            'employee_name' => 'Staff Person',
            'position' => 'Teacher I',
            'review_period' => '2026',
            'division_office' => 'Office',
            'objectives' => 'o',
            'accomplishments' => 'a',
            'self_rating' => 4.0,
            'submitted_at' => now(),
        ]);

        OpcrfSchedule::create([
            'opcrf_year' => (int) now()->year,
            'part_number' => OpcrfSchedule::PART_ONE,
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->addDay(),
            'is_enabled' => true,
        ]);

        // Staff-owned components: must render for a staff member.
        $staffComponents = [
            OpcrfUpload::class,
            OpcrfMovs::class,
            Sidebar::class,
            DashboardSummary::class,
            NotificationsMenu::class,
            MovUploader::class,
            OpcrfForm::class,
            OpcrfParts::class,
            MemMatrixList::class,
            WfpGenerateButton::class,
            WfpLineItemAllotment::class,
            WfpMyUploadsList::class,
        ];

        foreach ($staffComponents as $component) {
            Livewire::actingAs($staff)
                ->test($component)
                ->assertOk();
        }

        // The MEM editor and the WFP plan editor take a bound model, so their
        // views are only exercised when one is passed — give them a real,
        // SH-owned matrix and plan.
        $district = District::create(['name' => 'Smoke District']);
        $school = School::create(['district_id' => $district->id, 'name' => 'Smoke School']);
        $schoolHead = User::create([
            'name' => 'School Head',
            'username' => 'schoolhead',
            'email' => 'sh@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);

        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $school->id,
            'year' => (int) now()->year,
            'month' => 1,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
        ]);

        $plan = WfpPlan::create([
            'school_id' => $school->id,
            'year' => (int) now()->year,
            'status' => WfpPlan::STATUS_GENERATED,
        ]);

        Livewire::actingAs($schoolHead)->test(MemEditor::class, ['matrix' => $matrix])->assertOk();
        Livewire::actingAs($schoolHead)->test(WfpPlanEditor::class, ['plan' => $plan])->assertOk();

        // Bookkeeper-owned components: the Bookkeeper works the Budget
        // office-style checking pages (its write actions refuse it — covered
        // in BookkeeperRoleTest).
        $bookkeeper = User::create([
            'name' => 'Book Keeper',
            'username' => 'booky',
            'email' => 'booky@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'bookkeeper',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);

        $bookkeeperComponents = [
            Sidebar::class,
            DashboardSummary::class,
            OpcrfMovs::class,
            BudgetLineItemChecker::class,
            BudgetLineItemAllotment::class,
        ];

        foreach ($bookkeeperComponents as $component) {
            Livewire::actingAs($bookkeeper)
                ->test($component)
                ->assertOk();
        }

        // Administrator-owned components, including opening the review modal
        // so its biggest view actually renders.
        $admin = $this->administrator();
        $superComponents = [
            UsersTable::class,
            DistrictManager::class,
            DistrictList::class,
            OpcrfAnalytics::class,
            OpcrfScheduleManager::class,
            AccountClassificationsTable::class,
            WfpLineTemplatesTable::class,
        ];

        foreach ($superComponents as $component) {
            Livewire::actingAs($admin)
                ->test($component)
                ->assertOk();
        }

        // The lookup table takes a category parameter; exercise each one.
        foreach (['funding-sources', 'units', 'resource-kinds', 'org-classifications'] as $category) {
            Livewire::actingAs($admin)
                ->test(WfpLookupTable::class, ['category' => $category])
                ->assertOk();
        }

        // The Budget office's own components are shared with the
        // administrative side, so they render for both.
        Livewire::actingAs($admin)->test(SchoolAllotmentManager::class)->assertOk();
        Livewire::actingAs($admin)->test(BudgetAllotmentMonitor::class)->assertOk();

        $budget = User::create([
            'name' => 'Budget Officer',
            'username' => 'budgetofficer',
            'email' => 'budget@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'budget',
            'is_superadmin' => false,
        ]);

        Livewire::actingAs($budget)->test(SchoolAllotmentManager::class)->assertOk();
        Livewire::actingAs($budget)->test(BudgetAllotmentMonitor::class)->assertOk();
        Livewire::actingAs($budget)->test(BudgetLineItemChecker::class)->assertOk();

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSet('showReview', true)
            ->assertOk();
    }

    /**
     * A staff member must never reach a superadmin-only component, and a
     * guest must never reach anything at all.
     *
     * Note how this is asserted. Livewire's test harness does NOT throw for
     * a guard in mount(): it renders the framework's error page as the
     * component's output. Actions behave differently and do throw. Asserting
     * "an exception was raised" here would silently pass a component that
     * happily rendered — so this asserts on what actually came back.
     */
    public function test_role_boundaries_hold_for_every_superadmin_component(): void
    {
        Storage::fake('local');
        $this->superadmin();
        $staff = $this->staff();

        $superOnly = [
            UsersTable::class,
            DistrictManager::class,
            DistrictList::class,
            OpcrfAnalytics::class,
            OpcrfScheduleManager::class,
            OpcrfReview::class,
            AccountClassificationsTable::class,
            BudgetAllotmentMonitor::class,
            BudgetLineItemChecker::class,
        ];

        foreach ($superOnly as $component) {
            $html = Livewire::actingAs($staff)->test($component)->html();

            $this->assertStringContainsString(
                'Page Not Found',
                $html,
                $component.' rendered for a staff member; it is superadmin-only.'
            );
        }
    }

    /**
     * Every model's fillable list and casts must match the real schema.
     *
     * Drift here is silent until something writes the attribute: a cast on
     * a column that does not exist reads as null forever, and assigning it
     * builds SQL against a missing column. That is exactly how a dead
     * `email_verified_at` cast survived on this model — nothing used it,
     * so nothing complained.
     */
    public function test_no_model_declares_a_column_the_schema_does_not_have(): void
    {
        $problems = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $model = 'App\\Models\\'.basename($file, '.php');
            $instance = new $model;
            $table = $instance->getTable();

            $this->assertTrue(
                Schema::hasTable($table),
                "{$model} points at table {$table}, which does not exist."
            );

            $columns = Schema::getColumnListing($table);
            $key = $instance->getKeyName();

            foreach (array_keys($instance->getCasts()) as $attribute) {
                if ($attribute !== $key && ! in_array($attribute, $columns, true)) {
                    $problems[] = class_basename($model).": cast on missing column {$attribute}";
                }
            }

            foreach ($instance->getFillable() as $attribute) {
                if (! in_array($attribute, $columns, true)) {
                    $problems[] = class_basename($model).": fillable entry with no column {$attribute}";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * The Bookkeeper's interface is the division-wide checking pages alone:
     * the staff pages and the School Head's WFP workspace (components and
     * model-bound editors) refuse it with the same 404 they give any other
     * uninvited role.
     */
    public function test_a_bookkeeper_is_refused_the_staff_and_wfp_authoring_components(): void
    {
        Storage::fake('local');
        $bookkeeper = $this->bookkeeper();

        $refused = [
            OpcrfUpload::class,
            OpcrfParts::class,
            OpcrfForm::class,
            WfpUpload::class,
            WfpMyUploadsList::class,
            MemMatrixList::class,
            MemEditor::class,
            WfpPlanEditor::class,
            WfpManager::class,
            WfpGenerateButton::class,
            WfpLineItemAllotment::class,
        ];

        foreach ($refused as $component) {
            $html = Livewire::actingAs($bookkeeper)->test($component)->html();

            $this->assertStringContainsString(
                'Page Not Found',
                $html,
                $component.' rendered for a Bookkeeper; its module is the WFP alone.'
            );
        }
    }

    /**
     * The same boundaries for signed-out visitors.
     */
    public function test_guests_are_refused_by_every_livewire_component(): void
    {
        Storage::fake('local');
        $this->superadmin();
        $this->staff();

        $components = [
            OpcrfUpload::class,
            OpcrfMovs::class,
            OpcrfParts::class,
            OpcrfForm::class,
            MovUploader::class,
            DashboardSummary::class,
            NotificationsMenu::class,
            Sidebar::class,
            UsersTable::class,
            DistrictManager::class,
            DistrictList::class,
            OpcrfAnalytics::class,
            OpcrfScheduleManager::class,
            OpcrfReview::class,
            AccountClassificationsTable::class,
            MemMatrixList::class,
            MemEditor::class,
            WfpPlanEditor::class,
            WfpGenerateButton::class,
            WfpMyUploadsList::class,
            SchoolAllotmentManager::class,
            BudgetAllotmentMonitor::class,
            BudgetLineItemChecker::class,
        ];

        foreach ($components as $component) {
            $html = Livewire::test($component)->html();

            $this->assertStringContainsString(
                'Page Not Found',
                $html,
                $component.' rendered for a signed-out visitor.'
            );
        }
    }
}
