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
use ZipArchive;

/**
 * Filing an OPCR signs it: the account's digital signature is encoded into the
 * workbook automatically — no signing step for the staff member to forget —
 * and the stamped workbook is what the superadmin's download hands back.
 */
class OpcrfSignatureStampTest extends TestCase
{
    use BuildsOpcrfWorkbooks;
    use RefreshDatabase;

    /** A real 2×2 transparent PNG — bytes no reader can mistake for anything else. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR4nGP8z8DAwMDAwMDEAAWMDAwAKrQCAo0Y7sMAAAAASUVORK5CYII=';

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->reviewer = User::create([
            'name' => 'Chief',
            'username' => 'chief',
            'email' => 'chief@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
    }

    private function signaturePng(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    /**
     * The staff member who files the OPCR — with a digital signature on their
     * profile, the way the workflow expects one.
     */
    private function signedStaffUser(): User
    {
        $user = User::create([
            'name' => 'Jane D. Doe',
            'username' => 'staff',
            'email' => 'staff@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);

        Storage::disk('local')->put('signatures/'.$user->id.'/sig.png', $this->signaturePng());
        $user->forceFill([
            'signature_path' => 'signatures/'.$user->id.'/sig.png',
            'signature_original_name' => 'my-signature.png',
            'signature_mime_type' => 'image/png',
            'signature_uploaded_at' => now(),
            'signature_updated_at' => now(),
        ])->save();

        return $user;
    }

    private function addSignatureTo(User $user): void
    {
        $path = 'signatures/'.$user->id.'/reviewer.png';
        Storage::disk('local')->put($path, $this->signaturePng());
        $user->forceFill([
            'signature_path' => $path,
            'signature_original_name' => 'reviewer.png',
            'signature_mime_type' => 'image/png',
            'signature_uploaded_at' => now(),
            'signature_updated_at' => now(),
        ])->save();
    }

    /**
     * The staff member's workbook as the upload flow sends it.
     */
    private function uploadedWorkbook(string $path): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'OPCRF-TEMPLATE.xlsx',
            file_get_contents($path)
        );
    }

    /**
     * Every package part of a stored workbook, by name.
     *
     * @return array<string, string>
     */
    private function partsOf(string $storedPath): array
    {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($storedPath));

        $parts = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $parts[$name] = (string) $zip->getFromName($name);
        }

        $zip->close();

        return $parts;
    }

    /**
     * File the OPCR through the upload flow and return the stored submission.
     */
    private function fileOpcr(User $user, ?string $workbookPath = null): OpcrfSubmission
    {
        Livewire::actingAs($user)
            ->test(OpcrfUpload::class)
            ->set('file', $this->uploadedWorkbook($workbookPath ?? $this->buildThreePartOpcrf()))
            ->call('confirmSubmit')
            ->assertHasNoErrors();

        return OpcrfSubmission::firstOrFail();
    }

    public function test_filing_an_opcr_encodes_the_accounts_signature_into_its_workbook(): void
    {
        $user = $this->signedStaffUser();
        $uploadedPath = $this->buildThreePartOpcrf();
        $uploaded = (string) file_get_contents($uploadedPath);

        $submission = $this->fileOpcr($user, $uploadedPath);

        // The signed copy exists beside the upload…
        $this->assertNotNull($submission->signed_file_path);
        Storage::disk('local')->assertExists($submission->signed_file_path);

        // …and it carries the signature image in the RATEE block at the bottom
        // of the sheet, wired the way the package format requires.
        $parts = $this->partsOf($submission->signed_file_path);

        $this->assertSame($this->signaturePng(), $parts['xl/media/image1.png'] ?? '');
        $this->assertStringContainsString('Digital Signature', $parts['xl/drawings/drawing1.xml'] ?? '');
        $this->assertStringContainsString(
            'Digital signature of Jane D. Doe',
            $parts['xl/drawings/drawing1.xml'] ?? ''
        );
        $this->assertStringContainsString('D153', $parts['xl/worksheets/sheet1.xml']);

        // The file the staff member uploaded is untouched: the archived
        // original is still their own bytes, and the stamp is a copy.
        $this->assertSame($uploaded, Storage::disk('local')->get($submission->file_path));
        $this->assertNotSame($submission->file_path, $submission->signed_file_path);

        // Downloads hand back the stamped copy, not the unsigned upload.
        $this->assertSame($submission->signed_file_path, $submission->downloadPath());
        $this->assertTrue($submission->hasSignedFile());
    }

    public function test_the_superadmin_download_serves_the_stamped_workbook(): void
    {
        $user = $this->signedStaffUser();
        $submission = $this->fileOpcr($user);

        $response = $this->actingAs($this->reviewer)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk();

        $served = (string) $response->getContent();

        $this->assertNotSame(
            Storage::disk('local')->get($submission->file_path),
            $served,
            'The reviewer must not get the unsigned upload back.'
        );

        $path = tempnam(sys_get_temp_dir(), 'served-').'.xlsx';
        file_put_contents($path, $served);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The served workbook must be a valid package.');
        $this->assertSame($this->signaturePng(), (string) $zip->getFromName('xl/media/image1.png'));
        $zip->close();

        // The review modal offers the reviewer that same stamped workbook,
        // and says so on the action itself.
        Livewire::actingAs($this->reviewer)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee(route('opcrf.submission.download', $submission), false)
            ->assertSee('Download workbook (digitally signed)');
    }

    public function test_the_review_shows_the_ratees_signature_where_the_form_is_signed(): void
    {
        $user = $this->signedStaffUser();
        $submission = $this->fileOpcr($user);

        // The signer block of the submitted form carries the ratee's own
        // column — the same signature the stamped workbook encodes into it.
        Livewire::actingAs($this->reviewer)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee('Signed after the review')
            ->assertSee(route('opcrf.submission.signature', $submission), false)
            ->assertSee('Digital signature of Jane D. Doe');
    }

    public function test_approval_stamps_the_rater_and_final_signoff_stamps_the_approving_authority(): void
    {
        $ratee = $this->signedStaffUser();
        $this->addSignatureTo($this->reviewer);

        $ferdinand = User::create([
            'name' => 'Ferdinand S. Sy',
            'username' => 'ferdinand',
            'email' => 'ferdinand@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
        $this->addSignatureTo($ferdinand);
        config(['opcrf.next_reviewer' => 'ferdinand']);

        $submission = $this->fileOpcr($ratee);

        Livewire::actingAs($this->reviewer)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->call('approveSubmission')
            ->assertHasNoErrors();

        $submission->refresh();
        $this->assertSame(OpcrfSubmission::STATUS_FORWARDED, $submission->status);
        $this->assertTrue($submission->hasSignedFile());

        $parts = $this->partsOf($submission->signed_file_path);
        $allDrawingXml = implode('', array_filter(
            $parts,
            fn (string $contents, string $name): bool => str_starts_with($name, 'xl/drawings/drawing') && str_ends_with($name, '.xml'),
            ARRAY_FILTER_USE_BOTH
        ));
        $this->assertStringContainsString('Digital signature of Chief', $allDrawingXml);

        $raterReview = $submission->reviews()
            ->where('action', \App\Models\OpcrfReview::ACTION_COMPLIANCE)
            ->firstOrFail();

        Livewire::actingAs($ferdinand)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee(route('opcrf.review.signature', [$submission, $raterReview]), false)
            ->assertSee('Digital signature of Chief');

        $this->actingAs($ferdinand)
            ->get(route('opcrf.review.signature', [$submission, $raterReview]))
            ->assertOk();

        Livewire::actingAs($ferdinand)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->call('complianceSubmission')
            ->assertHasNoErrors();

        $submission->refresh();
        $parts = $this->partsOf($submission->signed_file_path);
        $allDrawingXml = implode('', array_filter(
            $parts,
            fn (string $contents, string $name): bool => str_starts_with($name, 'xl/drawings/drawing') && str_ends_with($name, '.xml'),
            ARRAY_FILTER_USE_BOTH
        ));
        $this->assertStringContainsString('Digital signature of Ferdinand S. Sy', $allDrawingXml);
    }

    public function test_a_submission_filed_without_a_signature_shows_none_in_the_review(): void
    {
        $user = User::create([
            'name' => 'Jane D. Doe',
            'username' => 'unsigned',
            'email' => 'unsigned@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);

        $submission = $this->fileOpcr($user);

        // The block is still there — the form is signed after the review —
        // but with nothing to show: no image, and no route to fetch one.
        Livewire::actingAs($this->reviewer)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertSee('Signed after the review')
            ->assertDontSee('Digital signature of')
            ->assertDontSee(route('opcrf.submission.signature', $submission), false);
    }

    public function test_a_signature_on_the_profile_alone_is_never_served(): void
    {
        $user = $this->signedStaffUser();

        // A workbook with no signer block: the stamp has nowhere to go, so
        // the submission carries no signed copy…
        $submission = $this->fileOpcr(
            $user,
            $this->buildOpcrfWorkbook(['B2' => 'A sheet with no signer block.'])
        );

        $this->assertSame($submission->file_path, $submission->downloadPath());

        // …and the signature is never laid over it: not in the review…
        Livewire::actingAs($this->reviewer)
            ->test(OpcrfReview::class)
            ->call('openReview', $submission->id)
            ->assertDontSee('Digital signature of')
            ->assertDontSee(route('opcrf.submission.signature', $submission), false);

        // …and not through the route either: reading a signature on its own,
        // apart from the document that carries it, is not a thing this app
        // does — not even for the account the signature belongs to.
        $this->actingAs($this->reviewer)
            ->get(route('opcrf.submission.signature', $submission))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('opcrf.submission.signature', $submission))
            ->assertNotFound();
    }

    public function test_the_ratees_signature_is_served_to_whoever_may_read_the_workbook(): void
    {
        $user = $this->signedStaffUser();
        $submission = $this->fileOpcr($user);

        // The reviewer the submission was routed to gets the stored picture
        // itself — inline, and never for a shared cache.
        $response = $this->actingAs($this->reviewer)
            ->get(route('opcrf.submission.signature', $submission));

        $response->assertOk();
        $this->assertSame($this->signaturePng(), $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        // The ratee reads their own copy back too.
        $this->actingAs($user)
            ->get(route('opcrf.submission.signature', $submission))
            ->assertOk();

        // Nobody else: another superadmin the submission was never routed to,
        // and a staff account with no part in it — the same 404 the workbook
        // itself answers with.
        $otherSuperadmin = User::create([
            'name' => 'Other Chief',
            'username' => 'other-chief',
            'email' => 'other-chief@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);

        $outsider = User::create([
            'name' => 'Outsider',
            'username' => 'outsider',
            'email' => 'outsider@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);

        $this->actingAs($otherSuperadmin)
            ->get(route('opcrf.submission.signature', $submission))
            ->assertNotFound();

        $this->actingAs($outsider)
            ->get(route('opcrf.submission.signature', $submission))
            ->assertNotFound();
    }

    public function test_a_submission_without_a_signature_is_stored_exactly_as_uploaded(): void
    {
        $user = User::create([
            'name' => 'Jane D. Doe',
            'username' => 'unsigned',
            'email' => 'unsigned@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);

        $uploadedPath = $this->buildThreePartOpcrf();
        $uploaded = (string) file_get_contents($uploadedPath);

        $submission = $this->fileOpcr($user, $uploadedPath);

        // Nothing to sign with: the submission is filed all the same, with no
        // stamped copy, and downloads serve the staff member's own file.
        $this->assertNull($submission->signed_file_path);
        $this->assertFalse($submission->hasSignedFile());
        $this->assertSame($submission->file_path, $submission->downloadPath());

        $this->actingAs($this->reviewer)
            ->get(route('opcrf.submission.download', $submission))
            ->assertOk()
            ->assertContent($uploaded);
    }

    public function test_a_signature_whose_file_has_gone_does_not_block_the_submission(): void
    {
        $user = $this->signedStaffUser();

        // The columns still point at a signature, but the file is gone.
        Storage::disk('local')->delete('signatures/'.$user->id.'/sig.png');

        $submission = $this->fileOpcr($user);

        $this->assertDatabaseCount('opcrf_submissions', 1);
        $this->assertNull($submission->signed_file_path);
        $this->assertSame($submission->file_path, $submission->downloadPath());
    }

    public function test_a_revision_is_stamped_again_and_the_old_stamp_goes(): void
    {
        $user = $this->signedStaffUser();
        $submission = $this->fileOpcr($user);

        $firstStamp = $submission->signed_file_path;
        $this->assertNotNull($firstStamp);

        $submission->returnForRevision($this->reviewer, 'Fix the accomplishments.');
        $submission->refresh();

        // The staff member resubmits with a revised workbook.
        Livewire::actingAs($user)
            ->test(OpcrfMovs::class)
            ->call('openRevise', $submission->id)
            ->set('revisionFile', $this->uploadedWorkbook(
                $this->buildThreePartOpcrf(['F16' => 'Objective 1: Revised learner outcomes'])
            ))
            ->call('confirmResubmit')
            ->assertHasNoErrors();

        $submission->refresh();

        // The stamp describes the file now under review: a fresh copy, and the
        // one of the replaced workbook is gone rather than left behind.
        $this->assertNotNull($submission->signed_file_path);
        $this->assertNotSame($firstStamp, $submission->signed_file_path);
        Storage::disk('local')->assertMissing($firstStamp);
        Storage::disk('local')->assertExists($submission->signed_file_path);

        $parts = $this->partsOf($submission->signed_file_path);

        $this->assertStringContainsString('Revised learner outcomes', $parts['xl/worksheets/sheet1.xml'] ?? '');
        $this->assertSame($this->signaturePng(), $parts['xl/media/image1.png'] ?? '');

        // The revision itself is still archived untouched beside the stamp.
        $this->assertSame($submission->signed_file_path, $submission->downloadPath());
    }
}
