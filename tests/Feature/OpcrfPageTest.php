<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\District;
use App\Models\OpcrfSubmission;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOpcrfWorkbooks;
use Tests\TestCase;

class OpcrfPageTest extends TestCase
{
    use BuildsOpcrfWorkbooks;
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

    public function test_viewers_can_view_opcrf_parts_but_cannot_open_an_editable_part(): void
    {
        $viewer = User::create([
            'name' => 'SDS Viewer',
            'username' => 'sdsviewer',
            'email' => 'sdsviewer@example.com',
            'password' => Hash::make('password123'),
            'role' => 'viewer',
            'is_superadmin' => false,
        ]);

        $this->actingAs($viewer)
            ->get(route('opcrf.index'))
            ->assertOk()
            ->assertSee('Part details are available to School Heads only.')
            ->assertDontSee('Access Part 1')
            ->assertDontSee('Access Parts 2–4')
            ->assertDontSee('View only');

        $this->get(route('opcrf.part', ['part' => 1]))
            ->assertNotFound();

        \Livewire\Livewire::actingAs($viewer)
            ->test(\App\Livewire\OpcrfParts::class)
            ->assertSee('Part details are available to School Heads only.')
            ->assertDontSee('Access Part 1')
            ->assertDontSee('Access Parts 2–4');

        $html = \Livewire\Livewire::actingAs($viewer)
            ->test(\App\Livewire\Sidebar::class, ['usersCount' => 0])
            ->html();

        $this->assertStringContainsString('href="'.route('opcrf.index').'"', $html);
    }

    public function test_viewers_can_review_school_head_submissions_with_school_and_district(): void
    {
        Storage::fake('local');
        $district = District::create(['name' => 'North District']);
        $school = School::create([
            'district_id' => $district->id,
            'name' => 'Riverside Elementary School',
        ]);
        $head = User::factory()->create([
            'name' => 'Jordan School Head',
            'username' => 'riverside-head',
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);
        $nonHead = User::factory()->create([
            'role' => 'administrator',
            'is_superadmin' => false,
        ]);

        $path = 'opcrf-submissions/'.$head->id.'/submitted.xlsx';
        Storage::disk('local')->put($path, file_get_contents($this->buildFullOpcrfWorkbook()));
        $submission = OpcrfSubmission::create([
            'user_id' => $head->id,
            'employee_name' => 'Jordan School Head',
            'position' => 'School Head',
            'review_period' => 'January to December 2026',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objectives',
            'accomplishments' => 'Accomplishments',
            'self_rating' => 4.25,
            'submitted_at' => now(),
            'file_path' => $path,
            'file_original_name' => 'submitted.xlsx',
        ]);
        OpcrfSubmission::create([
            'user_id' => $nonHead->id,
            'employee_name' => 'Administrator',
            'position' => 'Administrator',
            'review_period' => 'January to December 2026',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objectives',
            'accomplishments' => 'Accomplishments',
            'self_rating' => 4,
            'submitted_at' => now(),
        ]);

        $viewer = User::factory()->create([
            'role' => 'viewer',
            'is_superadmin' => false,
        ]);

        $this->actingAs($viewer)
            ->get(route('opcrf.index'))
            ->assertOk()
            ->assertSee('Submitted OPCRFs from School Heads')
            ->assertSee('Jordan School Head')
            ->assertSee('Riverside Elementary School')
            ->assertSee('North District')
            ->assertSee('View OPCRF')
            ->assertDontSee('Administrator');

        Livewire::actingAs($viewer)
            ->test(\App\Livewire\OpcrfViewerSubmissions::class)
            ->set('search', 'Jordan')
            ->assertSee('type="text"', false)
            ->assertSee('class="users-clear"', false)
            ->set('search', 'Riverside')
            ->assertSee('Jordan School Head')
            ->assertDontSee('Administrator')
            ->set('search', 'North District')
            ->assertSee('Riverside Elementary School')
            ->set('search', 'missing school head')
            ->assertSee('No School Head OPCRF submissions match your search.')
            ->call('clearSearch')
            ->assertSee('Jordan School Head')
            ->set('search', 'Jordan')
            ->call('openSubmission', $submission->id)
            ->assertSee('Submitted OPCRF — Riverside Elementary School')
            ->assertSee('Submitted form')
            ->assertSee('PART I-A')
            ->assertSee('PART II')
            ->assertSee('PART III')
            ->assertSee('PART IV')
            ->assertDontSee('Download workbook')
            ->assertDontSee('Review Decision')
            ->assertDontSee('Approve');

        $this->get(route('opcrf.submission.download', $submission))
            ->assertNotFound();
    }

    public function test_sidebar_shows_opcrf_to_regular_users(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user);

        $html = \Livewire\Livewire::test(\App\Livewire\Sidebar::class, ['usersCount' => 0])
            ->html();

        $this->assertStringContainsString('OPCRF', $html);
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
            ['Dashboard', 'OPCRF']
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

        // The staff OPCRF page stays hidden — but the superadmin's own
        // destinations remain available under the same Files label. (Users /
        // Districts & Schools are the Administrator's pages, not the
        // superadmin's, so they are not offered here.)
        $this->assertStringNotContainsString('href="'.route('opcrf.index').'"', $html);
        $this->assertStringContainsString('OPCRF', $html);
        $this->assertStringContainsString('OPCRF Schedule', $html);
        $this->assertStringContainsString('School Allotments', $html);
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
            ->assertSee('OPCRF')
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
