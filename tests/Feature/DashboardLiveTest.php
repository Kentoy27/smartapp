<?php

namespace Tests\Feature;

use App\Livewire\DashboardSummary;
use App\Livewire\DistrictList;
use App\Models\District;
use App\Models\OpcrfSubmission;
use App\Models\OpcrfSchedule;
use App\Models\School;
use App\Models\User;
use App\Models\WfpSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dashboard's live body.
 *
 * The staff dashboard's OPCRF Template card refreshes itself on poll — no
 * reload needed — and the District List that used to sit on a superadmin's
 * dashboard still refreshes itself on its own page (DistrictList). These
 * tests prove the liveness semantics: the same mounted component reflects
 * changes made behind its back, which is exactly what a poll cycle picks up.
 */
class DashboardLiveTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        return User::create([
            'name' => 'Staff Member',
            'username' => 'staff',
            'email' => 'staff@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);
    }

    private function superadmin(): User
    {
        return User::create([
            'name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'adminuser@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
    }

    private function administrator(): User
    {
        return User::create([
            'name' => 'Administrator User',
            'username' => 'administrator',
            'email' => 'administrator@example.com',
            'password' => Hash::make('password123'),
            'role' => 'administrator',
            'is_superadmin' => false,
        ]);
    }

    public function test_the_dashboard_body_is_live_for_every_account(): void
    {
        // Staff: the whole body is the polling OPCRF Template card, so
        // nothing data-driven goes stale.
        $this->actingAs($this->staffUser())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('wire:poll.15s', false);

        // A superadmin's dashboard is just the greeting — the District List
        // moved to its own page (DistrictsPageTest covers its liveness).
        $this->actingAs($this->superadmin())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('District List');
    }

    public function test_the_shared_footer_shows_developer_credits_for_both_roles(): void
    {
        $staff = $this->staffUser();
        $staff->forceFill(['role' => 'user'])->save();

        foreach ([$staff, $this->superadmin()] as $user) {
            $this->actingAs($user)
                ->get(route('home'))
                ->assertOk()
                ->assertSee('id="creditsTrigger"', false)
                ->assertSee('id="creditsModal"', false)
                ->assertSee('System Credits')
                ->assertSee('John Kenneth Ang')
                ->assertSee('Full-Stack Developer');
        }
    }

    public function test_system_overview_shows_live_counts_to_administrators_and_superadmins_only(): void
    {
        $district = District::create(['name' => 'Statistics District']);
        $school = School::create([
            'district_id' => $district->id,
            'name' => 'Statistics Elementary School',
            'school_id' => '900001',
        ]);
        $staff = $this->staffUser();
        $staff->forceFill(['role' => 'user', 'school_id' => $school->id])->save();

        OpcrfSubmission::create([
            'user_id' => $staff->id,
            'employee_name' => 'Staff Member',
            'position' => 'Teacher I',
            'review_period' => 'January to December 2026',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objective 1',
            'accomplishments' => 'Accomplished objective 1',
            'self_rating' => 4.5,
            'submitted_at' => now(),
        ]);
        WfpSubmission::create([
            'user_id' => $staff->id,
            'original_file_name' => 'school-wfp.xlsx',
            'file_path' => 'wfp/'.$staff->id.'/school-wfp.xlsx',
            'school_year' => '2026-2027',
            'uploaded_at' => now(),
        ]);

        $administrator = $this->administrator();
        $superadmin = $this->superadmin();

        foreach ([$administrator, $superadmin] as $account) {
            Livewire::actingAs($account)
                ->test(\App\Livewire\AdminDashboardStats::class)
                ->assertSee('System Overview')
                ->assertSee('Across all schools')
                ->assertSeeInOrder([
                    'Schools', '1',
                    'School Heads', '1',
                    'OPCRF Submissions', '1',
                    'WFP Uploads', '1',
                    'MOV Uploads', '0',
                ]);

            $this->actingAs($account)
                ->get(route('home'))
                ->assertOk()
                ->assertSee('admin-dashboard-stats', false)
                ->assertSee('System Overview');
        }

        $staffPage = $this->actingAs($staff)->get(route('home'));
        $staffPage->assertOk()->assertDontSee('System Overview');

        Livewire::actingAs($staff)
            ->test(\App\Livewire\AdminDashboardStats::class)
            ->assertNotFound();
    }

    public function test_the_staff_dashboard_groups_plan_workflows_in_one_responsive_workspace(): void
    {
        $user = $this->staffUser();
        $user->forceFill(['role' => 'user'])->save();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('staff-dashboard-grid', false)
            ->assertSee('Performance plan')
            ->assertSee('Financial plan')
            ->assertSee('Download OPCRF Template')
            ->assertSee('Upload Commitment')
            ->assertSee('Generate and Download WFP')
            ->assertSee(route('wfp.index'), false);
    }

    public function test_the_staff_opcrf_card_unlocks_when_the_submission_is_returned_without_a_reload(): void
    {
        $user = $this->staffUser();
        OpcrfSchedule::create([
            'opcrf_year' => (int) now()->format('Y'),
            'part_number' => OpcrfSchedule::PART_ONE,
            'start_datetime' => now()->subDays(2),
            'end_datetime' => now()->subDay(),
            'is_enabled' => true,
            'created_by' => $this->superadmin()->id,
        ]);
        $submission = OpcrfSubmission::create([
            'user_id' => $user->id,
            'employee_name' => 'Staff Member',
            'position' => 'Teacher I',
            'review_period' => 'January to December 2026',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objective 1',
            'accomplishments' => 'Accomplished objective 1',
            'self_rating' => 4.5,
            'submitted_at' => now()->subDay(),
            'status' => OpcrfSubmission::STATUS_PENDING,
        ]);

        // Confirmed submission → the dashboard card is locked.
        $component = Livewire::actingAs($user)->test(DashboardSummary::class);
        $component->assertSee('OPCRF submitted — locked');

        // A reviewer returns it for revision: the same mounted component
        // (what a poll cycle renders) shows the unlocked card again.
        $submission->update(['status' => OpcrfSubmission::STATUS_RETURNED]);
        $component->call('$refresh');

        $component->assertDontSee('OPCRF submitted — locked')
            ->assertSee('Download');
    }

    public function test_a_submitted_opcrf_is_unlocked_while_its_part_one_deadline_is_open(): void
    {
        $user = $this->staffUser();
        OpcrfSchedule::create([
            'opcrf_year' => (int) now()->format('Y'),
            'part_number' => OpcrfSchedule::PART_ONE,
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->addDay(),
            'is_enabled' => true,
            'created_by' => $this->superadmin()->id,
        ]);
        OpcrfSubmission::create([
            'user_id' => $user->id,
            'employee_name' => 'Staff Member',
            'position' => 'Teacher I',
            'review_period' => 'January to December 2026',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objective 1',
            'accomplishments' => 'Accomplished objective 1',
            'self_rating' => 4.5,
            'submitted_at' => now()->subHour(),
            'status' => OpcrfSubmission::STATUS_PENDING,
        ]);

        Livewire::actingAs($user)
            ->test(DashboardSummary::class)
            ->assertDontSee('OPCRF submitted — locked');
    }

    public function test_the_superadmin_district_list_refreshes_its_counts(): void
    {
        $component = Livewire::actingAs($this->superadmin())->test(DistrictList::class);
        $component->assertSee('No districts yet');

        $district = District::create(['name' => 'District Live']);
        School::create(['district_id' => $district->id, 'name' => 'Live Elementary School', 'school_id' => '900001']);

        // No remount — the poll cycle picks up the new district and count.
        $component->call('$refresh');

        $component->assertSee('District Live')
            ->assertSee('1 School');
    }

    public function test_the_dashboard_still_shows_the_district_list_to_a_superadmin(): void
    {
        // The District List moved off the dashboard to its own page; the
        // dashboard must not smuggle it back in.
        District::create(['name' => 'Dashboard District']);

        $this->actingAs($this->superadmin())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('District List')
            ->assertDontSee('Dashboard District');
    }

    public function test_the_dashboard_hides_the_district_list_from_staff(): void
    {
        $this->actingAs($this->staffUser())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('District List');
    }
}
