<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Models\District;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SidebarWfpNavTest extends TestCase
{
    use RefreshDatabase;

    private function schoolHead(): User
    {
        $district = District::create(['name' => 'Sidebar District']);
        $school = School::create(['district_id' => $district->id, 'name' => 'Sidebar School']);

        return User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => $school->id]);
    }

    public function test_the_wfp_group_holds_both_pages_and_opens_by_default(): void
    {
        Livewire::actingAs($this->schoolHead())
            ->test(Sidebar::class)
            // Both pages are inside the group, one click away on arrival.
            ->assertSee('Work & Financial Plan')
            ->assertSee(route('wfp.index'), false)
            ->assertSee('wire:navigate.hover', false)
            ->assertSee('Monthly Expenditure Matrix')
            ->assertSee(route('wfp.matrices.index'), false)
            // The disclosure reads expanded without being clicked.
            ->assertSee('aria-expanded="true"', false);
    }

    public function test_the_wfp_group_can_be_closed(): void
    {
        Livewire::actingAs($this->schoolHead())
            ->test(Sidebar::class)
            ->call('toggleGroup', 'wfp')
            ->assertSee('aria-expanded="false"', false)
            ->assertDontSee(route('wfp.matrices.index'), false);
    }

    public function test_roles_without_wfp_access_see_no_wfp_group(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $superadmin = User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);

        foreach ([$viewer, $superadmin] as $user) {
            Livewire::actingAs($user)
                ->test(Sidebar::class)
                ->assertDontSee('Monthly Expenditure Matrix')
                ->assertDontSee('Work & Financial Plan');
        }
    }
}
