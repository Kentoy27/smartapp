<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SipPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_heads_and_viewers_can_open_the_sip_landing_page(): void
    {
        foreach (['user', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_superadmin' => false]);

            $response = $this->actingAs($user)
                ->get(route('sip.index'))
                ->assertOk()
                ->assertSee('School Improvement Plan (SIP)')
                ->assertSee('The SIP workspace is not available yet.');

            $this->assertMatchesRegularExpression(
                '/href="'.preg_quote(route('sip.index'), '/').'"\s+class="nav-item active"/',
                $response->getContent()
            );
        }
    }

    public function test_sip_sidebar_item_is_visible_to_school_heads_and_viewers_only(): void
    {
        foreach (['user', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_superadmin' => false]);
            $items = collect(Livewire::actingAs($user)->test(Sidebar::class)->instance()->items());
            $sip = $items->firstWhere('label', 'SIP');

            $this->assertNotNull($sip);
            $this->assertSame('sip.index', $sip['route']);
            $this->assertSame('Files', $sip['section']);
        }
    }

    public function test_sip_is_not_available_to_guests_or_administrative_and_finance_roles(): void
    {
        $this->get(route('sip.index'))->assertRedirect(route('login'));

        foreach ([
            User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]),
            User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'budget', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'bookkeeper', 'is_superadmin' => false]),
        ] as $user) {
            $this->actingAs($user)->get(route('sip.index'))->assertNotFound();

            $items = collect(Livewire::actingAs($user)->test(Sidebar::class)->instance()->items());
            $this->assertNull($items->firstWhere('label', 'SIP'));
        }
    }
}
