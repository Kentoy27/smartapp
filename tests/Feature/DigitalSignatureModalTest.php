<?php

namespace Tests\Feature;

use App\Livewire\Profile\DigitalSignatureModal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Digital Signature" dialog behind the account menu.
 *
 * The dialog is only a shell: it hosts the one signature component, so the
 * upload flow is not re-tested here (DigitalSignatureTest owns that). What
 * these tests pin is the shell itself — that a guest cannot open it, that it
 * opens and closes on the account menu's event, and that the real signature
 * component is mounted inside it rather than a look-alike.
 */
class DigitalSignatureModalTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $username = 'sh_user'): User
    {
        return User::factory()->create([
            'name' => 'School Head',
            'username' => $username,
            'role' => 'user',
            'is_superadmin' => false,
        ]);
    }

    private function superadmin(string $username = 'super_admin'): User
    {
        return User::factory()->create([
            'name' => 'Super Admin',
            'username' => $username,
            'role' => 'superadmin',
            'is_superadmin' => true,
        ]);
    }

    public function test_guests_cannot_use_the_dialog(): void
    {
        Livewire::test(DigitalSignatureModal::class)->assertStatus(404);
    }

    public function test_the_dialog_stays_closed_until_the_account_menu_opens_it(): void
    {
        Livewire::actingAs($this->staff())->test(DigitalSignatureModal::class)
            ->assertSet('showModal', false)
            ->assertDontSee('Digital Signature')
            ->call('openModal')
            ->assertSet('showModal', true)
            ->assertSee('Digital Signature');
    }

    public function test_the_dialog_closes_again(): void
    {
        Livewire::actingAs($this->staff())->test(DigitalSignatureModal::class)
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('closeModal')
            ->assertSet('showModal', false)
            ->assertDontSee('Digital Signature');
    }

    public function test_the_dialog_mounts_the_real_signature_component(): void
    {
        foreach ([$this->staff(), $this->superadmin()] as $account) {
            Livewire::actingAs($account)->test(DigitalSignatureModal::class)
                ->call('openModal')
                ->assertSet('showModal', true)
                // The one signature component, not a second copy of the flow.
                ->assertSeeLivewire('profile.digital-signature')
                // Its contents come along: heading, status and the upload action.
                ->assertSee('Your signature is stamped onto the documents')
                ->assertSee('Not uploaded')
                ->assertSee('Upload Digital Signature');
        }
    }

    public function test_the_dialog_links_out_to_the_full_profile_page(): void
    {
        Livewire::actingAs($this->staff())->test(DigitalSignatureModal::class)
            ->call('openModal')
            // The page is still the bookmarkable home of the account
            // summary, so the quick dialog points at it.
            ->assertSee(route('profile.index'), false)
            ->assertSee('Open the full profile page')
            ->call('closeModal')
            ->assertDontSee('Open the full profile page');
    }

    public function test_the_account_menu_carries_the_event_that_opens_it(): void
    {
        $response = $this->actingAs($this->staff())->get(route('home'));

        $response->assertOk()
            ->assertSee('data-modal-event="open-signature-modal"', false)
            ->assertSee('data-modal-event="open-profile-modal"', false);
    }

    public function test_the_signature_entry_no_longer_navigates_away(): void
    {
        $response = $this->actingAs($this->staff())->get(route('home'));

        // The menu item is a dialog trigger, so it is a button, not the link
        // to the profile page it used to be.
        $response->assertSee('data-modal-event="open-signature-modal"', false)
            ->assertDontSee('href="'.route('profile.index').'" class="user-dropdown-link"', false);
    }
}
