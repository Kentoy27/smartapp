<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_shows_remember_me_and_branding(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('Smart', false);
        $response->assertSee('App', false);
        $response->assertSee('Remember me');
        $response->assertSee('name="remember"', false);
    }

    public function test_remember_me_prefills_saved_login_identifier(): void
    {
        $user = User::factory()->create([
            'username' => 'adminuser',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response = $this->withCookie('smartapp_login', $user->email)
            ->get(route('login'));

        $response->assertOk();
        $response->assertSee('value="' . $user->email . '"', false);
        $response->assertSee('checked', false);
    }

    public function test_wrong_password_shows_a_password_error(): void
    {
        User::factory()->create([
            'username' => 'adminuser',
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ]);

        $this->from(route('login'))
            ->post(route('login'), [
                'login' => 'adminuser',
                'password' => 'wrong-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'password' => 'Your password is wrong.',
            ]);
    }

    public function test_successful_login_passes_a_success_alert_to_the_dashboard(): void
    {
        User::factory()->create([
            'username' => 'adminuser',
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this->post(route('login'), [
            'login' => 'adminuser',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect('/home');

        $this->get('/home')
            ->assertOk()
            ->assertSee('data-success="Login successful. Welcome back!"', false)
            ->assertSee('Login successful', false);
    }

    public function test_logout_invalidates_the_session_and_returns_to_login(): void
    {
                /** @var User $user */
                $user = User::factory()->create([
            'username' => 'adminuser',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);


        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', 'Logout successful. See you again soon!');

        $this->get(route('login'))->assertOk();
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('home'))->assertRedirect(route('login'));
    }

}
