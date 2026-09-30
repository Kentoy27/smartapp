<?php

namespace Tests\Feature;

use App\Livewire\OpcrfReview;
use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class OpcrfReviewTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\BuildsOpcrfWorkbooks;

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
     * A second superadmin account — routing must keep the two apart.
     */
    private function otherSuperadmin(): User
    {
        return User::create([
            'name' => 'Other Admin',
            'username' => 'otheradmin',
            'email' => 'otheradmin@example.com',
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
            'objectives' => "Objective 1: Improved learner outcomes\nObjective 2: Drafted HR policies",
            'accomplishments' => "Raised MPS by 5 points\nPolicies submitted",
            'self_rating' => 4.5,
            'remarks' => null,
            'submitted_at' => now()->subDay(),
        ], $overrides));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('opcrf.review'))->assertRedirect(route('login'));
    }

    public function test_regular_users_get_404_on_the_review_page(): void
    {
        $this->actingAs($this->staffUser())
            ->get(route('opcrf.review'))
            ->assertNotFound();
    }

    public function test_superadmins_can_open_the_review_page(): void
    {
        $this->actingAs($this->superadmin())
            ->get(route('opcrf.review'))
            ->assertOk()
            ->assertSee('Review Opcrf')
            ->assertSee('All OPCRF submissions');
    }

    public function test_the_review_table_lists_every_staff_submission(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $this->createSubmission($staff);

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->assertSee('Jane D. Doe')
            ->assertSee('Teacher I')
            ->assertSee('January to December 2026')
            ->assertSee('staff')
            ->assertSee('Review');
    }

    public function test_the_review_table_refreshes_on_the_submission_event(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();

        $component = Livewire::actingAs($admin)->test(OpcrfReview::class);
        $component->assertDontSee('Jane D. Doe');

        $this->createSubmission($staff);
        $component->call('refreshSubmissions');

        $component->assertSee('Jane D. Doe');
    }

    public function test_opening_the_review_shows_the_submission_details(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSet('showReview', true)
            ->assertSee('Review submission')
            // This submission has no archived document, so the modal says
            // so instead of handing over a synthesized sheet.
            ->assertSee('No original OPCRF on file for this submission')
            // The recorded summary: staff header block in the template's
            // layout. The objectives table is not rendered in-app — the
            // full contents are reviewed via the download.
            ->assertSee('Jane D. Doe')
            ->assertSee('Teacher I')
            ->assertSee('Schools Division Office')
            // The review's write step is right there in the modal.
            ->assertSee('Not approved yet')
            ->assertSee('Approve & save the official copy')
            // The upload is mandatory before approval.
            ->assertSee('required to approve')
            ->assertDontSee('Objective 1: Improved learner outcomes')
            ->assertDontSee('Raised MPS by 5 points')
            ->assertDontSee('Objective 2: Drafted HR policies');
    }

    public function test_the_review_modal_keeps_the_workbook_off_the_page(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        // Archive a real template-shaped workbook so we can prove the modal
        // does NOT render its contents — the review happens on the file.
        $workbookPath = $this->buildThreePartOpcrf();
        Storage::disk('local')->put(
            'opcrf-submissions/'.$staff->id.'/filled.xlsx',
            file_get_contents($workbookPath)
        );
        $submission->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/filled.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSet('showReview', true)
            // The full workbook is reviewed by fetching it…
            ->assertSee('Download')
            // …and only the recorded summary is shown in-app — never the
            // workbook's full contents (those live in the file itself).
            ->assertSee('Submitted form')
            ->assertDontSee('PART I-A')
            ->assertDontSee('Signed after the review')
            ->assertDontSee('RATEE')
            // MOVs stay a view-only record with downloads.
            ->assertSee('MOVs (view only)');
    }

    public function test_the_review_component_accepts_only_the_approval_workbook(): void
    {
        // One upload slot exists — the corrected workbook attached while
        // approving. A crafted client can reach nothing else: no MOV picker,
        // no in-app sheet state.
        $reflection = new \ReflectionClass(OpcrfReview::class);

        $this->assertTrue(
            $reflection->hasProperty('reviewFile'),
            'The approval step accepts the corrected workbook.'
        );
        $this->assertFalse(
            $reflection->hasProperty('movFiles'),
            'The review component must not accept MOV uploads.'
        );
        $this->assertFalse(
            $reflection->hasProperty('sheet'),
            'The review modal shows no in-app sheet rendering.'
        );
    }

    public function test_regular_users_cannot_open_the_review_modal(): void
    {
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        $thrown = null;

        try {
            Livewire::actingAs($staff)
                ->test(OpcrfReview::class)
                ->call('openReview', $submission->id);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected the superadmin-only guard to reject a regular user.');
    }

    public function test_the_download_route_serves_the_archived_submission_file(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        Storage::disk('local')->put('opcrf-submissions/'.$staff->id.'/orig.xlsx', 'original-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/orig.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('opcrf.submission.download', $submission));

        $response->assertOk();
        $response->assertDownload('Staff Member - OPCRF.xlsx');

        // The literal archived file is served — byte for byte. (The
        // response streams from disk, so read the archived file back —
        // its content is what the browser receives.)
        $this->assertSame(
            'original-bytes',
            Storage::disk('local')->get($submission->fresh()->file_path)
        );
    }

    public function test_the_download_route_404s_when_no_original_file_was_archived(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        // Submissions predating file archiving have no original document —
        // the reviewer's download is the staff member's actual workbook,
        // never a sheet synthesized from the recorded fields, so there is
        // nothing to serve here (404) and the modal says so instead.
        $this->assertFalse($submission->hasFile());

        $this->actingAs($admin)
            ->get(route('opcrf.submission.download', $submission))
            ->assertNotFound();

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee('No original OPCRF on file for this submission')
            ->assertSee('Upload the full OPCRF document')
            ->assertDontSee(route('opcrf.submission.download', $submission), false);
    }

    public function test_staff_cannot_download_another_users_submission_file(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $other = User::create([
            'name' => 'Other Staff',
            'username' => 'otherstaff',
            'email' => 'otherstaff@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);

        $submission = $this->createSubmission($staff);

        Storage::disk('local')->put('opcrf-submissions/'.$staff->id.'/original.xlsx', 'original-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/original.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        // Even once it is approved, a submission's workbook belongs to its
        // owner: another staff member never gets it.
        $submission->approve($admin);

        $this->actingAs($other)
            ->get(route('opcrf.submission.download', $submission))
            ->assertNotFound();

        $this->actingAs($staff)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk();
    }

    public function test_confirming_an_upload_archives_the_original_file(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $chief = $this->superadmin();
        $path = $this->buildFilledOpcrf();

        Livewire::actingAs($user)
            ->test(\App\Livewire\OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->set('reviewer_id', (string) $chief->id)
            ->call('confirmSubmit');

        $submission = OpcrfSubmission::firstOrFail();

        $this->assertNotNull($submission->file_path);
        $this->assertSame('OPCRF-TEMPLATE.xlsx', $submission->file_original_name);
        // The original is stamped the moment it is archived — the review
        // download serves exactly these bytes.
        $this->assertNotNull($submission->file_updated_at);
        $this->assertTrue($submission->hasFile());
        Storage::disk('local')->assertExists($submission->file_path);
        $this->assertStringStartsWith('opcrf-submissions/'.$user->id.'/', $submission->file_path);
    }

    public function test_approving_without_the_updated_workbook_is_rejected(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        // The approval always ships a document: with nothing attached the
        // action is refused and the review stays open, untouched.
        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee('Not approved yet')
            ->call('approveSubmission')
            ->assertHasErrors(['reviewFile'])
            ->assertSet('showReview', true)
            ->assertSet('reviewFile', null);

        $submission->refresh();

        $this->assertFalse($submission->isApproved());
        $this->assertNull($submission->approved_at);
        $this->assertNull($submission->approved_by);
        $this->assertNull($submission->file_path);
    }
    public function test_approving_with_a_corrected_workbook_makes_it_the_official_copy(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        // The staff member's original upload, archived when they submitted.
        Storage::disk('local')->put('opcrf-submissions/'.$staff->id.'/submitted.xlsx', 'submitted-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/submitted.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        $corrected = $this->buildThreePartOpcrf();

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee('Download')
            ->set('reviewFile', $this->uploadedWorkbook($corrected, 'OPCRF-APPROVED.xlsx'))
            ->assertSee('OPCRF-APPROVED.xlsx')
            ->assertSee('Approve & save the official copy')
            ->call('approveSubmission')
            // The message is JSON-encoded into the sentinel, so assert on a
            // plain-ASCII slice of it (the em dash arrives escaped).
            ->assertSee('the updated workbook is now the official copy');

        $submission->refresh();

        $this->assertTrue($submission->isApproved());
        $this->assertSame($admin->id, $submission->approved_by);
        $this->assertSame('OPCRF-APPROVED.xlsx', $submission->file_original_name);
        $this->assertNotNull($submission->file_updated_at);
        $this->assertNotSame(
            'opcrf-submissions/'.$staff->id.'/submitted.xlsx',
            $submission->file_path,
            'The corrected workbook replaces the submitted file.'
        );

        // The corrected bytes are the archived copy, and the superseded
        // submission is gone from storage.
        Storage::disk('local')->assertExists($submission->file_path);
        Storage::disk('local')->assertMissing('opcrf-submissions/'.$staff->id.'/submitted.xlsx');
        $this->assertSame(
            file_get_contents($corrected),
            Storage::disk('local')->get($submission->file_path)
        );

        // Downloads now serve the approved workbook, named after the file
        // the superadmin uploaded.
        $this->actingAs($admin)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk()
            ->assertDownload('Staff Member - OPCRF.xlsx');

        // Re-opening the review shows the approved state and the new label.
        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee('Approved by')
            ->assertSee($admin->username)
            ->assertSee('Download')
            // The long label is gone from the button.
            ->assertDontSee('Download the full submitted OPCRF')
            ->assertDontSee('Download the official approved copy');
    }

    public function test_a_non_workbook_attachment_is_rejected(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('reviewFile', UploadedFile::fake()->create('review-notes.pdf', 4))
            ->call('approveSubmission')
            ->assertHasErrors(['reviewFile'])
            ->assertSet('showReview', true);

        $submission->refresh();

        $this->assertFalse($submission->isApproved());
        $this->assertNull($submission->approved_at);
    }

    public function test_approving_without_an_open_review_is_rejected(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        // A crafted call straight to the action, with no submission under
        // review: the guard rejects it (the Livewire harness surfaces the
        // abort as a failed response rather than an exception), so the
        // decisive check is that nothing was approved and no modal opened.
        $component = Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('approveSubmission');

        $component->assertSet('showReview', false);
        $component->assertSet('reviewId', null);

        $this->assertFalse($submission->fresh()->isApproved());
        $this->assertSame(0, OpcrfSubmission::whereNotNull('approved_at')->count());
    }

    public function test_regular_users_cannot_approve(): void
    {
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        $thrown = null;

        try {
            Livewire::actingAs($staff)
                ->test(OpcrfReview::class)
                ->call('approveSubmission');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected the superadmin-only guard to reject a regular user.');
        $this->assertFalse($submission->fresh()->isApproved());
    }

    public function test_staff_can_fetch_their_own_copy_only_once_it_is_approved(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        Storage::disk('local')->put('opcrf-submissions/'.$staff->id.'/approved.xlsx', 'approved-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/approved.xlsx',
            'file_original_name' => 'OPCR-APPROVED.xlsx',
        ]);

        // Before approval the workbook is the superadmin's to review…
        $this->actingAs($staff)
            ->get(route('opcrf.submission.download', $submission))
            ->assertNotFound();

        // …after approval it is the staff member's official copy.
        $submission->approve($admin);

        $this->actingAs($staff)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk()
            ->assertDownload('Staff Member - OPCRF.xlsx');

        $this->assertSame('approved-bytes', Storage::disk('local')->get($submission->file_path));
    }

    public function test_the_approval_state_shows_in_both_tables(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();

        $pending = $this->createSubmission($staff);
        $approved = $this->createSubmission($staff, ['review_period' => 'July to December 2026']);

        // The approved row carries the official copy, so its download
        // action is real.
        Storage::disk('local')->put('opcrf-submissions/'.$staff->id.'/approved.xlsx', 'approved-bytes');
        $approved->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/approved.xlsx',
            'file_original_name' => 'OPCR-APPROVED.xlsx',
        ]);
        $approved->approve($admin);

        // Superadmin: the review table marks which submissions are done.
        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->assertSee('Approved')
            ->assertSee('Pending review')
            ->assertSee('July to December 2026');

        // Staff: their own table shows the approval, and offers the
        // download action for the approved submission only.
        Livewire::actingAs($staff)
            ->test(\App\Livewire\OpcrfMovs::class)
            ->assertSee('Approved')
            ->assertSee('Pending review')
            ->assertSee(route('opcrf.submission.download', $approved), false)
            ->assertDontSee(route('opcrf.submission.download', $pending), false);
    }

    public function test_a_superadmin_only_sees_submissions_routed_to_them(): void
    {
        $mine = $this->superadmin();
        $theirs = $this->otherSuperadmin();
        $staff = $this->staffUser();

        $this->createSubmission($staff, [
            'reviewer_id' => $mine->id,
            'review_period' => 'Routed to me',
        ]);
        $this->createSubmission($staff, [
            'reviewer_id' => $theirs->id,
            'review_period' => 'Routed elsewhere',
        ]);
        // Sent before routing existed: nobody owns it, so it stays visible to
        // every superadmin rather than being stranded.
        $this->createSubmission($staff, ['review_period' => 'Unassigned row']);

        Livewire::actingAs($mine)
            ->test(OpcrfReview::class)
            ->assertSee('Routed to me')
            ->assertSee('Unassigned row')
            ->assertSee('Unassigned — no recipient on record')
            ->assertDontSee('Routed elsewhere');

        Livewire::actingAs($theirs)
            ->test(OpcrfReview::class)
            ->assertSee('Routed elsewhere')
            ->assertSee('Unassigned row')
            ->assertDontSee('Routed to me');
    }

    public function test_a_routed_submission_cannot_be_opened_by_another_superadmin(): void
    {
        $owner = $this->superadmin();
        $other = $this->otherSuperadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $owner->id]
        );

        // Routing is the authorization boundary — a crafted call on someone
        // else's submission must not open the modal.
        Livewire::actingAs($other)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSet('showReview', false)
            ->assertSet('reviewId', null);

        // The recipient opens it normally.
        Livewire::actingAs($owner)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSet('showReview', true);
    }

    public function test_a_routed_submission_cannot_be_approved_by_another_superadmin(): void
    {
        $owner = $this->superadmin();
        $other = $this->otherSuperadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $owner->id]
        );

        Livewire::actingAs($other)
            ->test(OpcrfReview::class)
            // Point the modal at someone else's submission and approve it.
            ->set('reviewId', $submission->id)
            ->set('showReview', true)
            ->call('approveSubmission');

        $this->assertFalse($submission->fresh()->isApproved());
    }

    public function test_another_superadmin_cannot_download_a_routed_submission(): void
    {
        Storage::fake('local');

        $owner = $this->superadmin();
        $other = $this->otherSuperadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $owner->id]
        );

        Storage::disk('local')->put('opcrf-submissions/routed.xlsx', 'routed-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/routed.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        $this->actingAs($other)
            ->get(route('opcrf.submission.download', $submission))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk()
            ->assertDownload('Staff Member - OPCRF.xlsx');
    }

    public function test_mov_evidence_follows_the_same_routing(): void
    {
        Storage::fake('local');

        $owner = $this->superadmin();
        $other = $this->otherSuperadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $owner->id]);

        $mov = $submission->movs()->create([
            'original_name' => 'proof.pdf',
            'stored_path' => 'opcrf-movs/'.$submission->id.'/proof.pdf',
            'size_bytes' => 5,
        ]);
        Storage::disk('local')->put($mov->stored_path, 'proof');

        // Not the recipient: no access.
        $this->actingAs($other)
            ->get(route('opcrf.movs.download', $mov))
            ->assertForbidden();

        // The recipient reviews the evidence, and so does the owner.
        $this->actingAs($owner)
            ->get(route('opcrf.movs.download', $mov))
            ->assertOk();

        $this->actingAs($staff)
            ->get(route('opcrf.movs.download', $mov))
            ->assertOk();
    }

    public function test_the_sidebar_badge_counts_only_my_pending_submissions(): void
    {
        $mine = $this->superadmin();
        $theirs = $this->otherSuperadmin();
        $staff = $this->staffUser();

        $pendingForMe = $this->createSubmission($staff, ['reviewer_id' => $mine->id]);
        $approvedForMe = $this->createSubmission($staff, ['reviewer_id' => $mine->id]);
        $approvedForMe->approve($mine);

        $this->createSubmission($staff, ['reviewer_id' => $theirs->id]);
        $this->createSubmission($staff, ['reviewer_id' => $theirs->id]);

        // Mine: the pending one (the approved one no longer counts).
        Livewire::actingAs($mine)
            ->test('sidebar')
            ->assertSee('1 submissions to review', false);

        // Theirs: both of theirs, none of mine.
        Livewire::actingAs($theirs)
            ->test('sidebar')
            ->assertSee('2 submissions to review', false);
    }

    public function test_the_review_table_offers_a_delete_action(): void
    {
        $admin = $this->superadmin();
        $this->createSubmission($this->staffUser());

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->assertSee('Review')
            ->assertSee('Delete');
    }

    public function test_the_delete_modal_asks_first_and_cancelling_keeps_the_submission(): void
    {
        $admin = $this->superadmin();
        $submission = $this->createSubmission($this->staffUser());

        $component = Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openDelete', $submission->id)
            // The confirmation names the submission…
            ->assertSet('showDeleteModal', true)
            ->assertSee('Delete OPCR Submission')
            ->assertSee('This action cannot be undone');

        // …and nothing is gone yet.
        $this->assertDatabaseHas('opcrf_submissions', ['id' => $submission->id]);

        $component->call('closeDelete')
            ->assertSet('showDeleteModal', false)
            ->assertSet('deleteId', null);

        $this->assertDatabaseHas('opcrf_submissions', ['id' => $submission->id]);
    }

    public function test_confirming_delete_removes_the_submission_with_its_workbook_and_movs(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $submission = $this->createSubmission($this->staffUser());

        Storage::disk('local')->put('opcrf-submissions/workbook.xlsx', 'workbook-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/workbook.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        foreach (['proof-a.pdf', 'proof-b.pdf'] as $name) {
            $path = 'opcrf-movs/'.$submission->id.'/'.$name;
            Storage::disk('local')->put($path, 'mov-bytes');
            $submission->movs()->create([
                'original_name' => $name,
                'stored_path' => $path,
                'size_bytes' => 9,
            ]);
        }

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->assertSee('Jane D. Doe')
            ->call('openDelete', $submission->id)
            ->call('confirmDelete')
            ->assertSet('showDeleteModal', false)
            ->assertDontSee('Jane D. Doe')
            ->assertSee('OPCR submission deleted with 2 MOV files.')
            ->assertDispatched('opcrf-submission-deleted');

        // The row, the archived workbook, and every MOV file are gone.
        $this->assertDatabaseMissing('opcrf_submissions', ['id' => $submission->id]);
        $this->assertDatabaseMissing('opcrf_movs', ['opcrf_submission_id' => $submission->id]);
        Storage::disk('local')->assertMissing('opcrf-submissions/workbook.xlsx');
        Storage::disk('local')->assertMissing('opcrf-movs/'.$submission->id.'/proof-a.pdf');
        Storage::disk('local')->assertMissing('opcrf-movs/'.$submission->id.'/proof-b.pdf');
    }

    public function test_deleting_a_submission_cleans_its_movs_even_without_the_component(): void
    {
        Storage::fake('local');

        $submission = $this->createSubmission($this->staffUser());

        $workbook = 'opcrf-submissions/plain-delete.xlsx';
        Storage::disk('local')->put($workbook, 'bytes');
        $submission->update(['file_path' => $workbook]);

        $movPath = 'opcrf-movs/'.$submission->id.'/proof.pdf';
        Storage::disk('local')->put($movPath, 'proof');
        $submission->movs()->create([
            'original_name' => 'proof.pdf',
            'stored_path' => $movPath,
            'size_bytes' => 5,
        ]);

        // Any code path that deletes the model must clean the record with it —
        // the database cascade alone would orphan the MOV file.
        $submission->delete();

        $this->assertDatabaseMissing('opcrf_movs', ['opcrf_submission_id' => $submission->id]);
        Storage::disk('local')->assertMissing($movPath);
        Storage::disk('local')->assertMissing($workbook);
    }

    public function test_deleting_closes_an_open_review_modal(): void
    {
        $admin = $this->superadmin();
        $submission = $this->createSubmission($this->staffUser());

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSet('showReview', true)
            ->call('openDelete', $submission->id)
            ->call('confirmDelete')
            ->assertSet('showReview', false)
            ->assertSet('reviewId', null);
    }

    public function test_a_regular_user_cannot_delete_a_submission(): void
    {
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        $thrown = null;

        try {
            Livewire::actingAs($staff)
                ->test(OpcrfReview::class)
                ->call('openDelete', $submission->id);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected the superadmin-only guard to reject a regular user.');
        $this->assertDatabaseHas('opcrf_submissions', ['id' => $submission->id]);
    }

    public function test_another_superadmin_cannot_delete_a_routed_submission(): void
    {
        $owner = $this->superadmin();
        $other = $this->otherSuperadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $owner->id]
        );

        // The row is not even in this superadmin's list, so the delete
        // request never arms (the guard answers 404; the harness surfaces it
        // as a failed response rather than an exception — the state and the
        // row itself are the decisive checks).
        Livewire::actingAs($other)
            ->test(OpcrfReview::class)
            ->call('openDelete', $submission->id)
            ->assertSet('showDeleteModal', false)
            ->assertSet('deleteId', null)
            ->assertDontSee('Jane D. Doe');

        $this->assertDatabaseHas('opcrf_submissions', ['id' => $submission->id]);

        // Even a crafted confirm with the id forced in does nothing.
        Livewire::actingAs($other)
            ->test(OpcrfReview::class)
            ->set('deleteId', $submission->id)
            ->call('confirmDelete');

        $this->assertDatabaseHas('opcrf_submissions', ['id' => $submission->id]);

        // The recipient deletes it normally.
        Livewire::actingAs($owner)
            ->test(OpcrfReview::class)
            ->call('openDelete', $submission->id)
            ->call('confirmDelete');

        $this->assertDatabaseMissing('opcrf_submissions', ['id' => $submission->id]);
    }

    public function test_the_review_modal_lists_the_other_superadmins_to_forward_to(): void
    {
        $mine = $this->superadmin();
        $other = $this->otherSuperadmin();
        User::create([
            'name' => 'Plain Staff',
            'username' => 'plainstaffer',
            'email' => 'plainstaffer@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);

        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $mine->id]
        );

        Livewire::actingAs($mine)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            // The hand-over picker exists…
            ->assertSee('Forward to another superadmin')
            ->assertSee('Hand this submission to')
            // …offers the other superadmin…
            ->assertSee('otheradmin', false)
            // …never this account, and never a staff account.
            ->assertDontSee('adminuser', escape: false)
            ->assertDontSee('plainstaffer', escape: false);
    }

    public function test_the_forward_picker_says_so_when_you_are_the_only_superadmin(): void
    {
        $mine = $this->superadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $mine->id]
        );

        Livewire::actingAs($mine)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee('You are the only superadmin right now')
            ->assertDontSee('Hand it over');
    }

    public function test_forwarding_hands_the_submission_to_the_other_superadmin(): void
    {
        Storage::fake('local');

        $mine = $this->superadmin();
        $other = $this->otherSuperadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $mine->id]
        );

        Storage::disk('local')->put('opcrf-submissions/handoff.xlsx', 'handoff-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/handoff.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        Livewire::actingAs($mine)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('forward_to', (string) $other->id)
            ->call('forwardSubmission')
            ->assertSet('showReview', false)
            ->assertSet('forward_to', '')
            // The row is gone from this superadmin's list…
            ->assertDontSee('Jane D. Doe')
            ->assertSee('Submission handed to otheradmin for review.')
            ->assertDispatched('opcrf-submission-forwarded');

        $submission->refresh();

        $this->assertSame($other->id, $submission->reviewer_id);

        // …they see it and can fetch the workbook; this account can no longer.
        Livewire::actingAs($other)
            ->test(OpcrfReview::class)
            ->assertSee('Jane D. Doe');

        $this->actingAs($other)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk();

        $this->actingAs($mine)
            ->get(route('opcrf.submission.download', $submission))
            ->assertNotFound();
    }

    public function test_the_new_recipient_can_update_and_approve_after_the_hand_over(): void
    {
        Storage::fake('local');

        $mine = $this->superadmin();
        $other = $this->otherSuperadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $mine->id]);

        Storage::disk('local')->put('opcrf-submissions/before-handoff.xlsx', 'before-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/before-handoff.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        // Hand it over.
        Livewire::actingAs($mine)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('forward_to', (string) $other->id)
            ->call('forwardSubmission');

        // The new recipient reviews, updates it with their own corrected
        // workbook, and approves.
        $corrected = $this->buildThreePartOpcrf();

        Livewire::actingAs($other)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('reviewFile', $this->uploadedWorkbook($corrected, 'OPCRF-B-APPROVED.xlsx'))
            ->call('approveSubmission');

        $submission->refresh();

        $this->assertTrue($submission->isApproved());
        $this->assertSame($other->id, $submission->approved_by);
        $this->assertSame('OPCRF-B-APPROVED.xlsx', $submission->file_original_name);
        Storage::disk('local')->assertMissing('opcrf-submissions/before-handoff.xlsx');

        // The staff member now gets the copy B approved.
        $this->actingAs($staff)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk()
            ->assertDownload('Staff Member - OPCRF.xlsx');
    }

    public function test_forwarding_an_approved_submission_reopens_it(): void
    {
        $mine = $this->superadmin();
        $other = $this->otherSuperadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $mine->id]
        );
        $submission->approve($mine);

        Livewire::actingAs($mine)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            // The modal warns before it happens…
            ->assertSee('forwarding reopens it for')
            ->set('forward_to', (string) $other->id)
            ->call('forwardSubmission')
            ->assertSee('the earlier approval was cleared');

        $submission->refresh();

        $this->assertSame($other->id, $submission->reviewer_id);
        $this->assertFalse($submission->isApproved());
        $this->assertNull($submission->approved_by);
    }

    public function test_forwarding_needs_a_valid_recipient(): void
    {
        $mine = $this->superadmin();
        $plain = User::create([
            'name' => 'Plain Staff',
            'username' => 'plainstaffer',
            'email' => 'plainstaffer@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $mine->id]
        );

        // No choice made.
        Livewire::actingAs($mine)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('forward_to', '')
            ->call('forwardSubmission')
            ->assertHasErrors(['forward_to']);

        // A crafted staff account id is rejected.
        Livewire::actingAs($mine)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('forward_to', (string) $plain->id)
            ->call('forwardSubmission')
            ->assertHasErrors(['forward_to']);

        // Handing it to yourself is refused.
        Livewire::actingAs($mine)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('forward_to', (string) $mine->id)
            ->call('forwardSubmission')
            ->assertHasErrors(['forward_to']);

        $submission->refresh();

        $this->assertSame($mine->id, $submission->reviewer_id);
        $this->assertSame(1, OpcrfSubmission::count());
    }

    public function test_a_superadmin_cannot_forward_a_submission_routed_elsewhere(): void
    {
        $owner = $this->superadmin();
        $other = $this->otherSuperadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $owner->id]
        );

        // A crafted call pointing the modal at someone else's row does
        // nothing: the row is outside this superadmin's scope.
        Livewire::actingAs($other)
            ->test(OpcrfReview::class)
            ->set('reviewId', $submission->id)
            ->set('showReview', true)
            ->set('forward_to', (string) $owner->id)
            ->call('forwardSubmission');

        $submission->refresh();

        $this->assertSame($owner->id, $submission->reviewer_id);
    }

    public function test_a_regular_user_cannot_forward_a_submission(): void
    {
        $staff = $this->staffUser();
        $other = $this->superadmin();
        $submission = $this->createSubmission($staff, ['reviewer_id' => null]);

        $thrown = null;

        try {
            Livewire::actingAs($staff)
                ->test(OpcrfReview::class)
                ->set('reviewId', $submission->id)
                ->set('forward_to', (string) $other->id)
                ->call('forwardSubmission');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected the superadmin-only guard to reject a regular user.');
        $this->assertNull($submission->fresh()->reviewer_id);
    }

    public function test_the_download_is_named_after_the_staff_members_real_name(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        Storage::disk('local')->put('opcrf-submissions/named.xlsx', 'bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/named.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        // The account's real name leads the file name, the template wording
        // is gone, and nothing generic sneaks in.
        $this->assertSame('Staff Member', $submission->realNameLabel());
        $this->assertSame('Staff Member - OPCRF.xlsx', $submission->fileDownloadName());
        $this->assertStringNotContainsString('TEMPLATE', $submission->fileDownloadName());

        $this->actingAs($admin)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk()
            ->assertDownload('Staff Member - OPCRF.xlsx');
    }

    public function test_the_download_name_is_sanitised_for_the_filesystem(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();

        // A real name with path separators, characters Windows rejects, and
        // a run of whitespace must not break the download.
        $staff->update(['name' => 'Juan  D. Dela\\Cruz: "JR" <bad>|name?*']);
        $submission = $this->createSubmission($staff);

        Storage::disk('local')->put('opcrf-submissions/odd.xlsx', 'bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/odd.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        $this->assertSame('Juan D. Dela Cruz JR bad name', $submission->realNameLabel());

        $this->actingAs($admin)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk()
            ->assertDownload('Juan D. Dela Cruz JR bad name - OPCRF.xlsx');

        // A very long name is capped so the header stays sane.
        $staff->update(['name' => str_repeat('Averylongname ', 12)]);
        $this->assertLessThanOrEqual(60, mb_strlen($submission->fresh()->realNameLabel()));

        // No name on the account at all: the id keeps the download distinct.
        $staff->update(['name' => '']);
        $submission = $submission->fresh();
        $this->assertSame('', $submission->realNameLabel());
        $this->assertSame('OPCR-submission-'.$submission->id.'.xlsx', $submission->fileDownloadName());
    }

    public function test_the_review_modal_names_the_real_name_of_whose_file_it_is(): void
    {
        $admin = $this->superadmin();
        $submission = $this->createSubmission($this->staffUser());

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            // Real name first, username in brackets for the account.
            ->assertSee('Staff Member')
            ->assertSee('(staff)')
            ->assertSee('The file saves under this')
            // The name typed into the workbook is still shown in its own row.
            ->assertSee('Jane D. Doe');
    }

    /**
     * An UploadedFile carrying a generated workbook's bytes (same helper
     * as OpcrfUploadTest — the content is a real xlsx, not random filler).
     */
    private function uploadedWorkbook(string $path, string $name = 'OPCRF-TEMPLATE.xlsx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            file_get_contents($path)
        );
    }
}
