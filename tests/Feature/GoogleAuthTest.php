<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(array $attributes = []): GoogleUser
    {
        $user = new GoogleUser;

        $user->id = $attributes['id'] ?? '1234567890';
        $user->name = $attributes['name'] ?? 'Jane Doe';
        $user->nickname = $attributes['nickname'] ?? 'janedoe';
        $user->email = $attributes['email'] ?? 'jane@example.com';
        $user->avatar = $attributes['avatar'] ?? 'https://lh3.googleusercontent.com/a/xyz';

        return $user;
    }

    private function mockSocialite(GoogleUser $googleUser): void
    {
        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturnSelf();

        Socialite::shouldReceive('user')
            ->once()
            ->andReturn($googleUser);
    }

    public function test_redirect_endpoint_points_to_google_consent_screen(): void
    {
        $response = $this->get(route('google.redirect'));

        $response->assertRedirect();

        $target = $response->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth', $target);
        $this->assertStringContainsString('client_id=' . config('services.google.client_id'), $target);
        $this->assertStringContainsString('redirect_uri=' . urlencode(config('services.google.redirect')), $target);
        $this->assertStringContainsString('scope=openid+profile+email', $target);
        $this->assertStringContainsString('state=', $target);
    }

    public function test_unknown_google_account_is_never_registered(): void
    {
        $this->mockSocialite($this->fakeGoogleUser());

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('login');

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_existing_email_is_linked_to_the_google_account(): void
    {
        $existing = User::factory()->create([
            'email' => 'jane@example.com',
            'username' => 'jane',
            'google_id' => null,
        ]);

        $this->mockSocialite($this->fakeGoogleUser());

        $response = $this->get(route('google.callback'));

        $response->assertRedirect('/home');

        $this->assertSame('1234567890', $existing->refresh()->google_id);
        $this->assertSame(1, User::where('email', 'jane@example.com')->count());
        $this->assertAuthenticatedAs($existing);
    }

    public function test_returning_google_user_logs_in_without_creating_duplicates(): void
    {
        $existing = User::factory()->create([
            'email' => 'jane@example.com',
            'username' => 'janedoe',
            'google_id' => '1234567890',
        ]);

        $this->mockSocialite($this->fakeGoogleUser());

        $response = $this->get(route('google.callback'));

        $response->assertRedirect('/home');

        $this->assertSame(1, User::count());
        $this->assertAuthenticatedAs($existing);
    }

    public function test_google_account_of_a_different_existing_user_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'username' => 'jane',
            'google_id' => '9999999999', // linked to a different Google account
        ]);

        $this->mockSocialite($this->fakeGoogleUser());

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('login');
        $this->assertGuest();

        $this->assertSame('9999999999', User::where('email', 'jane@example.com')->value('google_id'));
    }

    public function test_cancelled_or_failed_google_sign_in_redirects_back_to_login(): void
    {
        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturnSelf();

        Socialite::shouldReceive('user')
            ->once()
            ->andThrow(new InvalidStateException);

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }
}
