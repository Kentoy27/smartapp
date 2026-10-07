<?php

namespace Tests\Feature;

use App\Livewire\OpcrfMovs;
use App\Livewire\OpcrfReview;
use App\Models\OpcrfSubmission;
use App\Models\User;
use App\Notifications\OpcrfReturned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOpcrfWorkbooks;
use Tests\TestCase;

/**
 * The Return for Revision → Resubmission workflow: a superadmin returns a
 * submission (remarks required, status transition recorded, staff member
 * notified), the staff member revises and resubmits (previous workbook
 * archived as a version, status → 'resubmitted', routed back to the
 * returning superadmin), and the full history stays on the one trail.
 */
class OpcrfResubmitTest extends TestCase
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

    private function createSubmission(User $user, array $overrides = []): OpcrfSubmission
    {
        return OpcrfSubmission::create(array_merge([
            'user_id' => $user->id,
            'employee_name' => 'Jane D. Doe',
            'position' => 'Teacher I',
            'review_period' => 'January to December 2026',
            'division_office' => 'Schools Division Office',
            'objectives' => 'Objective 1: Improved learner outcomes',
            'accomplishments' => 'Raised MPS by 5 points',
            'self_rating' => 4.5,
            'status' => OpcrfSubmission::STATUS_PENDING,
            'submitted_at' => now()->subDay(),
        ], $overrides));
    }

    public function test_returning_records_the_status_transition_and_notifies_the_staff_member(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $admin->id]);

        $submission->returnForRevision($admin, 'Please correct the timeline and update the required sections.');

        $submission->refresh();

        // Status flipped, and the transition recorded on the trail row.
        $this->assertSame(OpcrfSubmission::STATUS_RETURNED, $submission->status);
        $this->assertDatabaseHas('opcrf_reviews', [
            'opcrf_submission_id' => $submission->id,
            'reviewer_id' => $admin->id,
            'action' => 'return',
            'previous_status' => OpcrfSubmission::STATUS_PENDING,
            'new_status' => OpcrfSubmission::STATUS_RETURNED,
            'remarks' => 'Please correct the timeline and update the required sections.',
        ]);

        // The staff member got the "OPCRF Returned" notification.
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $staff->id,
            'notifiable_type' => User::class,
            'type' => OpcrfReturned::class,
        ]);
    }

    public function test_returning_needs_remarks_in_the_review_modal(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $admin->id]);

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->call('returnSubmission')
            ->assertHasErrors(['reviewRemarks']);

        $submission->refresh();
        $this->assertSame(OpcrfSubmission::STATUS_PENDING, $submission->status);
    }

    public function test_the_staff_member_sees_action_required_with_the_remarks(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $admin->id]);
        $submission->returnForRevision($admin, 'Correct the timeline and update Section 3.');

        Livewire::actingAs($staff)
            ->test(OpcrfMovs::class)
            // The banner and its message…
            ->assertSee('Action Required — Revision Needed')
            ->assertSee('Your OPCRF has been returned for revision.')
            // …the reviewer's exact remarks, prominent…
            ->assertSee('Correct the timeline and update Section 3.')
            ->assertSee('Superadmin Remarks')
            // …and the revise entry point.
            ->assertSee('Revise &amp; Resubmit', false);
    }

    public function test_resubmitting_archives_the_previous_workbook_and_routes_back_to_the_returner(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $admin->id]);

        // Version 1: the original upload, archived when the revision lands.
        Storage::disk('local')->put('opcrf-submissions/'.$staff->id.'/original.xlsx', 'original-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/original.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        $submission->returnForRevision($admin, 'Fix the accomplishments.');

        // The staff member resubmits through the modal with a revised file.
        Livewire::actingAs($staff)
            ->test(OpcrfMovs::class)
            ->call('openRevise', $submission->id)
            ->assertSet('resubmittingId', $submission->id)
            ->set('revisionFile', UploadedFile::fake()->createWithContent(
                'revised.xlsx',
                // A real workbook naming the account: the revise upload
                // answers to the same identity rule as a first submission.
                file_get_contents($this->buildFilledOpcrf(['F4' => 'Staff Member'])),
            ))
            ->call('confirmResubmit')
            ->assertHasNoErrors();

        $submission->refresh();

        // Status moved to resubmitted, and routed back to the returning
        // superadmin — not to another superadmin, and not approved.
        $this->assertSame(OpcrfSubmission::STATUS_RESUBMITTED, $submission->status);
        $this->assertSame($admin->id, $submission->reviewer_id);
        $this->assertFalse($submission->isApproved());

        // Version 1 = the replaced original; Version 2 is the current file
        // (not archived until the next revision replaces it).
        $this->assertDatabaseHas('opcrf_submission_versions', [
            'opcrf_submission_id' => $submission->id,
            'version_number' => 1,
            'stored_path' => 'opcrf-submissions/'.$staff->id.'/original.xlsx',
        ]);

        // The resubmission step is on the trail, after the return.
        $this->assertDatabaseHas('opcrf_reviews', [
            'opcrf_submission_id' => $submission->id,
            'action' => 'resubmit',
            'previous_status' => OpcrfSubmission::STATUS_RETURNED,
            'new_status' => OpcrfSubmission::STATUS_RESUBMITTED,
        ]);

        // The original upload still exists on disk — nothing overwritten.
        Storage::disk('local')->assertExists('opcrf-submissions/'.$staff->id.'/original.xlsx');
    }

    public function test_the_returning_superadmin_sees_the_resubmission_with_its_history(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $admin->id]);

        $submission->returnForRevision($admin, 'Fix the accomplishments.');
        $submission->resubmit();

        // The returning superadmin's review modal shows the whole trail:
        // the return with its remarks, then the staff member's resubmit —
        // with the status transition.
        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee('Resubmitted for Review')
            ->assertSee('Resubmitted by: <strong>staff</strong>', false)
            ->assertSee('Fix the accomplishments.')
            ->assertSee('Status: Pending Review → Returned for Revision', false)
            ->assertSee('Status: Returned for Revision → Resubmitted for Review', false);
    }

    public function test_a_second_revision_archives_version_two(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $admin->id]);

        Storage::disk('local')->put('opcrf-submissions/'.$staff->id.'/v1.xlsx', 'one');
        $submission->update(['file_path' => 'opcrf-submissions/'.$staff->id.'/v1.xlsx']);

        $submission->returnForRevision($admin, 'Again.');

        // The revised workbook, as the revise modal would have stored it.
        Storage::disk('local')->put('opcrf-submissions/'.$staff->id.'/v2.xlsx', 'two');
        $submission->resubmit('opcrf-submissions/'.$staff->id.'/v2.xlsx', 'revised.xlsx');

        // Round two: another return + resubmission.
        $submission->returnForRevision($admin, 'Once more.');
        Storage::disk('local')->put('opcrf-submissions/'.$staff->id.'/v3.xlsx', 'three');
        $submission->resubmit('opcrf-submissions/'.$staff->id.'/v3.xlsx', 'final.xlsx');

        // Both replaced workbooks archived, in order.
        $versions = $submission->versions()->orderBy('version_number')->get();
        $this->assertCount(2, $versions);
        $this->assertSame('opcrf-submissions/'.$staff->id.'/v1.xlsx', $versions[0]->stored_path);
        $this->assertSame('opcrf-submissions/'.$staff->id.'/v2.xlsx', $versions[1]->stored_path);
        $this->assertSame('opcrf-submissions/'.$staff->id.'/v3.xlsx', $submission->file_path);

        // Nothing overwritten on disk.
        Storage::disk('local')->assertExists('opcrf-submissions/'.$staff->id.'/v1.xlsx');
        Storage::disk('local')->assertExists('opcrf-submissions/'.$staff->id.'/v2.xlsx');
        Storage::disk('local')->assertExists('opcrf-submissions/'.$staff->id.'/v3.xlsx');
    }

    public function test_a_staff_member_cannot_revise_someone_elses_submission(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $other = User::create([
            'name' => 'Other Staff',
            'username' => 'otherstaff',
            'email' => 'otherstaff@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);

        $submission = $this->createSubmission($staff, ['reviewer_id' => $admin->id]);
        $submission->returnForRevision($admin, 'Fix it.');

        // A 404, not a 403: a stranger cannot even learn that the
        // submission exists (same boundary as the review modal's open).
        Livewire::actingAs($other)
            ->test(OpcrfMovs::class)
            ->call('openRevise', $submission->id)
            ->assertNotFound();
    }

    public function test_resubmission_does_not_delete_the_original_submission_row(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $admin->id]);

        $submission->returnForRevision($admin, 'Revise.');
        $submission->resubmit();

        // The same row, same id, full trail intact: nothing recreated or
        // deleted.
        $this->assertDatabaseHas('opcrf_submissions', ['id' => $submission->id]);
        $this->assertSame(2, $submission->reviews()->count());
    }
}
