<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_are_sent_directly_to_the_dashboard(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        $this->get('/password/change')->assertNotFound();
    }
}
