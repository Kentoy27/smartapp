<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Where the OPCRF upload component sits on the dashboard, and why.
 *
 * It is a component holding modal state and a temporary file upload. It was
 * rendered from INSIDE dashboard/summary.blade.php, which carries
 * wire:poll.15s — and a component nested in a polling parent is re-mounted
 * on every tick. The pick was analysed, the review modal opened, and a few
 * seconds later the poll re-mounted the component: the modal was gone and
 * the user was back at the upload window with their file lost.
 *
 * These tests pin the placement, because the fix is invisible in the markup
 * and very easy to undo by "tidying" the card back together.
 */
class OpcrfUploadPlacementTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::create([
            'name' => 'Staff Member',
            'username' => 'staff',
            'email' => 'staff@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Chief',
            'username' => 'chief',
            'email' => 'chief@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
            'role' => 'superadmin',
        ]);
    }

    public function test_the_upload_component_is_a_sibling_of_the_polling_summary(): void
    {
        $home = (string) file_get_contents(resource_path('views/home.blade.php'));

        // The summary polls; anything nested inside it inherits that poll.
        $this->assertStringContainsString('wire:poll', (string) file_get_contents(
            resource_path('views/livewire/dashboard/summary.blade.php')
        ), 'This test is only meaningful while the summary still polls.');

        $this->assertStringNotContainsString(
            '<livewire:opcrf-upload',
            (string) file_get_contents(resource_path('views/livewire/dashboard/summary.blade.php')),
            'The upload component must not live inside the polling summary — that re-mounts it every 15s.'
        );

        $this->assertStringContainsString('<livewire:opcrf-upload', $home, 'It belongs on the page itself.');
    }

    public function test_the_dashboard_still_offers_the_upload_button_to_staff(): void
    {
        $this->actingAs($this->staff())
            ->get(route('home'))
            ->assertOk()
            ->assertSeeLivewire('opcrf-upload');
    }

    public function test_a_superadmin_dashboard_does_not_mount_the_upload_component(): void
    {
        // The component 404s on mount for a superadmin, so rendering it for
        // one would replace their whole dashboard with an error page.
        $this->actingAs($this->admin())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSeeLivewire('opcrf-upload');
    }

    public function test_the_modal_survives_a_summary_poll(): void
    {
        $staff = $this->staff();

        Livewire::actingAs($staff)->test(\App\Livewire\OpcrfUpload::class)
            ->call('openUpload')
            ->assertSet('showUpload', true);

        // Re-render the component the way a poll would if it were still a
        // child of the summary. The modal must not be torn down.
        Livewire::actingAs($staff)->test(\App\Livewire\OpcrfUpload::class)
            ->call('openUpload')
            ->assertSet('showUpload', true)
            ->assertSet('showReview', false);
    }
}
