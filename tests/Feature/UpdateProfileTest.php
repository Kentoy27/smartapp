<?php

namespace Tests\Feature;

use App\Livewire\Profile\UpdateProfile;
use App\Models\District;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Update My Profile" — the modal behind the account menu.
 *
 * The tests below walk both roles because the same modal serves a user and a
 * superadmin, and they pin the two boundaries that matter: an account can only
 * ever write to its OWN row, and it can never touch the role, school or
 * superadmin rights an administrator assigns. The password is optional — a
 * blank field must leave the existing login working — so its tests cover the
 * wrong current password, the too-short new one and the "changed nothing" case.
 */
class UpdateProfileTest extends TestCase
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

    /** A school to assign, so "cannot move myself" is a real assertion. */
    private function school(): School
    {
        $district = District::create(['name' => 'District I']);

        return School::create([
            'district_id' => $district->id,
            'name' => 'Alpha Elementary School',
            'school_id' => '100001',
        ]);
    }

    // ---------------------------------------------------------------- access

    public function test_guests_cannot_use_the_modal(): void
    {
        Livewire::test(UpdateProfile::class)->assertStatus(404);
    }

    public function test_the_modal_stays_closed_until_the_account_menu_opens_it(): void
    {
        $account = $this->staff();

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->assertSet('showModal', false)
            ->assertDontSee('Update My Profile')
            ->call('openModal')
            ->assertSet('showModal', true)
            ->assertSee('Update My Profile')
            ->assertSee('Save changes');
    }

    public function test_opening_the_modal_fills_it_from_the_signed_in_account(): void
    {
        foreach ([$this->staff(), $this->superadmin()] as $account) {
            $account->forceFill(['position' => 'School Head'])->save();

            Livewire::actingAs($account)->test(UpdateProfile::class)
                ->call('openModal')
                ->assertSet('name', $account->name)
                ->assertSet('username', $account->username)
                ->assertSet('email', $account->email)
                ->assertSet('position', 'School Head')
                // Nothing about a password is ever pre-filled.
                ->assertSet('current_password', '')
                ->assertSet('password', '')
                ->assertSet('password_confirmation', '');
        }
    }

    // ---------------------------------------------------------------- saving

    public function test_saving_updates_the_accounts_own_details(): void
    {
        $account = $this->staff('old_login');

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('name', 'Juan Dela Cruz')
            ->set('position', 'Principal II')
            ->set('username', 'juan_dc')
            ->set('email', 'juan.dc@deped.gov.ph')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Your profile has been updated.');

        $account->refresh();

        $this->assertSame('Juan Dela Cruz', $account->name);
        $this->assertSame('Principal II', $account->position);
        $this->assertSame('juan_dc', $account->username);
        $this->assertSame('juan.dc@deped.gov.ph', $account->email);
    }

    public function test_the_name_is_required_and_an_empty_position_is_allowed(): void
    {
        $account = $this->staff();

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('position', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($account->refresh()->position);
    }

    public function test_a_username_or_email_another_account_holds_is_refused(): void
    {
        $taken = $this->staff('taken_login');
        $account = $this->staff('my_login');

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('username', 'taken_login')
            ->call('save')
            ->assertHasErrors(['username' => 'unique']);

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('email', $taken->email)
            ->call('save')
            ->assertHasErrors(['email' => 'unique']);

        // Refused means refused: the account still signs in as it did.
        $this->assertSame('my_login', $account->refresh()->username);
    }

    public function test_keeping_your_own_username_and_email_is_not_a_clash(): void
    {
        $account = $this->staff('my_login');

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('username', 'my_login')
            ->set('email', $account->email)
            ->call('save')
            ->assertHasNoErrors();
    }

    // -------------------------------------------------------------- password

    public function test_a_new_password_must_be_confirmed_and_long_enough(): void
    {
        $account = $this->staff();

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('current_password', 'password')
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('save')
            ->assertHasErrors(['password' => 'min']);

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('current_password', 'password')
            ->set('password', 'new-password-1')
            ->set('password_confirmation', 'something-else')
            ->call('save')
            ->assertHasErrors(['password' => 'confirmed']);

        $this->assertTrue(Hash::check('password', $account->refresh()->password));
    }

    public function test_the_password_needs_the_current_one_to_change(): void
    {
        $account = $this->staff();

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('password', 'new-password-1')
            ->set('password_confirmation', 'new-password-1')
            ->call('save')
            ->assertHasErrors(['current_password' => 'required']);

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('current_password', 'not-my-password')
            ->set('password', 'new-password-1')
            ->set('password_confirmation', 'new-password-1')
            ->call('save')
            ->assertHasErrors(['current_password' => 'current_password']);

        // Neither attempt moved the stored password.
        $this->assertTrue(Hash::check('password', $account->refresh()->password));
    }

    public function test_the_password_changes_when_the_current_one_is_given(): void
    {
        $account = $this->staff();

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('current_password', 'password')
            ->set('password', 'new-password-1')
            ->set('password_confirmation', 'new-password-1')
            ->call('save')
            ->assertHasNoErrors()
            // The typed password is not kept in the form once it is saved.
            ->assertSet('current_password', '')
            ->assertSet('password', '')
            ->assertSet('password_confirmation', '');

        $this->assertTrue(Hash::check('new-password-1', $account->refresh()->password));
    }

    public function test_a_refused_save_clears_the_previous_confirmation(): void
    {
        $taken = $this->staff('taken_login');
        $account = $this->staff('my_login');

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('name', 'Saved Once')
            ->call('save')
            ->assertSet('successMessage', 'Your profile has been updated.')
            // A second attempt that is refused must not sit under the success
            // banner of the first.
            ->set('username', $taken->username)
            ->call('save')
            ->assertHasErrors(['username' => 'unique'])
            ->assertSet('successMessage', null);
    }

    public function test_a_blank_password_leaves_the_password_alone(): void
    {
        $account = $this->staff();

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('name', 'Renamed Only')
            ->call('save')
            ->assertHasNoErrors();

        $account->refresh();

        $this->assertSame('Renamed Only', $account->name);
        $this->assertTrue(Hash::check('password', $account->password));
    }

    // ------------------------------------------------------------- boundaries

    public function test_saving_never_touches_role_school_or_superadmin_rights(): void
    {
        $school = $this->school();

        $account = User::factory()->create([
            'name' => 'Assigned Person',
            'username' => 'assigned_person',
            'role' => 'administrator',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('name', 'Renamed Person')
            ->set('username', 'renamed_person')
            ->call('save')
            ->assertHasNoErrors();

        $account->refresh();

        $this->assertSame('administrator', $account->role);
        $this->assertFalse($account->is_superadmin);
        $this->assertSame($school->id, $account->school_id);
    }

    public function test_the_assigned_role_and_school_are_shown_read_only(): void
    {
        $school = $this->school();

        $account = User::factory()->create([
            'name' => 'Assigned Person',
            'username' => 'assigned_person',
            'role' => 'administrator',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);

        Livewire::actingAs($account)->test(UpdateProfile::class)
            ->call('openModal')
            ->assertSee('Administrator')
            ->assertSee('Alpha Elementary School')
            ->assertSee('assigned by an administrator')
            // Both assigned values render as read-only fields, so the form
            // cannot be used to edit them.
            ->assertSeeHtml('id="profileRole"')
            ->assertSeeHtml('readonly');
    }

    public function test_another_accounts_row_is_untouched(): void
    {
        $mine = $this->staff('mine');
        $theirs = $this->staff('theirs');

        $before = $theirs->only(['name', 'username', 'email', 'role', 'is_superadmin', 'school_id', 'password']);

        Livewire::actingAs($mine)->test(UpdateProfile::class)
            ->call('openModal')
            ->set('name', 'Only Mine')
            ->set('username', 'only_mine')
            ->set('email', 'only.mine@deped.gov.ph')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($before, $theirs->refresh()->only(array_keys($before)));
    }
}
