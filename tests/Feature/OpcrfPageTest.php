<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OpcrfPageTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        return User::create([
            'name' => 'Staff Member',
            'username' => 'staff',
            'email' => 'staff@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);
    }

    private function superadmin(): User
    {
        return User::create([
            'name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'adminuser@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
    }

    public function test_guests_cannot_open_the_opcrf_page(): void
    {
        $this->get(route('opcrf.index'))
            ->assertRedirect(route('login'));
    }

    public function test_regular_users_can_open_the_opcrf_page(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)
            ->get(route('opcrf.index'))
            ->assertOk()
            ->assertSee('Opcrf');
    }

    public function test_the_opcrf_page_is_the_submissions_table_only(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)
            ->get(route('opcrf.index'))
            ->assertOk()
            ->assertSee('Your OPCRF submissions')
            // The manual form is gone from the page (it was replaced by the
            // upload trigger on the dashboard's template card).
            ->assertDontSee('Submit your OPCRF')
            ->assertDontSee('Name of Employee');
    }

    public function test_superadmins_get_404_on_the_opcrf_page(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)
            ->get(route('opcrf.index'))
            ->assertNotFound();
    }

    public function test_sidebar_shows_opcrf_to_regular_users(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user);

        $html = \Livewire\Livewire::test(\App\Livewire\Sidebar::class, ['usersCount' => 0])
            ->html();

        $this->assertStringContainsString('Opcrf', $html);
        $this->assertStringNotContainsString('Users', $html);
    }

    public function test_sidebar_items_follow_the_working_order_for_staff(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user);

        $html = \Livewire\Livewire::test(\App\Livewire\Sidebar::class, ['usersCount' => 0])
            ->html();

        $positions = array_map(
            fn (string $label) => strpos($html, ">{$label}</span>"),
            ['Dashboard', 'Opcrf']
        );

        $this->assertNotContains(false, $positions, 'Both items are rendered.');

        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions, 'Sidebar items must appear in working order.');
    }

    public function test_sidebar_hides_opcrf_from_superadmins(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin);

        $html = \Livewire\Livewire::test(\App\Livewire\Sidebar::class, ['usersCount' => 3])
            ->html();

        // The staff Opcrf page stays hidden — but the superadmin's own
        // Review Opcrf item shows.
        $this->assertStringNotContainsString('href="'.route('opcrf.index').'"', $html);
        $this->assertStringContainsString('Review Opcrf', $html);
        $this->assertStringContainsString('Users', $html);
    }

    public function test_sidebar_shows_the_review_opcrf_badge_to_superadmins(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();

        // Two submissions → the badge reads 2.
        \App\Models\OpcrfSubmission::create([
            'user_id' => $staff->id,
            'employee_name' => 'Jane D. Doe',
            'position' => 'Teacher I',
            'review_period' => 'January to December 2026',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objective 1',
            'accomplishments' => 'Did it',
            'self_rating' => 4.5,
            'remarks' => null,
            'submitted_at' => now()->subDay(),
        ]);
        \App\Models\OpcrfSubmission::create([
            'user_id' => $staff->id,
            'employee_name' => 'John Q. Public',
            'position' => 'Master Teacher I',
            'review_period' => 'January to December 2025',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objective 1',
            'accomplishments' => 'Did it',
            'self_rating' => 4.0,
            'remarks' => null,
            'submitted_at' => now()->subDays(2),
        ]);

        $this->actingAs($admin);

        \Livewire\Livewire::test(\App\Livewire\Sidebar::class, ['usersCount' => 3])
            ->assertSee('Review Opcrf')
            ->assertSee('>2</span>', false);
    }

    public function test_the_sidebar_badge_updates_on_the_submission_event(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();

        $this->actingAs($admin);

        $component = \Livewire\Livewire::test(\App\Livewire\Sidebar::class, ['usersCount' => 3]);
        $component->assertSee('>0</span>', false);

        $this->createStaffSubmission($staff);

        $component->call('refreshOpcrfBadge')
            ->assertSee('>1</span>', false);
    }

    /**
     * A confirmed submission for the given staff member.
     */
    private function createStaffSubmission(User $user): void
    {
        \App\Models\OpcrfSubmission::create([
            'user_id' => $user->id,
            'employee_name' => 'Jane D. Doe',
            'position' => 'Teacher I',
            'review_period' => 'January to December 2026',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objective 1',
            'accomplishments' => 'Did it',
            'self_rating' => 4.5,
            'remarks' => null,
            'submitted_at' => now(),
        ]
        );
    }
}
