<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_flagged_users_are_redirected_until_they_change_their_password(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertRedirect(route('password.change'));

        $this->get(route('password.change'))->assertOk();

        $this->post(route('password.change'), [
            'password' => 'WeakPassword123',
            'password_confirmation' => 'WeakPassword123',
        ])->assertSessionHasErrors('password');

        $this->post(route('password.change'), [
            'password' => 'GoodPassword123!',
            'password_confirmation' => 'GoodPassword123!',
        ])->assertRedirect(route('home'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('GoodPassword123!', $user->password));

        $this->get(route('home'))->assertOk();
    }

    public function test_unflagged_users_are_not_sent_to_the_password_change_page(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();
    }
}
