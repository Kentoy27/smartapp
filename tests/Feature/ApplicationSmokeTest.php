<?php

namespace Tests\Feature;

use App\Livewire\DashboardSummary;
use App\Livewire\DistrictList;
use App\Livewire\DistrictManager;
use App\Livewire\MovUploader;
use App\Livewire\NotificationsMenu;
use App\Livewire\OpcrfAnalytics;
use App\Livewire\OpcrfForm;
use App\Livewire\OpcrfMovs;
use App\Livewire\OpcrfParts;
use App\Livewire\OpcrfReview;
use App\Livewire\OpcrfScheduleManager;
use App\Livewire\OpcrfUpload;
use App\Livewire\Sidebar;
use App\Livewire\UsersTable;
use App\Models\OpcrfSchedule;
use App\Models\OpcrfSubmission;
use App\Models\User;
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
            '/movs',
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
        ];

        foreach ($staffComponents as $component) {
            Livewire::actingAs($staff)
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
        ];

        foreach ($superComponents as $component) {
            Livewire::actingAs($admin)->test($component)->assertOk();
        }

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