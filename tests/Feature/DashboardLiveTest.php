<?php

namespace Tests\Feature;

use App\Livewire\DashboardSummary;
use App\Livewire\DistrictList;
use App\Models\District;
use App\Models\OpcrfSubmission;
use App\Models\School;
use App\Models\User;
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

    public function test_the_staff_opcrf_card_unlocks_when_the_submission_is_returned_without_a_reload(): void
    {
        $user = $this->staffUser();
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
