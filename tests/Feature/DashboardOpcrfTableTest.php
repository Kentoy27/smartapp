<?php

namespace Tests\Feature;

use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardOpcrfTableTest extends TestCase
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

    private function createSubmission(User $user, array $overrides = []): OpcrfSubmission
    {
        return OpcrfSubmission::create(array_merge([
            'user_id' => $user->id,
            'employee_name' => 'Staff Member',
            'position' => 'Teacher I',
            'review_period' => 'January to December 2025',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objective 1',
            'accomplishments' => 'Accomplished objective 1',
            'self_rating' => 4.25,
            'remarks' => 'Well done.',
            'submitted_at' => now()->subDay(),
        ], $overrides));
    }

    public function test_staff_dashboard_does_not_list_submissions(): void
    {
        // The submissions table moved off the dashboard — it lives on the
        // /opcrf page only.
        $user = $this->staffUser();
        $this->createSubmission($user);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Your OPCRF submissions')
            ->assertDontSee('January to December 2025');
    }

    public function test_superadmins_do_not_get_the_submissions_table(): void
    {
        $admin = $this->superadmin();
        $this->createSubmission($admin);

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Your OPCRF submissions');
    }

    public function test_the_opcrf_page_lists_the_staff_members_submissions(): void
    {
        $user = $this->staffUser();
        $submission = $this->createSubmission($user);

        $this->actingAs($user)
            ->get(route('opcrf.index'))
            ->assertOk()
            ->assertSee('Your OPCRF submissions')
            ->assertSee('January to December 2025')
            ->assertSee('Teacher I')
            ->assertSee('4.25')
            ->assertSee('Well done.')
            ->assertSee($submission->submitted_at->format('M j, Y g:i A'));
    }

    public function test_the_opcrf_page_shows_the_empty_hint_with_no_submissions(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)
            ->get(route('opcrf.index'))
            ->assertOk()
            ->assertSee('Your OPCRF submissions')
            ->assertSee('Nothing submitted yet');
    }

    public function test_the_opcrf_page_hides_other_users_submissions(): void
    {
        $user = $this->staffUser();
        $other = User::create([
            'name' => 'Other Member',
            'username' => 'other',
            'email' => 'other@example.com',
            'password' => Hash::make('password123'),
        ]);
        $this->createSubmission($other, [
            'review_period' => 'Secret period',
            'employee_name' => 'Secret Employee',
        ]);
        $own = $this->createSubmission($user);

        $this->actingAs($user)
            ->get(route('opcrf.index'))
            ->assertOk()
            ->assertSee($own->review_period)
            ->assertDontSee('Secret period')
            ->assertDontSee('Secret Employee');
    }

    public function test_only_the_ten_most_recent_submissions_are_on_page_one(): void
    {
        $user = $this->staffUser();

        for ($i = 1; $i <= 12; $i++) {
            $this->createSubmission($user, [
                'review_period' => "Period {$i}",
                'submitted_at' => now()->subDays($i),
            ]);
        }

        $html = $this->actingAs($user)
            ->get(route('opcrf.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Period 1', $html);
        $this->assertStringContainsString('Period 10', $html);
        $this->assertStringNotContainsString('Period 11', $html);
        $this->assertStringNotContainsString('Period 12', $html);
    }
}
