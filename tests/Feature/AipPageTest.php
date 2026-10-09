<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AipPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('aip.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_open_the_empty_aip_table(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('aip.index'))
            ->assertOk()
            ->assertSee('Annual Implementation Plan (AIP)')
            ->assertSee('AIP Records')
            ->assertSee('<table class="data-table aip-table">', false)
            ->assertSee('No AIP records yet.');

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('aip.index'), '/').'"\s+class="nav-item active"/',
            $response->getContent()
        );
    }

    public function test_aip_is_listed_in_the_files_sidebar_for_staff_and_superadmins(): void
    {
        $staff = User::factory()->create();
        $admin = User::factory()->create([
            'role' => 'superadmin',
            'is_superadmin' => true,
        ]);

        foreach ([$staff, $admin] as $user) {
            $items = collect(Livewire::actingAs($user)->test(Sidebar::class)->instance()->items());
            $aip = $items->firstWhere('label', 'AIP');

            $this->assertNotNull($aip);
            $this->assertSame('aip.index', $aip['route']);
            $this->assertSame('Files', $aip['section']);
        }
    }
}
