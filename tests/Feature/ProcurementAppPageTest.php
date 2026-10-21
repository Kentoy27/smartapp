<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProcurementAppPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('procurement.app.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_open_the_app_page(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('procurement.app.index'))
            ->assertOk()
            ->assertSee('Annual Procurement Plan (APP)')
            ->assertSee('APP Records')
            ->assertSee('No APP records are available yet.');

        $this->assertStringNotContainsString(
            'href="'.route('procurement.app.index').'"',
            $response->getContent()
        );
    }

    public function test_app_is_hidden_from_the_sidebar_for_all_roles(): void
    {
        $users = [
            User::factory()->create(),
            User::factory()->create([
                'role' => 'administrator',
                'is_superadmin' => false,
            ]),
            User::factory()->create([
                'role' => 'superadmin',
                'is_superadmin' => true,
            ]),
            User::factory()->create([
                'role' => 'budget',
                'is_superadmin' => false,
            ]),
            User::factory()->create([
                'role' => 'viewer',
                'is_superadmin' => false,
            ]),
        ];

        foreach ($users as $user) {
            $items = collect(Livewire::actingAs($user)->test(Sidebar::class)->instance()->items());

            $this->assertNull($items->firstWhere('label', 'APP'));
        }
    }
}
