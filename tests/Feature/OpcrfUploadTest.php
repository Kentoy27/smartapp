<?php

namespace Tests\Feature;

use App\Livewire\OpcrfUpload;
use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOpcrfWorkbooks;
use Tests\TestCase;

class OpcrfUploadTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOpcrfWorkbooks;

    /**
     * The superadmin submissions are routed to by default (the picker
     * pre-selects the first superadmin account).
     */
    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        // Confirming a submission archives the staff member's original
        // workbook on the local disk. Fake it for the whole class so the
        // suite never writes into the real storage/app tree (it used to
        // leave dozens of stray copies under opcrf-submissions/1).
        Storage::fake('local');

        // A confirmed OPCR is always routed to a superadmin the staff
        // member picks, so the class starts with one recipient available.
        $this->reviewer = User::create([
            'name' => 'Chief',
            'username' => 'chief',
            'email' => 'chief@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
    }

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

    /**
     * An UploadedFile carrying a generated workbook's bytes (same class as
     * UploadedFile::fake()->create(), so Livewire's upload simulation
     * works; the content is the real xlsx, not random filler).
     */
    private function uploadedWorkbook(string $path): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'OPCRF-TEMPLATE.xlsx',
            file_get_contents($path)
        );
    }

    public function test_uploading_a_filled_opcr_opens_the_review_modal_with_analyzed_details(): void
    {
        $user = $this->staffUser();
        $path = $this->buildFilledOpcrf();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            // The upload window hands over to the review modal.
            ->assertSet('showUpload', false)
            ->assertSet('showReview', true)
            ->assertSee('Review before submitting')
            // Every analyzed detail is visible in the modal.
            ->assertSee('Jane D. Doe')
            ->assertSee('Teacher I')
            ->assertSee('January to December 2026')
            ->assertSee('Schools Division Office')
            ->assertSee('Objective 1: Improved learner outcomes')
            ->assertSee('Raised MPS by 5 points')
            ->assertSee('4.50');

        // Analysis alone must not store anything.
        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_confirming_the_review_persists_the_analyzed_submission(): void
    {
        $user = $this->staffUser();
        $path = $this->buildFilledOpcrf();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->call('confirmSubmit')
            ->assertSet('showReview', false)
            ->assertSet('showUpload', false)
            // The submissions table below refreshes itself on this event.
            ->assertDispatched('opcrf-submission-created')
            ->assertSee('OPCR uploaded');

        $submission = OpcrfSubmission::firstOrFail();

        $this->assertSame($user->id, $submission->user_id);
        // Routed to the superadmin the picker had selected.
        $this->assertSame($this->reviewer->id, $submission->reviewer_id);
        $this->assertSame('Jane D. Doe', $submission->employee_name);
        $this->assertSame('Teacher I', $submission->position);
        $this->assertSame('January to December 2026', $submission->review_period);
        $this->assertSame(4.5, $submission->self_rating);
        $this->assertNotNull($submission->submitted_at);
    }

    public function test_the_review_modal_offers_the_superadmins_to_send_to(): void
    {
        $user = $this->staffUser();
        $second = User::create([
            'name' => 'Second Chief',
            'username' => 'chief2',
            'email' => 'chief2@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
        $plain = User::create([
            'name' => 'Plain Staff',
            'username' => 'plainstaffer',
            'email' => 'plainstaffer@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($this->buildFilledOpcrf()))
            // The picker names every superadmin account…
            ->assertSee('Send this OPCR to (superadmin)')
            ->assertSee('chief2', false)
            ->assertSee('Second Chief', false)
            // …and never a regular account.
            ->assertDontSee('plainstaffer', escape: false);
    }

    public function test_the_picker_preselects_the_first_superadmin_and_honours_a_change(): void
    {
        $user = $this->staffUser();
        $second = User::create([
            'name' => 'Second Chief',
            'username' => 'chief2',
            'email' => 'chief2@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);

        // Ordered by username: 'chief' comes before 'chief2'.
        $this->assertTrue('chief' < 'chief2');

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->call('openUpload')
            ->assertSet('reviewer_id', (string) $this->reviewer->id)
            ->set('file', $this->uploadedWorkbook($this->buildFilledOpcrf()))
            ->set('reviewer_id', (string) $second->id)
            ->call('confirmSubmit');

        $this->assertSame($second->id, OpcrfSubmission::firstOrFail()->reviewer_id);
    }

    public function test_a_staff_account_cannot_be_the_recipient(): void
    {
        $user = $this->staffUser();
        $other = User::create([
            'name' => 'Plain Staff',
            'username' => 'plainstaffer',
            'email' => 'plainstaffer@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($this->buildFilledOpcrf()))
            // A crafted client sending a staff id is rejected server-side.
            ->set('reviewer_id', (string) $other->id)
            ->call('confirmSubmit')
            ->assertHasErrors(['reviewer_id']);

        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_submitting_without_a_recipient_is_rejected(): void
    {
        $user = $this->staffUser();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($this->buildFilledOpcrf()))
            ->set('reviewer_id', '')
            ->call('confirmSubmit')
            ->assertHasErrors(['reviewer_id']);

        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_submitting_is_blocked_when_no_superadmin_exists(): void
    {
        $user = $this->staffUser();

        $this->reviewer->forceDelete();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($this->buildFilledOpcrf()))
            ->call('openUpload')
            ->assertSet('reviewer_id', '')
            ->assertSee('No superadmin account exists yet')
            ->call('confirmSubmit')
            ->assertHasErrors(['reviewer_id']);

        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_cancelling_the_review_saves_nothing(): void
    {
        $user = $this->staffUser();
        $path = $this->buildFilledOpcrf();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->call('cancelReview')
            ->assertSet('showReview', false)
            ->assertSet('analyzed', false);

        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_a_non_xlsx_file_is_rejected_before_analysis(): void
    {
        $user = $this->staffUser();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', UploadedFile::fake()->create('notes.pdf', 100))
            ->assertSet('showReview', false)
            ->assertHasErrors(['file']);

        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_an_empty_template_is_approved_and_shown_for_review(): void
    {
        $user = $this->staffUser();
        // The shipped template's first page: header labels present, left
        // value band untouched, but the evaluator block on the right
        // pre-fills the evaluator's own name in O4. Uploading it is
        // approved: the review modal opens with all header fields blank
        // (shown as dashes), ready to confirm.
        $path = $this->buildOpcrfWorkbook([
            'B4' => 'Name of Employee:',
            'B5' => 'Position/Designation:',
            'B6' => 'Review Period:',
            'B7' => 'Strand/Bureau/Center/Service/Region/Division:',
            'O4' => 'EVA M. DOLLOSA RN',
            'N5' => 'Position:',
            'O5' => 'OIC-Asst. Schools Division Superintendent',
            'N6' => 'Approving Authority:',
            'O6' => 'FERDINAND S. SY PhD, CESO VI',
            'N7' => 'Date of Review:',
        ]);

        $component = Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->assertSet('showUpload', false)
            ->assertSet('showReview', true)
            ->assertSee('Review before submitting');

        // Nothing from the evaluator block leaks into the staff fields.
        $this->assertSame('', $component->get('employee_name'));
        $this->assertSame('', $component->get('position'));
        $this->assertSame('', $component->get('review_period'));
        $this->assertSame('', $component->get('division_office'));
        $this->assertSame('', $component->get('self_rating'));
    }

    public function test_the_pre_filled_evaluator_name_is_never_taken_as_the_employee_name(): void
    {
        $user = $this->staffUser();
        // Regression: the untouched template ships with the evaluator's
        // name in O4 (merged O4:V4). Uploading it used to analyze that as
        // "Name of Employee" even though the staff member's own field was
        // empty. The analyzer must only read the left value band (C..M).
        $path = $this->buildOpcrfWorkbook([
            'B4' => 'Name of Employee:',
            'O4' => 'EVA M. DOLLOSA RN',
            'B5' => 'Position/Designation:',
            'N5' => 'Position:',
            'O5' => 'OIC-Asst. Schools Division Superintendent',
            'N6' => 'Approving Authority:',
            'O6' => 'FERDINAND S. SY PhD, CESO VI',
            'N7' => 'Date of Review:',
            'F16' => 'Objective 1: Drafted HR policies',
            'S16' => 'Policies submitted',
            'T16' => 5,
        ]);

        $component = Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path));

        $this->assertSame('', (string) $component->get('employee_name'));
        $this->assertStringNotContainsString('DOLLOSA', (string) $component->get('employee_name'));
        $this->assertSame('5', (string) $component->get('self_rating'));
    }

    public function test_a_fullname_typed_in_the_left_band_is_recognized_over_the_evaluator_block(): void
    {
        $user = $this->staffUser();
        // The staff member filled in their own name on the template's
        // first page (left value band F4, merged F4:M4) while the
        // evaluator block on the right still carries the evaluator's
        // pre-filled name — the employee's name must win.
        $path = $this->buildFilledOpcrf();

        $component = Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path));

        $this->assertSame('Jane D. Doe', $component->get('employee_name'));
        $this->assertSame('Teacher I', $component->get('position'));
    }

    public function test_the_review_modal_shows_the_evaluator_block_beside_the_staff_details(): void
    {
        $user = $this->staffUser();
        $path = $this->buildFilledOpcrf();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->assertSet('showReview', true)
            // The evaluator block (right side of the template's first
            // page) is rendered beside the staff details — evaluator
            // name, their position, approving authority.
            ->assertSee('Evaluator:')
            ->assertSee('EVA M. DOLLOSA RN')
            ->assertSee('Position:')
            ->assertSee('OIC-Asst. Schools Division Superintendent')
            ->assertSee('Approving Authority:')
            ->assertSee('FERDINAND S. SY PhD, CESO VI')
            // The staff member's own details stay on the left.
            ->assertSee('Jane D. Doe')
            ->assertSee('Teacher I');
    }

    public function test_the_review_modal_shows_all_three_parts_with_totals_and_the_signers(): void
    {
        $user = $this->staffUser();
        $path = $this->buildThreePartOpcrf();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->assertSet('showReview', true)
            // Part banners, one table per part.
            ->assertSee('PART I-A')
            ->assertSee('COMMITMENT TO ORGANIZATIONAL OUTCOMES (60%)')
            ->assertSee('PART I-B')
            ->assertSee('INNOVATING AND INTERVENING ACCOMPLISHMENTS (20%)')
            ->assertSee('PART I-C')
            ->assertSee('ORGANIZATIONAL EFFECTIVENESS (15%)')
            // Each part's objective + accomplishment landed in its own table.
            ->assertSee('Objective 1: Improved learner outcomes')
            ->assertSee('Raised MPS by 5 points')
            ->assertSee('Objective: Conducted innovations and interventions')
            ->assertSee('Objective: Utilized budget allocation')
            // Total-score rows with the values from the workbook.
            ->assertSee('PART I-A TOTAL SCORE')
            ->assertSee('PART I-B TOTAL SCORE')
            ->assertSee('PART I-C TOTAL SCORE')
            ->assertSee('2.00')
            // The signer block at the end.
            ->assertSee('Signed after the review')
            ->assertSee('EVA M. DOLLOSA RN')
            ->assertSee('FERDINAND S. SY PhD, CESO VI')
            ->assertSee('RATEE')
            ->assertSee('RATER')
            ->assertSee('APPROVING AUTHORITY');
    }

    public function test_a_workbook_with_no_ratings_still_confirms_with_a_zero_rating(): void
    {
        $user = $this->staffUser();
        // Header fields filled (left value band, as in the real template)
        // but no ratings anywhere → the confirm still passes, recording
        // self_rating as 0.00 (shown as "—" in the submissions table).
        $path = $this->buildOpcrfWorkbook([
            'B4' => 'Name of Employee:',
            'F4' => 'Jane D. Doe',
            'B5' => 'Position/Designation: ',
            'F5' => 'Teacher I',
            'B6' => 'Review Period:   ',
            'F6' => 'January to December 2026',
            'B7' => 'Strand/Bureau/Center/Service/Region/Division:  ',
            'F7' => 'Schools Division Office',
            'F16' => 'Objective 1',
            'S16' => 'Did the thing',
        ]);

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->assertSet('showReview', true)
            ->call('confirmSubmit')
            ->assertHasNoErrors()
            ->assertSee('OPCR uploaded');

        $submission = OpcrfSubmission::firstOrFail();
        $this->assertSame('Jane D. Doe', $submission->employee_name);
        $this->assertSame(0.0, $submission->self_rating);
    }

    public function test_a_completely_empty_workbook_still_confirms_with_blank_fields(): void
    {
        $user = $this->staffUser();
        // Even a workbook with nothing at all can be approved — blanks
        // simply record as empty strings and a 0.00 rating.
        $path = $this->buildOpcrfWorkbook([]);

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->assertSet('showReview', true)
            ->call('confirmSubmit')
            ->assertHasNoErrors();

        $submission = OpcrfSubmission::firstOrFail();
        $this->assertSame('', $submission->employee_name);
        $this->assertSame('', $submission->position);
        $this->assertSame(0.0, $submission->self_rating);
    }

    public function test_superadmins_are_blocked_from_uploading(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'adminuser@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);

        $thrown = null;
        try {
            Livewire::actingAs($admin)
                ->test(OpcrfUpload::class)
                ->set('file', $this->uploadedWorkbook($this->buildFilledOpcrf()));
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected the staff-only guard to reject a superadmin.');
        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_the_upload_button_sits_beside_download_on_the_template_card(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('OPCRF Template')
            ->assertSee('Download')
            ->assertSee('Upload')
            // The upload window itself stays closed until the button is clicked.
            ->assertDontSee('Upload your OPCR in here')
            ->assertDontSee('Click to choose your OPCR file');
    }

    public function test_the_upload_button_opens_the_upload_window(): void
    {
        $user = $this->staffUser();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->assertSet('showUpload', false)
            ->call('openUpload')
            ->assertSet('showUpload', true)
            ->assertSee('Upload your OPCR in here')
            ->assertSee('Click to choose your OPCRF file')
            ->assertDontSee('Review before submitting');
    }

    public function test_closing_the_upload_window_saves_nothing(): void
    {
        $user = $this->staffUser();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->call('openUpload')
            ->call('closeUpload')
            ->assertSet('showUpload', false)
            ->assertSet('file', null)
            ->assertDontSee('Upload your OPCR in here');

        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_a_rejected_file_keeps_the_upload_window_open_with_the_error(): void
    {
        $user = $this->staffUser();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->call('openUpload')
            ->set('file', UploadedFile::fake()->create('notes.pdf', 100))
            ->assertSet('showUpload', true)
            ->assertSet('showReview', false)
            ->assertHasErrors(['file'])
            ->assertSee('Upload your OPCR in here');

        $this->assertDatabaseCount('opcrf_submissions', 0);
    }

    public function test_superadmins_do_not_see_the_upload_button(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'adminuser@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Upload your OPCR in here')
            ->assertDontSee('OPCRF-TEMPLATE.xlsx');
    }

    public function test_confirming_the_review_opens_the_locked_movs_window(): void
    {
        $user = $this->staffUser();
        $path = $this->buildFilledOpcrf();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->call('confirmSubmit')
            // The locked "Upload your MOVs" window pops up right away.
            ->assertSet('showMovsModal', true)
            ->assertSee('Upload your MOVs')
            ->assertSee('Required')
            ->assertSee('This window is locked until you upload at least one MOV')
            ->assertSee('Upload at least 1 MOV to continue')
            ->assertSee('January to December 2026');

        $submission = OpcrfSubmission::firstOrFail();
        $this->assertSame($user->id, $submission->user_id);
    }

    public function test_the_locked_movs_window_cannot_be_closed_without_a_mov(): void
    {
        $user = $this->staffUser();
        $path = $this->buildFilledOpcrf();

        // Continue without any MOV: the lock holds and an error explains why.
        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->call('confirmSubmit')
            ->call('finishMovs')
            ->assertSet('showMovsModal', true)
            ->assertHasErrors(['movFiles'])
            ->assertSee('Attach at least one MOV file to continue.');

        $this->assertSame(1, OpcrfSubmission::count());
    }

    public function test_uploading_a_mov_inside_the_locked_window_persists_it_and_unlocks(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $path = $this->buildFilledOpcrf();

        $component = Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->call('confirmSubmit')
            ->set('movFiles', [
                UploadedFile::fake()->createWithContent('ACR-buwan-ng-wika.pdf', '%PDF-1.4 test'),
            ])
            ->assertSee('1 MOV file attached to your OPCR.')
            ->assertDontSee('Upload at least 1 MOV to continue');

        $submission = OpcrfSubmission::firstOrFail();
        $this->assertSame(1, $submission->movs()->count());

        $mov = $submission->movs()->first();
        $this->assertSame('ACR-buwan-ng-wika.pdf', $mov->original_name);
        Storage::disk('local')->assertExists($mov->stored_path);
        $this->assertStringStartsWith('opcrf-movs/'.$submission->id.'/', $mov->stored_path);

        // The requirement is satisfied: Continue releases the lock and
        // returns to the dashboard, whose OPCRF Template card re-renders
        // locked (the submission now carries its MOVs).
        $component->call('finishMovs')
            ->assertSet('showMovsModal', false)
            ->assertRedirect(route('home'));

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('OPCRF submitted — locked')
            ->assertDontSee('Click to choose your OPCRF file');
    }

    public function test_removing_the_last_mov_locks_the_window_again(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $path = $this->buildFilledOpcrf();

        $component = Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->call('confirmSubmit')
            ->set('movFiles', [
                UploadedFile::fake()->createWithContent('proof.pdf', '%PDF-1.4 test'),
            ])
            ->assertSee('1 MOV file attached to your OPCR.');

        $movId = OpcrfSubmission::firstOrFail()->movs()->first()->id;

        // Removing the only MOV re-locks the window.
        $component->call('removeMov', $movId)
            ->assertSee('Removed proof.pdf')
            ->assertSee('Upload at least 1 MOV to continue')
            ->call('finishMovs')
            ->assertSet('showMovsModal', true)
            ->assertHasErrors(['movFiles']);

        $this->assertSame(0, OpcrfSubmission::firstOrFail()->movs()->count());
    }

    public function test_the_locked_window_rejects_disallowed_mov_types(): void
    {
        Storage::fake('local');

        $user = $this->staffUser();
        $path = $this->buildFilledOpcrf();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
            ->call('confirmSubmit')
            ->set('movFiles', [UploadedFile::fake()->create('script.exe', 10)])
            ->assertHasErrors(['movFiles.*'])
            ->assertSet('showMovsModal', true);

        $this->assertSame(0, OpcrfSubmission::firstOrFail()->movs()->count());
    }
}
