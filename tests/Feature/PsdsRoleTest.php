<?php

namespace Tests\Feature;

use App\Livewire\BudgetAllotmentMonitor;
use App\Livewire\BudgetLineItemAllotment;
use App\Livewire\BudgetLineItemChecker;
use App\Livewire\DistrictList;
use App\Livewire\DistrictManager;
use App\Livewire\MemEditor;
use App\Livewire\MemMatrixList;
use App\Livewire\MovUploader;
use App\Livewire\OpcrfAnalytics;
use App\Livewire\OpcrfForm;
use App\Livewire\OpcrfParts;
use App\Livewire\OpcrfReview;
use App\Livewire\OpcrfScheduleManager;
use App\Livewire\OpcrfUpload;
use App\Livewire\Sidebar;
use App\Livewire\UsersTable;
use App\Livewire\WfpGenerateButton;
use App\Livewire\WfpLineItemAllotment;
use App\Livewire\WfpMyUploadsList;
use App\Livewire\WfpPlanEditor;
use App\Livewire\WfpUpload;
use App\Models\District;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The PSDS (Public Schools District Supervisor) role: a strictly view-only
 * monitoring account. An administrator assigns it one or more districts
 * (user_district_assignments), and it may see — and only see — the AIP,
 * OPCRF and WFP submission status of the schools in those districts.
 *
 * It carries no OPCRF, MOV, AIP, WFP, checking or administrative
 * destination of its own: its module is the School Submission Monitoring
 * page alone, and every other page refuses it with the same 404 an
 * uninvited role always gets.
 */
class PsdsRoleTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        return User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
    }

    private function district(string $name = 'PSDS District'): District
    {
        return District::create(['name' => $name.' '.random_int(1000, 9999)]);
    }

    private function school(District $district, string $name = 'PSDS Elementary School'): School
    {
        return School::create([
            'district_id' => $district->id,
            'name' => $name.' '.random_int(1000, 9999),
            'school_id' => (string) random_int(100000, 999999),
        ]);
    }

    /**
     * The account under test: a PSDS assigned to the given districts.
     *
     * @param  array<int, District>  $districts
     * @param  array<string, mixed>  $attributes
     */
    private function psds(array $districts = [], array $attributes = []): User
    {
        $suffix = (string) random_int(1000, 9999);

        $user = User::create(array_merge([
            'name' => 'Paula District Supervisor',
            'position' => 'Public Schools District Supervisor',
            'username' => 'psds'.$suffix,
            'email' => 'psds'.$suffix.'@example.com',
            'password' => Hash::make('password123'),
            'role' => 'psds',
            'is_superadmin' => false,
        ], $attributes));

        if ($districts !== []) {
            $user->syncAssignedDistricts(array_map(fn (District $district): int => $district->id, $districts));
        }

        return $user;
    }

    /* ------------------------------------------------------------ the role */

    public function test_an_administrator_can_create_a_psds_with_assigned_districts(): void
    {
        $admin = $this->administrator();
        $north = $this->district('North District');
        $south = $this->district('South District');

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            // The PSDS is offered as a role, with the district picker
            // appearing the moment it is chosen.
            ->assertSee('PSDS')
            ->set('role', 'psds')
            ->assertSee('Assigned District')
            ->set('name', 'Paula PSDS')
            ->set('username', 'paula')
            ->set('email', 'paula@example.com')
            ->set('password', 'supersecret1')
            ->set('assigned_districts', [$north->id, $south->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        $psds = User::where('username', 'paula')->firstOrFail();

        $this->assertSame('psds', $psds->role);
        $this->assertFalse($psds->is_superadmin);
        $this->assertSame('PSDS', $psds->roleLabel());
        $this->assertSame([$north->id, $south->id], $psds->assignedDistrictIds());
    }

    public function test_a_psds_cannot_be_created_without_a_district(): void
    {
        $admin = $this->administrator();

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'psds')
            ->set('name', 'No District Supervisor')
            ->set('username', 'nodistrict')
            ->set('email', 'nodistrict@example.com')
            ->set('password', 'supersecret1')
            ->call('saveUser')
            ->assertHasErrors(['assigned_districts']);

        $this->assertDatabaseMissing('users', ['username' => 'nodistrict']);
    }

    public function test_the_district_picker_adds_and_removes_only_real_districts(): void
    {
        $admin = $this->administrator();
        $north = $this->district('North District');

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'psds')
            ->call('addAssignedDistrict', $north->id)
            // Picking the same district again is not a second assignment…
            ->call('addAssignedDistrict', $north->id)
            // …and an id that names no district is a request, not a fact.
            ->call('addAssignedDistrict', 999999)
            ->assertSet('assigned_districts', [$north->id])
            ->call('removeAssignedDistrict', $north->id)
            ->assertSet('assigned_districts', []);
    }

    public function test_editing_a_psds_prefills_their_districts_and_keeps_them(): void
    {
        $admin = $this->administrator();
        $north = $this->district('North District');
        $psds = $this->psds([$north]);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openEditModal', $psds->id)
            ->assertSet('role', 'psds')
            // The picker opens on what is stored, so a save that never
            // touched it leaves the assignments exactly as they were.
            ->assertSet('assigned_districts', [$north->id])
            ->set('name', 'Paula P. District Supervisor')
            ->call('saveUser')
            ->assertHasNoErrors();

        $psds->refresh();

        $this->assertSame('Paula P. District Supervisor', $psds->name);
        $this->assertSame('psds', $psds->role);
        $this->assertSame([$north->id], $psds->assignedDistrictIds());
    }

    public function test_the_role_modal_can_assign_the_psds_role(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create(['is_superadmin' => false, 'role' => 'viewer']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openRoleModal', $target->id)
            ->set('selectedRole', 'psds')
            ->call('updateRole')
            ->assertHasNoErrors();

        $this->assertSame('psds', $target->refresh()->role);
        $this->assertFalse($target->is_superadmin);
    }

    public function test_district_assignment_is_scoped_to_the_psds_role_alone(): void
    {
        $admin = $this->administrator();
        $north = $this->district('North District');
        $school = $this->school($north, 'Central School');
        $staff = User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);

        // A crafted district list on a non-PSDS role is ignored, never
        // saved: the field is not part of that form.
        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openEditModal', $staff->id)
            ->set('assigned_districts', [$north->id])
            ->set('name', 'Staff Person')
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertSame([], $staff->refresh()->assignedDistrictIds());
    }

    /* -------------------------------------------- navigation and refusals */

    public function test_a_psds_gets_the_monitoring_navigation_and_nothing_else(): void
    {
        $psds = $this->psds([$this->district('North District')]);

        Livewire::actingAs($psds)
            ->test(Sidebar::class)
            ->assertSee('School Submission Monitoring')
            // No module of its own beyond monitoring: not the staff pages…
            ->assertDontSee('OPCRF')
            ->assertDontSee('MOV')
            ->assertDontSee('AIP')
            ->assertDontSee('Monthly Expenditure')
            ->assertDontSee('Per Line Item Expenditure')
            // …and not the administrative ones.
            ->assertDontSee('Users')
            ->assertDontSee('Districts')
            ->assertDontSee('School Allotments')
            ->assertDontSee('WFP Final Approvals');
    }

    public function test_a_psds_lands_on_the_monitoring_workspace_and_reads_the_monitoring_page(): void
    {
        $psds = $this->psds([$this->district('North District')]);

        // The dashboard is the monitoring workspace…
        $this->actingAs($psds)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('DISTRICT MONITORING WORKSPACE');

        // …and the monitoring page is its own destination.
        $this->actingAs($psds)
            ->get(route('psds.monitoring'))
            ->assertOk()
            ->assertSee('School Submission Monitoring');
    }

    public function test_the_monitoring_page_is_refused_to_every_other_role(): void
    {
        $this->psds([$this->district('North District')]);

        foreach ([
            $this->administrator(),
            User::factory()->create(['role' => 'user', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'bookkeeper', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'budget', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]),
        ] as $outsider) {
            $this->actingAs($outsider)
                ->get(route('psds.monitoring'))
                ->assertNotFound();
        }
    }

    public function test_a_psds_is_refused_every_other_module_route(): void
    {
        $psds = $this->psds([$this->district('North District')]);

        foreach ([
            'opcrf.index',
            'opcrf.review',
            'opcrf.schedule',
            'opcrf.template',
            'mov.index',
            'aip.index',
            'aip.review',
            'wfp.index',
            'wfp.lineitems.index',
            'wfp.matrices.index',
            'budget.allotments.index',
            'budget.lineitems.index',
            'budget.per-line-item.index',
            'users.index',
            'districts.index',
            'account-classifications.index',
            'wfp.submissions.index',
            'wfp.approvals.index',
        ] as $route) {
            $this->actingAs($psds)
                ->get(route($route))
                ->assertNotFound();
        }
    }

    public function test_a_psds_is_refused_the_staff_wfp_and_admin_components(): void
    {
        $psds = $this->psds([$this->district('North District')]);

        $refused = [
            // Staff pages.
            OpcrfUpload::class,
            OpcrfParts::class,
            OpcrfForm::class,
            MovUploader::class,
            // WFP authoring and checking.
            WfpUpload::class,
            WfpMyUploadsList::class,
            MemMatrixList::class,
            MemEditor::class,
            WfpPlanEditor::class,
            WfpGenerateButton::class,
            WfpLineItemAllotment::class,
            BudgetLineItemChecker::class,
            BudgetLineItemAllotment::class,
            BudgetAllotmentMonitor::class,
            // Administrative pages.
            UsersTable::class,
            DistrictManager::class,
            DistrictList::class,
            OpcrfAnalytics::class,
            OpcrfScheduleManager::class,
            OpcrfReview::class,
        ];

        foreach ($refused as $component) {
            $html = Livewire::actingAs($psds)->test($component)->html();

            $this->assertStringContainsString(
                'Page Not Found',
                $html,
                $component.' rendered for a PSDS; the role monitors, it does not work any module.'
            );
        }
    }
}
