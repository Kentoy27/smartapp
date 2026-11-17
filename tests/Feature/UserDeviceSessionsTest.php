<?php

namespace Tests\Feature;

use App\Livewire\Profile\DeviceSessionsModal;
use App\Livewire\Profile\DownloadAppModal;
use App\Models\User;
use App\Models\UserDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserDeviceSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_has_the_device_sessions_item_in_the_account_menu(): void
    {
        foreach ([
            ['user', false],
            ['viewer', false],
            ['administrator', false],
            ['superadmin', true],
            ['budget', false],
            ['bookkeeper', false],
            ['psds', false],
        ] as [$role, $isSuperadmin]) {
            $user = User::factory()->create([
                'role' => $role,
                'is_superadmin' => $isSuperadmin,
            ]);

            $this->actingAs($user)
                ->get(route('home'))
                ->assertOk()
                ->assertSee('open-device-sessions-modal')
                ->assertSeeLivewire(DeviceSessionsModal::class)
                ->assertSee('Download App')
                ->assertSee('open-download-app-modal')
                ->assertSeeLivewire(DownloadAppModal::class);
        }
    }

    public function test_download_app_modal_shows_install_steps_for_iphone_and_ipad(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]))
            ->test(DownloadAppModal::class)
            ->call('openModal')
            ->assertSee('IPHONE &amp; IPAD')
            ->assertSee('Install SmartApp on iPhone or iPad')
            ->assertSee('iOS cannot install Android APK files')
            ->assertSee('Add to Home Screen')
            ->assertSee('Tap Add');
    }

    public function test_device_modal_lists_all_active_sessions_for_only_the_signed_in_user(): void
    {
        $user = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $otherUser = User::factory()->create(['role' => 'user', 'is_superadmin' => false]);

        for ($index = 1; $index <= 15; $index++) {
            UserDeviceSession::recordActivity(
                $user,
                'viewer-device-session-'.$index,
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36',
            );
        }

        UserDeviceSession::recordActivity($otherUser, 'other-account-session', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1');

        Livewire::actingAs($user)
            ->test(DeviceSessionsModal::class)
            ->call('openModal')
            ->assertSet('showModal', true)
            ->assertSee('15')
            ->assertSee('active sessions')
            ->assertSee('Computer')
            ->assertSee('Chrome')
            ->assertSee('Windows');

        $this->assertSame(
            15,
            UserDeviceSession::query()->where('user_id', $user->id)->count(),
        );
    }

    public function test_authenticated_requests_record_a_device_and_logout_marks_it_inactive(): void
    {
        $user = User::factory()->create(['role' => 'user', 'is_superadmin' => false]);

        $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1')
            ->get(route('home'))
            ->assertOk();

        $deviceSession = UserDeviceSession::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame('Phone', $deviceSession->device_type);
        $this->assertSame('Safari', $deviceSession->browser);
        $this->assertNull($deviceSession->logged_out_at);

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertNotNull($deviceSession->fresh()->logged_out_at);
    }
}
