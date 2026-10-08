<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Districts & Schools page — the sidebar destination that shows the
 * District List at full size, and the Administrator-only gate around it.
 */
class DistrictsPageTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
    }

    private function administrator(): User
    {
        return User::factory()->create(['is_superadmin' => false, 'role' => 'administrator']);
    }

    private function staffUser(): User
    {
        return User::factory()->create(['is_superadmin' => false, 'role' => 'user']);
    }

    public function test_an_administrator_sees_the_district_list_on_its_own_page(): void
    {
        $district = District::create(['name' => 'District Page']);
        School::create(['district_id' => $district->id, 'name' => 'Page Elementary School', 'school_id' => '900101']);

        $this->actingAs($this->administrator())
            ->get(route('districts.index'))
            ->assertOk()
            ->assertSee('Districts & Schools')
            ->assertSee('District List')
            ->assertSee('District Page')
            ->assertSee('1 School')
            ->assertSee('Manage Districts & Schools');
    }

    public function test_the_page_is_closed_to_staff(): void
    {
        $this->actingAs($this->staffUser())
            ->get(route('districts.index'))
            ->assertNotFound();
    }

    public function test_the_page_is_closed_to_superadmins(): void
    {
        $superadmin = $this->superadmin();

        $this->actingAs($superadmin)
            ->get(route('districts.index'))
            ->assertNotFound();

        Livewire::actingAs($superadmin)
            ->test('district-list')
            ->assertStatus(404);
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get(route('districts.index'))->assertRedirect(route('login'));
    }

    public function test_the_sidebar_offers_the_page_to_administrators_only(): void
    {
        Livewire::actingAs($this->administrator())
            ->test('sidebar')
            ->assertSee('Districts & Schools')
            ->assertSee(route('districts.index'), false);

        Livewire::actingAs($this->superadmin())
            ->test('sidebar')
            ->assertDontSee('Districts & Schools');

        Livewire::actingAs($this->staffUser())
            ->test('sidebar')
            ->assertDontSee('Districts & Schools');
    }

    public function test_the_district_list_refreshes_itself_on_the_page(): void
    {
        // The list polls rather than waiting for a reload, so a school added
        // elsewhere shows up without the page being re-requested.
        $this->actingAs($this->administrator())
            ->get(route('districts.index'))
            ->assertOk()
            ->assertSee('wire:poll.15s', false);

        $district = District::create(['name' => 'Polled District']);
        School::create(['district_id' => $district->id, 'name' => 'Polled School', 'school_id' => '900102']);

        Livewire::actingAs($this->administrator())
            ->test('district-list')
            ->assertSee('Polled District')
            ->assertSee('1 School');
    }

    public function test_the_management_modal_is_not_rendered_inside_the_polling_list(): void
    {
        $admin = $this->administrator();
        District::create(['name' => 'Division A']);

        // The list polls every 15s, and a Livewire child of a polling parent is
        // re-instantiated by that poll — which reset the manager's state and
        // slammed the modal shut in the middle of adding a school. The page
        // mounts the manager BESIDE the list instead; this pins that down.
        Livewire::actingAs($admin)
            ->test('district-list')
            ->assertDontSee('district-manager', false);

        $this->actingAs($admin)
            ->get(route('districts.index'))
            ->assertOk()
            ->assertSee('district-manager', false);
    }

    public function test_the_district_list_is_closed_to_staff(): void
    {
        // abort() inside a Livewire component surfaces as a failed response
        // rather than a thrown exception, so assert on the status instead.
        Livewire::actingAs($this->staffUser())
            ->test('district-list')
            ->assertStatus(404);
    }
}
