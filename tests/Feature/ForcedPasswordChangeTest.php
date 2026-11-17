<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_temporary_password_account_is_required_to_change_its_password(): void
    {
        $user = User::factory()->create([
            'username' => 'temporary-user',
            'password' => 'TemporaryPassword123!',
            'must_change_password' => true,
        ]);

        $this->post(route('login'), [
            'login' => $user->username,
            'password' => 'TemporaryPassword123!',
        ])->assertRedirect(route('password.change'));

        $this->get(route('home'))->assertRedirect(route('password.change'));
        $this->get(route('users.index'))->assertRedirect(route('password.change'));
        $this->getJson('/api/admin/account-classifications')
            ->assertStatus(423)
            ->assertJsonPath('password_change_url', route('password.change'));
        $this->get(route('password.change'))
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('Temporary password');
    }

    public function test_a_user_can_replace_the_temporary_password_and_continue(): void
    {
        $user = User::factory()->create([
            'username' => 'temporary-user',
            'password' => 'TemporaryPassword123!',
            'must_change_password' => true,
        ]);

        $this->post(route('login'), [
            'login' => $user->username,
            'password' => 'TemporaryPassword123!',
        ])->assertRedirect(route('password.change'));

        $this->from(route('password.change'))
            ->post(route('password.change.update'), [
                'current_password' => 'TemporaryPassword123!',
                'password' => 'NewStrongPassword123!',
                'password_confirmation' => 'NewStrongPassword123!',
            ])
            ->assertRedirect(route('home'));

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(password_verify('NewStrongPassword123!', $user->fresh()->password));
        $this->get(route('home'))->assertOk();
    }

    public function test_password_change_requires_the_correct_temporary_password_and_strong_confirmation(): void
    {
        $user = User::factory()->create([
            'username' => 'temporary-user',
            'password' => 'TemporaryPassword123!',
            'must_change_password' => true,
        ]);

        $this->post(route('login'), [
            'login' => $user->username,
            'password' => 'TemporaryPassword123!',
        ])->assertRedirect(route('password.change'));

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
}
