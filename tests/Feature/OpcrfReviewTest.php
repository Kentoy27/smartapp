<?php

namespace Tests\Feature;

use App\Livewire\OpcrfMovs;
use App\Livewire\OpcrfReview;
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

class OpcrfReviewTest extends TestCase
{
    use BuildsOpcrfWorkbooks;
    use RefreshDatabase;

    private function staffUser(string $name = 'Staff Member'): User
    {
        return User::create([
            'name' => $name,
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
            // This submission has no archived document, so the modal falls
            // back to the recorded summary — never a synthesized sheet.
            ->assertSee('Submitted form')
            // The recorded summary: staff header block in the template's
            // layout, shown because there is no workbook to render.
            ->assertSee('Jane D. Doe')
            ->assertSee('Teacher I')
            ->assertSee('Schools Division Office')
            // The review's write steps are right there in the modal —
            // remarks, compliance, return — and no upload anywhere. Routing
            // is automatic: there is no manual forwarding step.
            ->assertSee('Review Decision')
            ->assertSee('Approve / Compliance')
            ->assertSee('Return for Revision')
            ->assertSee('Review History')
            ->assertSee('No review activity yet')
            ->assertDontSee('Objective 1: Improved learner outcomes')
            ->assertDontSee('Raised MPS by 5 points')
            ->assertDontSee('Objective 2: Drafted HR policies')
            // The modal no longer offers a Download button — the whole
            // workbook is reviewed in-app.
            ->assertDontSee('>Download</span>', false);
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
            // No Download action in the modal — everything is reviewed
            // in-app, and the workbook's FULL contents are rendered as the
            // Excel-style sheet: every part, objective, accomplishment,
            // rating, total and signer.
            ->assertDontSee('>Download</span>', false)
            ->assertSee('Submitted form')
            ->assertSee('PART I-A')
            ->assertSee('PART I-B')
            ->assertSee('PART I-C')
            ->assertSee('Objective 1: Improved learner outcomes')
            ->assertSee('Raised MPS by 5 points')
            ->assertSee('Objective: Utilized budget allocation')
            ->assertSee('Liquidation reports submitted')
            ->assertSee('Signed after the review')
            ->assertSee('RATEE')
            // MOVs are gone from the review modal entirely.
            ->assertDontSee('MOVs');
    }

    public function test_the_review_shows_an_empty_field_as_empty_instead_of_inventing_one(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, [
            'employee_name' => '',
            'position' => '',
            'review_period' => '',
            'division_office' => '',
        ]);
        // A workbook whose fields are genuinely empty: no employee name,
        // no timeline, no accomplishments and no ratings typed anywhere.
        Storage::disk('local')->put(
            'opcrf-submissions/'.$staff->id.'/blank.xlsx',
            file_get_contents($this->buildThreePartOpcrf([
                'F4' => '',
                'H16' => '',
                'S16' => '',
                'T16' => '',
            ]))
        );
        $submission->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/blank.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertOk()
            // The heading falls back to the account the submission belongs
            // to…
            ->assertSee($staff->username, false)
            // …while the form's own field rows report what it carries. A
            // blank cell reads as the sheet's em-dash placeholder, styled
            // .is-empty, exactly as it did on the submitter's screen.
            ->assertSee('class="opcrf-sheet-headvalue is-empty"', false)
            ->assertSee('<td class="opcrf-sheet-rate">—</td>', false)
            // And nothing is filled in that the upload does not carry.
            ->assertDontSee('January to December 2024');
    }

    public function test_the_review_reads_the_parts_own_column_layout(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        // The shipped header bands: Part I-C keeps its Timeline in column
        // J and its Weight in K, and has no Performance Targets columns at
        // all. Reading the band is what stops its weight being shown as a
        // performance target.
        Storage::disk('local')->put(
            'opcrf-submissions/'.$staff->id.'/real.xlsx',
            file_get_contents($this->buildThreePartOpcrf([
                'B12' => 'TO BE ACCOMPLISHED DURING PLANNING',
                'S12' => 'TO BE FILLED DURING EVALUATION',
                'B14' => 'Key Result Areas (KRA) (Based on Office Mandate and Functions)',
                'F13' => 'Objectives (based on Office Functions)',
                'H13' => 'Timeline',
                'I13' => 'Weight Allocation',
                'L13' => 'Performance Measure (Quality, Efficiency, Timeliness)',
                'R13' => 'Means of Verification (MOVs)',
                'S13' => 'Actual Accomplishments',
                'T13' => 'RATING (Q,E,T)',
                'B116' => 'TO BE FILLED IN DURING PLANNING',
                'B117' => 'Organizational Effectiveness Area',
                'F117' => 'Objectives',
                'J117' => 'Timeline',
                'K117' => 'Weight Allocation',
                'L117' => 'Performance Measure (Quality, Efficiency, Timeliness)',
                'R117' => 'Means of Verification (MOVs)',
                'S117' => 'Actual Results/ Accomplishments',
                'T117' => 'RATING (Q,E,T)',
                'J119' => 'Quarterly disbursement',
                'K119' => '0.05',
            ]))
        );
        $submission->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/real.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertOk()
            // The template's own band captions head the table, in the
            // template's own wording.
            ->assertSee('TO BE ACCOMPLISHED DURING PLANNING')
            ->assertSee('TO BE FILLED DURING EVALUATION')
            // Part I-C's own wording for its left-hand column…
            ->assertSee('Effectiveness Area')
            // …and its Timeline and Weight read from J and K.
            ->assertSee('Quarterly disbursement')
            ->assertSee('0.05');
    }

    public function test_the_review_modal_renders_every_tab_of_the_workbook(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        // A full four-tab workbook: the review shows all of it, not just
        // the PART I objectives sheet.
        Storage::disk('local')->put(
            'opcrf-submissions/'.$staff->id.'/full.xlsx',
            file_get_contents($this->buildFullOpcrfWorkbook())
        );
        $submission->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/full.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSet('showReview', true)
            // PART I (the main partial)…
            ->assertSee('PART I-A')
            ->assertSee('Raised MPS by 5 points')
            // …PART II (competencies), behind its own sheet tab…
            ->assertSee('PART II-A')
            ->assertSee('LEADERSHIP COMPETENCIES (2.5%)')
            ->assertSee('Leading People')
            ->assertSee('1. Uses basic persuasion techniques in a discussion.')
            ->assertSee('Part II-A Total Score: Weighted Average (Average x 0.025)')
            // …PART III (rating summary + agreement)…
            ->assertSee('PART III: SUMMARY OF RATINGS')
            ->assertSee('A.  Commitment to Organizational Outcomes')
            ->assertSee('JUAN DELA CRUZ')
            // …and PART IV (improvement + development plans).
            ->assertSee('PART IV: IMPROVEMENT AND DEVELOPMENT PLANS')
            ->assertSee('Weak ICT infrastructure')
            ->assertSee('Procure tablets and offline content')
            ->assertSee('Strong classroom management')
            ->assertSee('Plans are achievable within the rating period.');
    }

    /**
     * The reviewer reads the submission through the IDENTICAL partial the
     * submitter saw on their own screen — the same Excel-window component,
     * the same sheet partial, the same tab strip. Nothing can be approved in
     * review that was not visible at submission, and the two views cannot
     * quietly drift apart again.
     */
    public function test_the_review_renders_the_workbook_exactly_as_the_upload_review_did(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff);

        // buildThreePartOpcrf() returns a staged PATH; read the bytes once
        // and hand the identical workbook to both sides of the comparison.
        $bytes = file_get_contents($this->buildThreePartOpcrf());

        // The same bytes, archived as a submission…
        Storage::disk('local')->put(
            'opcrf-submissions/'.$staff->id.'/same.xlsx',
            $bytes
        );
        $submission->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/same.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        $reviewHtml = Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->html();

        // …and picked as an upload by the same staff member.
        $uploadHtml = Livewire::actingAs($staff)
            ->test(OpcrfUpload::class)
            ->call('openUpload')
            ->set('file', UploadedFile::fake()->createWithContent('OPCRF-TEMPLATE.xlsx', $bytes))
            ->html();

        // Both render the workbook through the one shared sheet component.
        foreach ([$reviewHtml, $uploadHtml] as $html) {
            $this->assertStringContainsString('opcrf-excelwin', $html);
            $this->assertStringContainsString('opcrf-excelwin-tabs', $html);
            $this->assertStringContainsString('data-sheet="part1"', $html);
        }

        // The long-form document renderer — the review modal's own previous
        // presentation — is gone; the reviewer sees the sheet, not an essay.
        $this->assertStringNotContainsString('opcrf-doc', $reviewHtml);

        // And the sheet body itself is identical between the two modals:
        // everything inside the shared component, from the title banner
        // down to the signer block. wire:key is dropped first — it exists
        // only to namespace DOM ids per modal instance, and the two are
        // deliberately suffixed differently.
        $sheetOf = function (string $html): string {
            $start = strpos($html, '<div class="opcrf-sheet">');
            $end = strpos($html, '<div class="opcrf-excelwin-tabs"');
            $sheet = substr($html, $start, $end - $start);

            return preg_replace('/ wire:key="[^"]*"/', '', $sheet);
        };

        $this->assertNotSame('', $sheetOf($reviewHtml), 'The review modal renders a sheet body.');
        $this->assertSame($sheetOf($uploadHtml), $sheetOf($reviewHtml));
    }

    public function test_the_review_accepts_no_uploads_at_all(): void
    {
        // The review is remark-and-route only: the component holds no file
        // state at all — no corrected-workbook slot, no MOV picker, no
        // writable sheet state (the read-only sheet is a computed property
        // derived from the archived file, not client state).
        $reflection = new \ReflectionClass(OpcrfReview::class);

        $this->assertFalse(
            $reflection->hasProperty('reviewFile'),
            'The review requires no workbook upload — the submitted file is the record.'
        );
        $this->assertFalse(
            $reflection->hasProperty('movFiles'),
            'The review component must not accept MOV uploads.'
        );
        $this->assertFalse(
            $reflection->hasProperty('sheet'),
            'The review modal holds no writable sheet state.'
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
        $submission = $this->createSubmission($staff);        // Submissions predating file archiving have no original document —
        // the route 404s (the reviewer's download is the staff member's
        // actual workbook, never a synthesized one), and the modal falls
        // back to the recorded summary without any download link.
        $this->assertFalse($submission->hasFile());

        $this->actingAs($admin)
            ->get(route('opcrf.submission.download', $submission))
            ->assertNotFound();

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee('Submitted form')
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
        $submission->markCompliant($admin);

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

        // Registered as the name the filled fixture carries: an upload is
        // only accepted when the workbook's own name matches the account.
        $user = $this->staffUser('Jane D. Doe');
        $chief = $this->superadmin();
        $path = $this->buildFilledOpcrf();

        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($path))
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

    public function test_compliance_needs_no_upload_and_records_the_history(): void
    {
        Storage::fake('local');

        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $admin->id]);

        // The staff member's original upload, archived when they submitted.
        Storage::disk('local')->put('opcrf-submissions/'.$staff->id.'/submitted.xlsx', 'submitted-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/'.$staff->id.'/submitted.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        // Nothing is attached — the reviewer adds remarks and marks the
        // submitted OPCRF compliant as-is. The configured onward hop (SY)
        // has no account in this test's database, so the mark stands alone.
        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('reviewRemarks', 'All objectives supported — compliant.')
            ->call('approveSubmission')
            ->assertSet('showReview', false);

        $submission->refresh();

        $this->assertTrue($submission->isApproved());
        $this->assertSame(OpcrfSubmission::STATUS_FOR_COMPLIANCE, $submission->status);
        $this->assertSame($admin->id, $submission->approved_by);
        $this->assertNotNull($submission->approved_at);

        // The submitted file is untouched — byte for byte, same path.
        $this->assertSame('opcrf-submissions/'.$staff->id.'/submitted.xlsx', $submission->file_path);
        $this->assertSame('submitted-bytes', Storage::disk('local')->get($submission->file_path));

        // The trail records the reviewer, the action, the remarks, when,
        // and the routing (nowhere — the chain ends here).
        $this->assertDatabaseHas('opcrf_reviews', [
            'opcrf_submission_id' => $submission->id,
            'reviewer_id' => $admin->id,
            'action' => 'compliance',
            'remarks' => 'All objectives supported — compliant.',
            'from_id' => null,
            'to_id' => null,
        ]);
    }

    public function test_compliance_routes_onward_to_the_next_superadmin(): void
    {
        $first = $this->superadmin();
        $next = $this->otherSuperadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $first->id]);

        // The workflow's onward hop is configured by username (SY in
        // production); the test names the second superadmin the same way.
        config(['opcrf.next_reviewer' => $next->username]);

        Livewire::actingAs($first)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('reviewRemarks', 'Compliant — on to the next review.')
            ->call('approveSubmission');

        $submission->refresh();

        // Marked compliant, and auto-routed onward: the next superadmin now
        // holds the review step, the original reviewer keeps the record.
        $this->assertSame(OpcrfSubmission::STATUS_FORWARDED, $submission->status);
        $this->assertTrue($submission->isApproved());
        $this->assertSame($first->id, $submission->reviewer_id);
        $this->assertSame($next->id, $submission->assigned_to);

        // The trail carries both steps: the compliance decision and the
        // routing, each with the reviewer and the remarks.
        $this->assertDatabaseCount('opcrf_reviews', 2);
        $this->assertDatabaseHas('opcrf_reviews', [
            'opcrf_submission_id' => $submission->id,
            'reviewer_id' => $first->id,
            'action' => 'compliance',
            'to_id' => $next->id,
        ]);
        $this->assertDatabaseHas('opcrf_reviews', [
            'opcrf_submission_id' => $submission->id,
            'reviewer_id' => $first->id,
            'action' => 'forward',
            'from_id' => $first->id,
            'to_id' => $next->id,
        ]);

        // The next superadmin sees it in their list, with the whole trail
        // in the modal — the timeline names the recipient superadmin.
        Livewire::actingAs($next)
            ->test(OpcrfReview::class)
            ->assertSee('Jane D. Doe')
            ->call('openReview', $submission->id)
            ->assertSee('Compliance / Approved')
            ->assertSee('Compliant — on to the next review.')
            ->assertSee('Forwarded to: <strong>'.$next->username.'</strong>', false);
    }

    public function test_the_configured_chain_ends_with_the_final_reviewer(): void
    {
        $first = $this->superadmin();
        $final = $this->otherSuperadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $final->id]
        );

        // The final reviewer IS the configured onward hop: marking
        // compliant ends the chain — it never bounces back to the first.
        config(['opcrf.next_reviewer' => $final->username]);

        Livewire::actingAs($final)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->call('approveSubmission');

        $submission->refresh();

        $this->assertSame($final->id, $submission->reviewer_id);
        $this->assertSame(OpcrfSubmission::STATUS_FOR_COMPLIANCE, $submission->status);
        $this->assertDatabaseCount('opcrf_reviews', 1);
    }

    public function test_the_history_table_shows_every_review_action(): void
    {
        $first = $this->superadmin();
        $next = $this->otherSuperadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $first->id]);

        $submission->forwardTo($first, $next, 'Please double-check the targets.');
        $submission->returnForRevision($next, 'Add the missing accomplishments.');

        // After the return the submission is back with the staff member;
        // the original reviewer keeps the record — and its whole trail —
        // visible.
        Livewire::actingAs($first)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee('Review History')
            ->assertSee('Forwarded')
            ->assertSee('Forwarded to: <strong>'.$next->username.'</strong>', false)
            ->assertSee('Please double-check the targets.')
            ->assertSee('Returned for Revision')
            ->assertSee('Add the missing accomplishments.')
            ->assertSee('Submission received');
    }

    public function test_returning_for_revision_needs_remarks_and_shows_them_to_the_staff_member(): void
    {
        $admin = $this->superadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $admin->id]);

        // Remarks are what makes a return actionable — refused without.
        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->call('returnSubmission')
            ->assertHasErrors(['reviewRemarks']);

        $this->assertSame(OpcrfSubmission::STATUS_PENDING, $submission->fresh()->status);

        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('reviewRemarks', 'Complete the accomplishments column, then resubmit.')
            ->call('returnSubmission')
            ->assertSet('showReview', false);

        $submission->refresh();

        $this->assertSame(OpcrfSubmission::STATUS_RETURNED, $submission->status);
        $this->assertFalse($submission->isApproved());
        $this->assertDatabaseHas('opcrf_reviews', [
            'opcrf_submission_id' => $submission->id,
            'reviewer_id' => $admin->id,
            'action' => 'return',
            'remarks' => 'Complete the accomplishments column, then resubmit.',
        ]);

        // The staff member sees the status and the reviewer's remarks in
        // their own table.
        Livewire::actingAs($staff)
            ->test(OpcrfMovs::class)
            ->assertSee('Returned for Revision')
            ->assertSee('Complete the accomplishments column, then resubmit.')
            ->assertSee($admin->username);
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

    public function test_staff_can_always_fetch_their_own_submitted_copy(): void
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

        // The workbook is the staff member's own submission — they can
        // fetch it at any time, review pending or not.
        $this->actingAs($staff)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk()
            ->assertDownload('Staff Member - OPCRF.xlsx');

        // …and after the compliance mark it stays available, still the
        // same bytes — reviews never replace the file.
        $submission->markCompliant($admin);

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
        $approved->markCompliant($admin);

        // Superadmin: the review table marks which submissions are done.
        Livewire::actingAs($admin)
            ->test(OpcrfReview::class)
            ->assertSee('For Compliance')
            ->assertSee('Pending Review')
            ->assertSee('July to December 2026');

        // Staff: their own table shows the compliance mark, and offers the
        // download action for the approved submission only.
        Livewire::actingAs($staff)
            ->test(OpcrfMovs::class)
            ->assertSee('For Compliance')
            ->assertSee('Pending Review')
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
        $approvedForMe->markCompliant($mine);

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

    public function test_the_review_modal_has_no_manual_forwarding_step(): void
    {
        $mine = $this->superadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $mine->id]
        );

        // Routing is automatic: the reviewer never picks a next superadmin.
        Livewire::actingAs($mine)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertDontSee('Forward to Next Superadmin')
            ->assertDontSee('Forward this submission to')
            ->assertDontSee('>Forward</span>', false);
    }

    public function test_compliance_auto_routes_the_same_submission_to_the_next_superadmin(): void
    {
        Storage::fake('local');

        $first = $this->superadmin();
        $next = $this->otherSuperadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $first->id]);

        // The staff member's original upload, archived when they submitted.
        Storage::disk('local')->put('opcrf-submissions/auto-route.xlsx', 'auto-route-bytes');
        $submission->update([
            'file_path' => 'opcrf-submissions/auto-route.xlsx',
            'file_original_name' => 'OPCRF-TEMPLATE.xlsx',
        ]);

        // The workflow's onward hop is configured by username (SY in
        // production); the test names the second superadmin the same way.
        config(['opcrf.next_reviewer' => $next->username]);

        Livewire::actingAs($first)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('reviewRemarks', 'Compliant — on to the next review.')
            ->call('approveSubmission')
            ->assertDispatched('opcrf-submission-forwarded');

        $submission->refresh();

        // ONE submission row, still owned by the same staff member and the
        // same workbook — only the assignment and the status moved.
        $this->assertSame(1, OpcrfSubmission::count());
        $this->assertSame($staff->id, $submission->user_id);
        $this->assertSame($first->id, $submission->reviewer_id);
        $this->assertSame($next->id, $submission->assigned_to);
        $this->assertSame(OpcrfSubmission::STATUS_FORWARDED, $submission->status);
        $this->assertTrue($submission->isApproved());
        $this->assertSame($first->id, $submission->approved_by);

        // The trail carries both steps: the compliance decision and the
        // auto-routing, each with the reviewer and the remarks.
        $this->assertDatabaseCount('opcrf_reviews', 2);
        $this->assertDatabaseHas('opcrf_reviews', [
            'opcrf_submission_id' => $submission->id,
            'reviewer_id' => $first->id,
            'action' => 'compliance',
            'remarks' => 'Compliant — on to the next review.',
            'to_id' => $next->id,
        ]);
        $this->assertDatabaseHas('opcrf_reviews', [
            'opcrf_submission_id' => $submission->id,
            'reviewer_id' => $first->id,
            'action' => 'forward',
            'from_id' => $first->id,
            'to_id' => $next->id,
        ]);

        // The original reviewer keeps the record in their list — status
        // "Forwarded to Superadmin …" — and keeps read access to the file.
        Livewire::actingAs($first)
            ->test(OpcrfReview::class)
            ->assertSee('Jane D. Doe')
            ->assertSee('Forwarded to Superadmin '.$next->username);

        $this->actingAs($first)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk();

        // The next superadmin finds the SAME submission in their queue —
        // shown to them as "Pending Review".
        Livewire::actingAs($next)
            ->test(OpcrfReview::class)
            ->assertSee('Jane D. Doe')
            ->assertSee('Pending Review')
            ->call('openReview', $submission->id)
            ->assertSee('Compliance / Approved')
            ->assertSee('Compliant — on to the next review.')
            ->assertSee('Forwarded to: <strong>'.$next->username.'</strong>', false);
    }

    public function test_the_original_reviewer_cannot_act_once_the_submission_routed_onward(): void
    {
        $first = $this->superadmin();
        $next = $this->otherSuperadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $first->id]
        );

        config(['opcrf.next_reviewer' => $next->username]);

        $submission->markCompliant($first, 'First-pass remarks.', $next);

        // The original reviewer can still OPEN the record — read-only, with
        // the routing note naming the superadmin it now sits with.
        Livewire::actingAs($first)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee('routed onward to')
            ->assertSee($next->username)
            ->assertDontSee('>Approve / Compliance</span>', false);

        // …and neither review action goes through any more: the harness
        // surfaces the abort as a failed response, so the decisive checks
        // are the unchanged state and trail.
        Livewire::actingAs($first)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('reviewRemarks', 'Trying to act after the fact.')
            ->call('approveSubmission');

        Livewire::actingAs($first)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('reviewRemarks', 'Trying to return it instead.')
            ->call('returnSubmission');

        $submission->refresh();

        $this->assertSame($next->id, $submission->assigned_to);
        $this->assertSame(OpcrfSubmission::STATUS_FORWARDED, $submission->status);
        $this->assertDatabaseCount('opcrf_reviews', 2);
    }

    public function test_the_next_superadmin_completes_the_chain_after_the_auto_route(): void
    {
        $first = $this->superadmin();
        $next = $this->otherSuperadmin();
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => $first->id]);

        // The chain ends with the onward hop: SY reviewing the submission
        // SY already holds.
        config(['opcrf.next_reviewer' => $next->username]);
        $submission->markCompliant($first, 'First-pass remarks.', $next);

        Livewire::actingAs($next)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->set('reviewRemarks', 'Final review — approved.')
            ->call('approveSubmission');

        $submission->refresh();

        // Same row; the chain ended with the final reviewer — no bounce.
        $this->assertSame($first->id, $submission->reviewer_id);
        $this->assertSame($next->id, $submission->assigned_to);
        $this->assertSame(OpcrfSubmission::STATUS_FOR_COMPLIANCE, $submission->status);
        $this->assertSame($next->id, $submission->approved_by);

        // The whole journey in one trail — compliance → auto-forward →
        // final compliance — with the remarks intact.
        $this->assertDatabaseCount('opcrf_reviews', 3);
        $this->assertSame(
            ['compliance', 'forward', 'compliance'],
            $submission->reviews()->pluck('action')->all()
        );
        $this->assertSame(
            ['First-pass remarks.', 'First-pass remarks.', 'Final review — approved.'],
            $submission->reviews()->pluck('remarks')->all()
        );
    }

    public function test_a_superadmin_cannot_act_on_a_submission_routed_elsewhere(): void
    {
        $owner = $this->superadmin();
        $other = $this->otherSuperadmin();
        $submission = $this->createSubmission(
            $this->staffUser(),
            ['reviewer_id' => $owner->id]
        );

        // A crafted call pointing the modal at someone else's row: the row
        // is outside this superadmin's scope, so the action aborts (the
        // harness surfaces it as a failed response) and nothing changes.
        Livewire::actingAs($other)
            ->test(OpcrfReview::class)
            ->set('reviewId', $submission->id)
            ->set('showReview', true)
            ->call('approveSubmission');

        $submission->refresh();

        $this->assertSame(OpcrfSubmission::STATUS_PENDING, $submission->status);
        $this->assertNull($submission->approved_at);
        $this->assertDatabaseCount('opcrf_reviews', 0);
    }

    public function test_a_regular_user_cannot_trigger_review_actions(): void
    {
        $staff = $this->staffUser();
        $submission = $this->createSubmission($staff, ['reviewer_id' => null]);

        $thrown = null;

        try {
            Livewire::actingAs($staff)
                ->test(OpcrfReview::class)
                ->set('reviewId', $submission->id)
                ->call('approveSubmission');
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
            ->assertSee('(staff)')            // The name typed into the workbook is still shown in its own row.
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
