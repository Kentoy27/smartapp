<?php

namespace Tests\Feature;

use App\Livewire\OpcrfMovs;
use App\Livewire\OpcrfUpload;
use App\Models\District;
use App\Models\OpcrfSubmission;
use App\Models\OpcrfTemplate;
use App\Models\School;
use App\Models\User;
use App\Support\OpcrfSpreadsheet;
use App\Support\OpcrfTemplatePersonalizer;
use App\Support\PurePhpZipReader;
use App\Support\PurePhpZipWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsOpcrfWorkbooks;
use Tests\TestCase;

/**
 * The identity rule: what the workbook says about who it belongs to.
 *
 * The signal is deliberately content-based. A file NAME proves nothing —
 * any of these files can be called "OPCRF_Juan_Dela_Cruz.xlsx" — so what is
 * read is what is typed into the form, out of the workbook, with the same
 * analyzer the review uses.
 *
 * What the signal may DO is deliberately narrow. A name that is blank,
 * spelled differently or plainly somebody else's does not stop an upload:
 * staff file their OPCR freely, and the comparison is recorded on the
 * submission as context for the reviewer. The one refusal left is a stamp
 * proving the workbook was generated on another account's dashboard — that
 * is not a spelling question, it is a different person's file.
 */
class OpcrfIdentityTest extends TestCase
{
    use BuildsOpcrfWorkbooks;
    use RefreshDatabase;

    private function staff(string $name = 'Juan Dela Cruz', ?string $username = null): User
    {
        $username ??= 'jdelacruz';

        return User::create([
            'name' => $name,
            'username' => $username,
            'email' => $username.'@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);
    }

    private function reviewer(): User
    {
        return User::create([
            'name' => 'Chief',
            'username' => 'chief',
            'email' => 'chief@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
    }

    private function upload(User $user, string $workbookPath): Testable
    {
        return Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->call('openUpload')
            ->set('file', UploadedFile::fake()->createWithContent(
                'OPCRF_Juan_Dela_Cruz.xlsx',
                file_get_contents($workbookPath),
            ));
    }

    /* ------------------------------------------------------------------
     * The generated template
     * ------------------------------------------------------------------ */

    public function test_the_identity_comes_from_the_account_not_the_workbook(): void
    {
        $district = District::create(['name' => 'Division of Manila']);
        $school = School::create(['name' => 'ABC Elementary School', 'district_id' => $district->id]);

        $user = $this->staff();
        $user->update(['position' => 'Teacher III', 'school_id' => $school->id]);

        $identity = OpcrfTemplatePersonalizer::identityFor($user->fresh());

        $this->assertSame('Juan Dela Cruz', $identity['name']);
        $this->assertSame('Teacher III', $identity['position']);
        $this->assertSame('ABC Elementary School', $identity['school']);
        $this->assertSame('Division of Manila', $identity['division']);
        $this->assertSame('jdelacruz', $identity['employee_id']);

        // The shipped template's own evaluator name must never be mistaken
        // for the employee's — the personalizer only reads the left band.
        $this->assertStringNotContainsString(
            'DOLLOSA',
            implode(' ', array_column($identity, null))
        );
    }

    public function test_a_permission_role_is_never_printed_as_a_position(): void
    {
        // 'user' is a permission flag, not a job — printing it into a box
        // labelled "Position/Designation" would be worse than leaving it
        // blank for the staff member to complete.
        $user = $this->staff();
        $user->update(['role' => 'user']);

        $this->assertSame('', OpcrfTemplatePersonalizer::identityFor($user->fresh())['position']);

        // A role that does read as a job title is used.
        $user->update(['role' => 'Teacher III']);
        $this->assertSame('Teacher III', OpcrfTemplatePersonalizer::identityFor($user->fresh())['position']);
    }

    public function test_the_download_name_is_safe_and_readable(): void
    {
        $this->assertSame(
            'OPCRF_Juan_Dela_Cruz.xlsx',
            OpcrfTemplatePersonalizer::downloadNameFor($this->staff('Juan Dela Cruz', 'juan'))
        );

        // Path separators and the characters Windows rejects are dropped;
        // the name stays readable.
        $this->assertSame(
            'OPCRF_Juan_D_Cruz.xlsx',
            OpcrfTemplatePersonalizer::downloadNameFor($this->staff('Juan/D: Cruz', 'slashy'))
        );

        // No registered name: still a usable, distinguishable file.
        $nameless = $this->staff('', 'nameless');

        $this->assertSame(
            'OPCRF_user-'.$nameless->id.'.xlsx',
            OpcrfTemplatePersonalizer::downloadNameFor($nameless)
        );
    }

    /* ------------------------------------------------------------------
     * What the gate accepts
     * ------------------------------------------------------------------ */

    public function test_a_workbook_naming_the_account_is_accepted(): void
    {
        Storage::fake('local');
        $this->reviewer();

        $user = $this->staff();

        $this->upload($user, $this->buildFilledOpcrf(['F4' => 'Juan Dela Cruz']))
            ->assertSet('showReview', true)
            ->assertHasNoErrors()
            // The modal states which name was verified.
            ->assertSee('Name verified')
            ->assertSee('Juan Dela Cruz');
    }

    /**
     * Typing your own name into a spreadsheet is not exact. These are all the
     * same person, and refusing them would train staff to work around the
     * check.
     */
    public function test_the_same_person_is_recognised_however_they_type_their_name(): void
    {
        Storage::fake('local');
        $user = $this->staff();

        foreach ([
            'Juan Dela Cruz',
            'JUAN DELA CRUZ',
            'juan dela cruz',
            'Juan  Dela   Cruz',
            'Dela Cruz, Juan',
            'J. Dela Cruz',
            'Juan D. Cruz',
        ] as $spelling) {
            $this->assertTrue(
                OpcrfTemplatePersonalizer::namesMatch($spelling, 'Juan Dela Cruz'),
                "“{$spelling}” should be recognised as the account holder."
            );
        }
    }

    /* ------------------------------------------------------------------
     * What the gate refuses — and the much larger thing it does not
     * ------------------------------------------------------------------ */

    public function test_a_workbook_naming_somebody_else_is_still_uploaded_and_the_reviewer_is_told(): void
    {
        Storage::fake('local');
        $this->reviewer();

        $user = $this->staff();

        $component = $this->upload($user, $this->buildFilledOpcrf(['F4' => 'Maria Santos']))
            ->assertSet('showReview', true)
            ->assertHasNoErrors()
            ->assertSet('verifiedName', 'Maria Santos')
            // A heads-up, stated as optional — not a refusal.
            ->assertSet('nameNote', fn (?string $note): bool => $note !== null
                && str_contains($note, 'Maria Santos')
                && str_contains($note, 'still submit'))
            // And it is actually on screen, in the warning tone rather than
            // the green "Name verified" banner, because nothing was verified.
            ->assertSee('Heads up')
            ->assertSee('Maria Santos')
            ->assertSee('You can still submit this form.')
            ->assertDontSee('Name verified');

        $component->call('confirmSubmit')
            ->assertHasNoErrors()
            ->assertDispatched('opcrf-submission-created');

        $this->assertDatabaseCount('opcrf_submissions', 1);

        $submission = OpcrfSubmission::first();

        // The mismatch is preserved for the reviewer rather than swallowed:
        // both sides of the comparison, so it still means something after
        // somebody corrects the name in Users.
        $this->assertSame(OpcrfTemplatePersonalizer::MISMATCH, $submission->upload_check);
        $this->assertSame('Juan Dela Cruz', $submission->account_name);
        $this->assertSame('Maria Santos', $submission->employee_name);
        $this->assertTrue($submission->hasUploadCheckConcern());
        $this->assertStringContainsString('Maria Santos', (string) $submission->uploadCheckNote());
    }

    public function test_a_workbook_with_no_name_at_all_is_still_uploaded(): void
    {
        Storage::fake('local');
        $this->reviewer();

        $user = $this->staff();

        $this->upload($user, $this->buildFilledOpcrf(['F4' => '']))
            ->assertSet('showReview', true)
            ->assertHasNoErrors()
            ->assertSet('verifiedName', '')
            ->assertSet('nameNote', fn (?string $note): bool => $note !== null
                && str_contains($note, 'No name could be read'));

        $this->assertDatabaseCount('opcrf_submissions', 0);

        $this->upload($user, $this->buildFilledOpcrf(['F4' => '']))
            ->call('confirmSubmit')
            ->assertHasNoErrors();

        $this->assertSame(
            OpcrfTemplatePersonalizer::UNREADABLE,
            OpcrfSubmission::first()->upload_check
        );
    }

    /**
     * The whole point, walked end to end: the untouched shipped template —
     * no employee name typed anywhere — is opened, reviewed and filed, and
     * the account's own name never enters the decision.
     *
     * Guards against the rule creeping back in as copy or as a stray guard,
     * not as a refusal: the dashboard must not promise a name match it no
     * longer enforces, and the upload must not silently start failing.
     */
    public function test_the_untouched_template_uploads_end_to_end_with_no_name_anywhere(): void
    {
        Storage::fake('local');
        $this->reviewer();
        $user = $this->staff();

        // The realistic state: the account downloaded its template first,
        // which is also what puts the card's "not submitted" guidance on
        // screen.
        OpcrfTemplate::record($user, '2026.1', 'OPCRF-Test.xlsx');

        $component = $this->upload($user, $this->buildFilledOpcrf([
            'B4' => 'Name of Employee:',
            'F4' => '',
            'F5' => '',
            'F6' => '',
            'F7' => '',
            'O4' => 'EVA M. DOLLOSA RN',
        ]));

        // Nothing refused it, and nothing was claimed to be verified.
        $component->assertSet('showReview', true)
            ->assertSet('showUpload', false)
            ->assertHasNoErrors()
            ->assertSet('verifiedName', '')
            ->assertSet('employee_name', '')
            ->assertSee('Heads up')
            ->assertDontSee('Name verified');

        // The dashboard must not promise a name match it no longer
        // enforces. Checked BEFORE submitting, while the card still shows
        // its "not submitted" guidance.
        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertSee('Any name will do')
            ->assertSee('your reviewer is told if the file')
            ->assertDontSee('the name inside it is checked against your account');

        $component->call('confirmSubmit')->assertHasNoErrors();

        $submission = OpcrfSubmission::firstOrFail();

        $this->assertSame('', $submission->employee_name);
        $this->assertSame(OpcrfTemplatePersonalizer::UNREADABLE, $submission->upload_check);
        $this->assertTrue(Storage::disk('local')->exists($submission->file_path));

        // And the upload window's own requirements list says the same, so
        // nobody reads "will be checked" as "will be rejected".
        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->call('openUpload')
            ->assertSee('Any name will do — if the file does not match your account')
            ->assertDontSee('the name inside the file is checked against your account');
    }

    /**
     * An account with no name on file used to be an automatic refusal with
     * no way out except asking an administrator. It is not any more.
     */
    public function test_an_account_with_no_name_can_upload_freely(): void
    {
        Storage::fake('local');
        $this->reviewer();

        $nameless = User::create([
            'name' => null,
            'username' => 'anon',
            'email' => 'anon@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);

        $this->upload($nameless, $this->buildFilledOpcrf(['F4' => 'Juan Dela Cruz']))
            ->assertSet('showReview', true)
            ->assertHasNoErrors()
            ->assertSet('nameNote', fn (?string $note): bool => $note !== null
                && str_contains($note, 'no name on file'))
            ->call('confirmSubmit')
            ->assertHasNoErrors();

        $this->assertSame(
            OpcrfTemplatePersonalizer::ACCOUNT_UNNAMED,
            OpcrfSubmission::first()->upload_check
        );
    }

    /**
     * The one thing that is still a refusal, and it is deliberately not a
     * name question: this file was generated on somebody else's dashboard.
     */
    /**
     * The last gate is gone. A workbook downloaded from somebody else's
     * dashboard — even with the right name typed over the header — is
     * uploaded like anything else, and the reviewer is told where it came
     * from.
     */
    public function test_a_workbook_from_another_account_is_uploaded_and_its_origin_recorded(): void
    {
        Storage::fake('local');
        $this->reviewer();

        $user = $this->staff();
        $other = $this->staff('Maria Santos', 'msantos');

        // Maria's personalized template, with Juan's name typed over the
        // header so the name alone would read as a match. The stamp inside
        // the workbook still says where the file was generated.
        $path = $this->stageWorkbook(
            OpcrfTemplatePersonalizer::personalizeFor($other->fresh())
        );

        $tampered = $this->rewriteNameCell($path, 'Juan Dela Cruz');

        $this->assertSame(
            OpcrfTemplatePersonalizer::STAMP_MISMATCH,
            OpcrfTemplatePersonalizer::verify($tampered, $user)['status']
        );

        $component = $this->upload($user, $tampered)
            ->assertSet('showReview', true)
            ->assertHasNoErrors()
            ->assertSee('Heads up')
            ->assertSee('downloaded from another user');

        $component->call('confirmSubmit')->assertHasNoErrors();

        $this->assertDatabaseCount('opcrf_submissions', 1);

        $submission = OpcrfSubmission::firstOrFail();

        $this->assertSame(OpcrfTemplatePersonalizer::STAMP_MISMATCH, $submission->upload_check);
        $this->assertStringContainsString(
            'another user',
            (string) $submission->uploadCheckNote()
        );
        $this->assertFalse(
            $submission->hasUploadCheckConcern(),
            'A file from another dashboard is context, not a flag to act on.'
        );
    }

    /* ------------------------------------------------------------------
     * What a stored submission looks like
     * ------------------------------------------------------------------ */

    public function test_an_accepted_submission_is_recorded_against_its_template(): void
    {
        Storage::fake('local');
        $reviewer = $this->reviewer();
        $user = $this->staff();

        // The account downloads its own template first.
        $this->actingAs($user)->get(route('opcrf.template'))->assertOk();
        $template = OpcrfTemplate::latestFor($user);

        $this->upload($user, $this->buildFilledOpcrf(['F4' => 'Juan Dela Cruz']))
            ->call('confirmSubmit');

        $submission = OpcrfSubmission::firstOrFail();

        $this->assertSame($user->id, $submission->user_id);
        $this->assertSame($reviewer->id, $submission->reviewer_id);
        $this->assertTrue($submission->wasAnsweredOnOwnTemplate());
        $this->assertSame($template->id, $submission->opcrf_template_id);
        $this->assertSame($template->version, $submission->template?->version);

        // A reference a person can quote, minted once.
        $this->assertSame('OPCRF-'.now()->format('Y').'-'.str_pad((string) $submission->id, 5, '0', STR_PAD_LEFT), $submission->reference);

        // The original bytes are archived untouched.
        $this->assertNotNull($submission->file_path);
        $this->assertTrue(Storage::disk('local')->exists($submission->file_path));
        $this->assertNotNull($submission->file_updated_at);
        $this->assertSame(OpcrfSubmission::STATUS_PENDING, $submission->status);
    }

    public function test_a_revised_upload_keeps_the_version_it_replaces(): void
    {
        Storage::fake('local');
        $reviewer = $this->reviewer();
        $user = $this->staff();

        $this->upload($user, $this->buildFilledOpcrf(['F4' => 'Juan Dela Cruz']))
            ->call('confirmSubmit');

        $submission = OpcrfSubmission::firstOrFail();
        $firstPath = $submission->file_path;

        // Returned for revision, then revised through the app's own revise
        // flow on the Opcrf page.
        $submission->returnForRevision($reviewer, 'Objectives are too vague.');

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->call('openRevise', $submission->id)
            ->set('revisionFile', UploadedFile::fake()->createWithContent(
                'OPCRF_Juan_Dela_Cruz.xlsx',
                file_get_contents($this->buildFilledOpcrf([
                    'F4' => 'Juan Dela Cruz',
                    'F16' => 'Objective 1: Sharpened and specific',
                ])),
            ))
            ->call('confirmResubmit')
            ->assertHasNoErrors();

        $submission->refresh();

        $this->assertSame(1, $submission->versions()->count(), 'Version 1 must survive the revision.');
        $this->assertSame($firstPath, $submission->versions()->firstOrFail()->stored_path);
        $this->assertSame(1, $submission->versions()->firstOrFail()->version_number);
        $this->assertSame(OpcrfSubmission::STATUS_RESUBMITTED, $submission->status);

        // The current file is the revision, and Version 1 is still on disk.
        $this->assertNotSame($firstPath, $submission->file_path);
        $this->assertTrue(Storage::disk('local')->exists($firstPath));
    }

    public function test_a_revision_naming_somebody_else_is_accepted_and_keeps_both_versions(): void
    {
        Storage::fake('local');
        $reviewer = $this->reviewer();
        $user = $this->staff();

        $this->upload($user, $this->buildFilledOpcrf(['F4' => 'Juan Dela Cruz']))
            ->call('confirmSubmit');

        $submission = OpcrfSubmission::firstOrFail();

        $submission->returnForRevision($reviewer, 'Objectives are too vague.');

        // A revision is an upload like any other, so it answers to the same
        // rule: a different name in the header block does not stop it.
        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->call('openRevise', $submission->id)
            ->set('revisionFile', UploadedFile::fake()->createWithContent(
                'OPCRF_Juan_Dela_Cruz.xlsx',
                file_get_contents($this->buildFilledOpcrf(['F4' => 'Maria Santos'])),
            ))
            ->call('confirmResubmit')
            ->assertHasNoErrors('revisionFile');

        $submission->refresh();

        $this->assertSame(OpcrfSubmission::STATUS_RESUBMITTED, $submission->status);
        $this->assertSame(1, $submission->versions()->count(), 'Version 1 is archived, not overwritten.');
    }

    /**
     * A revision answers to the same rule as a first submission: nothing
     * the workbook says about itself holds it up, and Version 1 is archived
     * rather than overwritten.
     */
    public function test_a_revision_from_another_account_is_accepted_like_any_other(): void
    {
        Storage::fake('local');
        $reviewer = $this->reviewer();
        $user = $this->staff();
        $other = $this->staff('Maria Santos', 'msantos');

        $this->upload($user, $this->buildFilledOpcrf(['F4' => 'Juan Dela Cruz']))
            ->call('confirmSubmit');

        $submission = OpcrfSubmission::firstOrFail();
        $firstPath = $submission->file_path;

        $submission->returnForRevision($reviewer, 'Objectives are too vague.');

        // Maria's own template, with Juan's name typed over the header.
        // Nothing about that is a reason to hold up a revision.
        $tampered = $this->rewriteNameCell(
            $this->stageWorkbook(OpcrfTemplatePersonalizer::personalizeFor($other->fresh())),
            'Juan Dela Cruz'
        );

        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->call('openRevise', $submission->id)
            ->set('revisionFile', UploadedFile::fake()->createWithContent(
                'OPCRF_Juan_Dela_Cruz.xlsx',
                file_get_contents($tampered),
            ))
            ->call('confirmResubmit')
            ->assertHasNoErrors('revisionFile');

        $submission->refresh();

        $this->assertSame(OpcrfSubmission::STATUS_RESUBMITTED, $submission->status);
        $this->assertSame(1, $submission->versions()->count(), 'Version 1 is archived, not overwritten.');
        $this->assertSame(OpcrfSubmission::STATUS_RESUBMITTED, $submission->status);
    }

    public function test_the_dashboard_shows_the_generated_template_and_the_submission(): void
    {
        Storage::fake('local');
        $this->reviewer();
        $user = $this->staff();

        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertSee('Download OPCRF Template');

        $this->actingAs($user)->get(route('opcrf.template'))->assertOk();

        // Now that a template exists, the card names the actual file.
        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertSee('OPCRF_Juan_Dela_Cruz.xlsx')
            ->assertSee('Template v'.config('opcrf.template.version'))
            ->assertSee('Download Again');

        $this->upload($user, $this->buildFilledOpcrf(['F4' => 'Juan Dela Cruz']))
            ->call('confirmSubmit');

        $submission = OpcrfSubmission::firstOrFail();

        // The card locks, but the reference stays on it — it is what the
        // staff member quotes when they ask about the form.
        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertSee('OPCRF submitted — locked')
            ->assertSee($submission->reference)
            ->assertSee('Pending Review');
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private function stageWorkbook(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opcrf-id-').'.xlsx';
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * A workbook with its Name of Employee cell overwritten — the tampering
     * a determined user could do by hand in Excel.
     */
    private function rewriteNameCell(string $workbookPath, string $name): string
    {
        $parts = (new PurePhpZipReader($workbookPath))->readAll();
        $sheet = 'xl/worksheets/sheet1.xml';

        $parts[$sheet] = (string) preg_replace(
            '/(<c\b[^>]*\br="F4"[^>]*>).*?(<\/c>)/s',
            '$1 t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($name, ENT_XML1).'</t></is></c>',
            $parts[$sheet],
            1
        );

        return $this->stageWorkbook(PurePhpZipWriter::create($parts));
    }
}
