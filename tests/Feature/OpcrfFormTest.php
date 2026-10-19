<?php

namespace Tests\Feature;

use App\Livewire\OpcrfForm;
use App\Models\OpcrfSchedule;
use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class OpcrfFormTest extends TestCase
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

    /**
     * A complete, valid set of form inputs.
     *
     * @return array<string, string>
     */
    private function validInput(): array
    {
        return [
            'employee_name' => 'Jane D. Doe',
            'position' => 'Teacher I',
            'review_period' => 'January to December 2026',
            'division_office' => 'Schools Division Office',
            'objectives' => "Objective 1: Improved learner outcomes\nObjective 2: Conducted research",
            'accomplishments' => "Raised MPS by 5 points\nCompleted one action research",
            'self_rating' => '4.50',
            'remarks' => 'Submitted on time.',
        ];
    }

    public function test_the_form_component_still_works_standalone(): void
    {
        // The /opcrf page now shows the submissions table only — the form
        // component is no longer mounted there, but stays fully functional.
        $user = $this->staffUser();

        Livewire::actingAs($user)
            ->test(OpcrfForm::class)
            ->assertSee('Submit your OPCRF')
            ->assertSee('Name of Employee');
    }

    public function test_submit_opens_the_review_modal_and_saves_nothing_yet(): void
    {
        $user = $this->staffUser();

        Livewire::actingAs($user)
            ->test(OpcrfForm::class)
            ->fill($this->validInput())
            ->call('submitForReview')
            ->assertSet('showReview', true)
            ->assertSee('Review your OPCRF')
            // Every entered detail must be visible inside the review modal.
            ->assertSee('Jane D. Doe')
            ->assertSee('Teacher I')
            ->assertSee('January to December 2026')
            ->assertSee('Schools Division Office')
            ->assertSee('Objective 1: Improved learner outcomes')
            ->assertSee('Raised MPS by 5 points')
            ->assertSee('4.50')
            ->assertSee('Submitted on time.');

        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_invalid_form_shows_errors_and_never_opens_the_review(): void
    {
        $user = $this->staffUser();

        Livewire::actingAs($user)
            ->test(OpcrfForm::class)
            ->set('employee_name', '')
            ->set('self_rating', '9')
            ->call('submitForReview')
            ->assertSet('showReview', false)
            ->assertHasErrors(['employee_name', 'position', 'review_period', 'division_office', 'objectives', 'accomplishments', 'self_rating']);

        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_confirming_the_review_persists_the_submission(): void
    {
        $user = $this->staffUser();

        Livewire::actingAs($user)
            ->test(OpcrfForm::class)
            ->fill($this->validInput())
            ->call('submitForReview')
            ->assertSet('showReview', true)
            ->call('confirmSubmit')
            ->assertSet('showReview', false)
            ->assertSee('OPCRF submitted');

        $submission = OpcrfSubmission::firstOrFail();

        $this->assertSame($user->id, $submission->user_id);
        $this->assertSame('Jane D. Doe', $submission->employee_name);
        $this->assertSame('Teacher I', $submission->position);
        $this->assertSame('January to December 2026', $submission->review_period);
        $this->assertSame('Schools Division Office', $submission->division_office);
        $this->assertSame(4.5, $submission->self_rating);
        $this->assertSame('Submitted on time.', $submission->remarks);
        $this->assertNotNull($submission->submitted_at);
    }

    public function test_updating_part_one_changes_the_existing_submission_instead_of_adding_a_row(): void
    {
        $user = $this->staffUser();
        $this->partOneSchedule();
        $component = Livewire::actingAs($user)
            ->test(OpcrfForm::class)
            ->fill($this->validInput())
            ->call('submitForReview')
            ->call('confirmSubmit');

        $submission = OpcrfSubmission::firstOrFail();
        $this->assertSame(1, OpcrfSubmission::where('user_id', $user->id)->count());

        $updatedInput = $this->validInput();
        $updatedInput['objectives'] = 'Updated objective';
        $updatedInput['accomplishments'] = 'Updated accomplishment';
        $component
            ->fill($updatedInput)
            ->call('submitForReview')
            ->call('confirmSubmit')
            ->assertSee('OPCRF updated');

        $submission->refresh();
        $this->assertSame(1, OpcrfSubmission::where('user_id', $user->id)->count());
        $this->assertSame('Updated objective', $submission->objectives);
        $this->assertSame('Updated accomplishment', $submission->accomplishments);
        $this->assertSame(OpcrfSubmission::STATUS_RESUBMITTED, $submission->status);
        $this->assertSame(1, $submission->reviews()->count());
        $this->assertSame($submission->id, $component->get('submissionId'));
    }

    public function test_a_part_one_submission_is_read_only_after_the_deadline(): void
    {
        $user = $this->staffUser();
        OpcrfSchedule::create([
            'opcrf_year' => (int) now()->format('Y'),
            'part_number' => OpcrfSchedule::PART_ONE,
            'start_datetime' => now()->subDays(2),
            'end_datetime' => now()->subDay(),
            'is_enabled' => true,
            'created_by' => $this->superadmin()->id,
        ]);
        OpcrfSubmission::create(array_merge($this->validInput(), [
            'user_id' => $user->id,
            'self_rating' => 4.5,
            'submitted_at' => now()->subDays(3),
            'status' => OpcrfSubmission::STATUS_PENDING,
        ]));

        Livewire::actingAs($user)
            ->test(OpcrfForm::class)
            ->assertSee('read-only')
            ->assertSet('showReview', false)
            ->assertSet('submissionId', OpcrfSubmission::firstOrFail()->id)
            ->assertSet('objectives', $this->validInput()['objectives'])
            ->assertSet('accomplishments', $this->validInput()['accomplishments'])
            ->call('submitForReview')
            ->assertForbidden();
    }

    public function test_the_legacy_form_cannot_update_an_opcrf_at_the_final_reviewer(): void
    {
        $user = $this->staffUser();
        $finalReviewer = User::create([
            'name' => 'Final Reviewer',
            'username' => 'SY',
            'email' => 'final-reviewer@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
        config(['opcrf.next_reviewer' => 'SY']);
        $this->partOneSchedule();
        OpcrfSubmission::create(array_merge($this->validInput(), [
            'user_id' => $user->id,
            'self_rating' => 4.5,
            'submitted_at' => now()->subHour(),
            'reviewer_id' => $finalReviewer->id,
            'status' => OpcrfSubmission::STATUS_PENDING,
        ]));

        Livewire::actingAs($user)
            ->test(OpcrfForm::class)
            ->fill($this->validInput())
            ->call('submitForReview')
            ->assertForbidden();

        Livewire::actingAs($user)
            ->test(OpcrfForm::class)
            ->fill($this->validInput())
            ->call('confirmSubmit')
            ->assertForbidden();

        $this->assertSame(
            OpcrfSubmission::STATUS_PENDING,
            OpcrfSubmission::firstOrFail()->status
        );
        $this->assertSame(0, OpcrfSubmission::firstOrFail()->reviews()->count());
    }

    public function test_cancelling_the_review_returns_to_the_filled_form_without_saving(): void
    {
        $user = $this->staffUser();

        Livewire::actingAs($user)
            ->test(OpcrfForm::class)
            ->fill($this->validInput())
            ->call('submitForReview')
            ->call('cancelReview')
            ->assertSet('showReview', false)
            // Values survive the cancel so the user can keep editing.
            ->assertSet('employee_name', 'Jane D. Doe')
            ->assertSet('self_rating', '4.50');

        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_superadmins_are_blocked_from_the_form_component(): void
    {
        $admin = $this->superadmin();

        // Even a direct component call is rejected by the staff-only guard
        // (the abort unwinds the Livewire update, so assert via try/catch —
        // same pattern as DistrictManagerTest).
        $thrown = null;
        try {
            Livewire::actingAs($admin)
                ->test(OpcrfForm::class)
                ->call('submitForReview');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected the staff-only guard to reject a superadmin.');
        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    private function partOneSchedule(): void
    {
        OpcrfSchedule::create([
            'opcrf_year' => (int) now()->format('Y'),
            'part_number' => OpcrfSchedule::PART_ONE,
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->addDay(),
            'is_enabled' => true,
            'created_by' => $this->superadmin()->id,
        ]);
    }

    public function test_superadmins_get_404_on_the_opcrf_page(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)
            ->get(route('opcrf.index'))
            ->assertNotFound();
    }

    public function test_users_only_see_their_own_submissions_on_the_dashboard(): void
    {
        $user = $this->staffUser();
        $other = User::create([
            'name' => 'Other Member',
            'username' => 'other',
            'email' => 'other@example.com',
            'password' => Hash::make('password123'),
        ]);

        OpcrfSubmission::create([
            'user_id' => $other->id,
            'employee_name' => 'Other Member',
            'position' => 'Principal',
            'review_period' => 'Secret period',
            'division_office' => 'Secret office',
            'objectives' => 'Secret objective',
            'accomplishments' => 'Secret accomplishment',
            'self_rating' => 3,
            'remarks' => null,
            'submitted_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Secret period');
    }
}
