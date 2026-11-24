<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_temporary_password_account_can_sign_in_and_use_the_dashboard(): void
    {
        $user = User::factory()->create([
            'username' => 'temporary-user',
            'password' => 'TemporaryPassword123!',
            'must_change_password' => true,
            'role' => 'user',
        ]);

        $this->post(route('login'), [
            'login' => $user->username,
            'password' => 'TemporaryPassword123!',
        ])->assertRedirect(route('home'));

        $this->get(route('home'))->assertOk();
        $this->get(route('password.change'))
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('Temporary password')
            ->assertSee('Use at least 8 characters.')
            ->assertDontSee('Use at least 12 characters');
    }

    public function test_an_existing_session_with_a_legacy_change_flag_can_use_the_dashboard(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'role' => 'user',
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_can_replace_the_temporary_password_and_continue(): void
    {
        $user = User::factory()->create([
            'username' => 'temporary-user',
            'password' => 'TemporaryPassword123!',
            'must_change_password' => true,
            'role' => 'user',
        ]);

        $this->post(route('login'), [
            'login' => $user->username,
            'password' => 'TemporaryPassword123!',
        ])->assertRedirect(route('home'));

        $this->from(route('password.change'))
            ->post(route('password.change.update'), [
                'current_password' => 'TemporaryPassword123!',
                'password' => 'passw0rd',
                'password_confirmation' => 'passw0rd',
            ])
            ->assertRedirect(route('home'));

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(password_verify('passw0rd', $user->fresh()->password));
        $this->get(route('home'))->assertOk();
    }

    public function test_password_change_requires_the_correct_temporary_password_and_matching_confirmation(): void
    {
        $user = User::factory()->create([
            'username' => 'temporary-user',
            'password' => 'TemporaryPassword123!',
            'must_change_password' => true,
            'role' => 'user',
        ]);

        $this->post(route('login'), [
            'login' => $user->username,
            'password' => 'TemporaryPassword123!',
        ])->assertRedirect(route('home'));

        $this->from(route('password.change'))
            ->post(route('password.change.update'), [
                'current_password' => 'NotTheTemporaryPassword123!',
                'password' => 'weak',
                'password_confirmation' => 'different',
            ])
            ->assertRedirect(route('password.change'))
            ->assertSessionHasErrors(['current_password', 'password']);

        $this->assertTrue($user->fresh()->must_change_password);
        $this->get(route('password.change'))->assertOk();
    }

    public function test_administrator_can_change_a_temporary_password_without_the_strong_password_rules(): void
    {
        $administrator = User::factory()->create([
            'username' => 'temporary-admin',
            'password' => 'TemporaryPassword123!',
            'must_change_password' => true,
            'role' => 'administrator',
            'is_superadmin' => false,
        ]);

        $this->post(route('login'), [
            'login' => $administrator->username,
            'password' => 'TemporaryPassword123!',
        ])->assertRedirect(route('home'));

        $this->get(route('password.change'))
            ->assertOk()
            ->assertSee('Use at least 8 characters.')
            ->assertDontSee('Use at least 12 characters with uppercase');

        $this->from(route('password.change'))
            ->post(route('password.change.update'), [
                'current_password' => 'TemporaryPassword123!',
                'password' => 'adminpass',
                'password_confirmation' => 'adminpass',
            ])
            ->assertRedirect(route('home'));

        $this->assertFalse($administrator->fresh()->must_change_password);
        $this->assertTrue(password_verify('adminpass', $administrator->fresh()->password));
    }

    public function test_administrator_password_change_still_requires_eight_characters(): void
    {
        $administrator = User::factory()->create([
            'username' => 'temporary-admin',
            'password' => 'TemporaryPassword123!',
            'must_change_password' => true,
            'role' => 'administrator',
            'is_superadmin' => false,
        ]);

        $this->post(route('login'), [
            'login' => $administrator->username,
            'password' => 'TemporaryPassword123!',
        ])->assertRedirect(route('home'));

        $this->from(route('password.change'))
            ->post(route('password.change.update'), [
                'current_password' => 'TemporaryPassword123!',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertRedirect(route('password.change'))
            ->assertSessionHasErrors(['password']);

        $this->assertTrue($administrator->fresh()->must_change_password);
    }

    public function test_sds_viewer_with_a_temporary_password_goes_directly_to_the_dashboard(): void
    {
        $viewer = User::factory()->create([
            'username' => 'sds-viewer',
            'password' => 'TemporaryPassword123!',
            'must_change_password' => true,
            'role' => 'viewer',
            'is_superadmin' => false,
        ]);

        $this->post(route('login'), [
            'login' => $viewer->username,
            'password' => 'TemporaryPassword123!',
        ])->assertRedirect(route('home'));

        $this->get(route('home'))->assertOk();
        $this->get(route('password.change'))->assertRedirect(route('home'));

        $this->assertTrue($viewer->fresh()->must_change_password);
    }
}
