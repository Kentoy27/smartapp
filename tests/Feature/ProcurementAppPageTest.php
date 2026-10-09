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

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('procurement.app.index'), '/').'"\s+class="nav-item active"/',
            $response->getContent()
        );
    }

    public function test_app_is_listed_in_the_files_sidebar_for_authenticated_roles(): void
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
        ];

        foreach ($users as $user) {
            $items = collect(Livewire::actingAs($user)->test(Sidebar::class)->instance()->items());
            $app = $items->firstWhere('label', 'APP');

            $this->assertNotNull($app);
            $this->assertSame('procurement.app.index', $app['route']);
            $this->assertSame('Files', $app['section']);
        }
    }
}
