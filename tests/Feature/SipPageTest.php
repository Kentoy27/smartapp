<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Livewire\SipTable;
use App\Models\District;
use App\Models\School;
use App\Models\SchoolDivisionSetting;
use App\Models\SipSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class SipPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_heads_and_viewers_can_open_the_sip_landing_page(): void
    {
        foreach (['user', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_superadmin' => false]);

            $response = $this->actingAs($user)
                ->get(route('sip.index'))
                ->assertOk()
                ->assertSee('School Improvement Plan (SIP)')
                ->assertSee('Browse each school\'s SIP by division.', false);

            $this->assertMatchesRegularExpression(
                '/href="'.preg_quote(route('sip.index'), '/').'"\s+class="nav-item active"/',
                $response->getContent()
            );
        }
    }

    public function test_sip_sidebar_item_is_visible_to_school_heads_viewers_and_administrators(): void
    {
        foreach (['user', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_superadmin' => false]);
            $items = collect(Livewire::actingAs($user)->test(Sidebar::class)->instance()->items());
            $sip = $items->firstWhere('label', 'SIP');

            $this->assertNotNull($sip);
            $this->assertSame('sip.index', $sip['route']);
            $this->assertSame('Files', $sip['section']);
        }

        foreach ([
            User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]),
        ] as $admin) {
            $items = collect(Livewire::actingAs($admin)->test(Sidebar::class)->instance()->items());
            $sipRecords = $items->firstWhere('label', 'SIP Records');

            $this->assertNotNull($sipRecords);
            $this->assertSame('sip.index', $sipRecords['route']);
            $this->assertSame('Operations', $sipRecords['section']);
        }
    }

    public function test_administrators_can_view_all_school_sip_records_read_only(): void
    {
        $this->fakeSipStorage();
        [$firstSchool] = $this->makeSchool(['ELEMENTARY'], '123456');
        [$secondSchool] = $this->makeSchool(['JUNIOR_HIGH'], '654321');
        $secondSchool->update(['name' => 'Second Test School']);
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);

        $submissions = [];
        foreach ([$firstSchool, $secondSchool] as $school) {
            $submission = SipSubmission::create([
                'school_id' => $school->id,
                'division' => $school->availableDivisions()[0],
                'original_file_name' => $school->name.'-SIP.pdf',
                'file_path' => 'sip-uploads/'.$school->id.'/sip.pdf',
                'file_size' => 100,
                'mime_type' => 'application/pdf',
                'uploaded_at' => now(),
            ]);
            Storage::disk('local')->put($submission->file_path, '%PDF-1.4 test SIP');
            $submissions[] = $submission;
        }

        $this->actingAs($admin)
            ->get(route('sip.index'))
            ->assertOk()
            ->assertSee('Browse SIP records uploaded by schools across all divisions.')
            ->assertSee('Test School')
            ->assertSee('Second Test School');

        Livewire::actingAs($admin)->test(SipTable::class)
            ->assertSee('Test School')
            ->assertSee('Preview')
            ->assertSee('Download')
            ->assertDontSee('wire:click="openUploadModal', false);

        Livewire::actingAs($admin)->test(SipTable::class)
            ->call('openPreview', $submissions[0]->id)
            ->assertSet('previewSubmissionId', $submissions[0]->id)
            ->assertSee('sip-pdf-preview__viewport', false)
            ->assertSee('data-pdf-url="'.route('sip.preview', $submissions[0]).'"', false)
            ->call('closePreviewModal')
            ->assertSet('previewSubmissionId', null);

        foreach ($submissions as $submission) {
            $this->get(route('sip.download', $submission))->assertOk();
            $this->get(route('sip.preview', $submission))->assertOk();
        }
    }

    public function test_sip_is_not_available_to_guests_or_finance_roles(): void
    {
        $this->get(route('sip.index'))->assertRedirect(route('login'));

        foreach ([
            User::factory()->create(['role' => 'budget', 'is_superadmin' => false]),
            User::factory()->create(['role' => 'bookkeeper', 'is_superadmin' => false]),
        ] as $user) {
            $this->actingAs($user)->get(route('sip.index'))->assertNotFound();

            $items = collect(Livewire::actingAs($user)->test(Sidebar::class)->instance()->items());
            $this->assertNull($items->firstWhere('label', 'SIP'));
        }
    }

    public function test_school_head_can_upload_and_replace_own_division_sip(): void
    {
        $this->fakeSipStorage();
        [$school, $division] = $this->makeSchool();
        $head = $this->makeSchoolHead($school, [$division]);

        Livewire::actingAs($head)->test(SipTable::class)
            ->call('openBulkUploadModal')
            ->assertSet('showBulkUploadModal', true)
            ->assertDontSee('bulkSipDivision0', false);

        Livewire::actingAs($head)->test(SipTable::class)
            ->assertSee('Upload')
            ->call('openUploadModal', $school->id, $division)
            ->set('sipFile', UploadedFile::fake()->create('School-SIP.pdf', 80, 'application/pdf'))
            ->call('uploadSip')
            ->assertHasNoErrors()
            ->assertSet('showUploadModal', false)
            ->assertSet('successMessage', 'Elementary SIP uploaded for Test School.');

        $submission = SipSubmission::query()->where('school_id', $school->id)->firstOrFail();
        $firstPath = $submission->file_path;
        Storage::disk('local')->assertExists($firstPath);

        Livewire::actingAs($head)->test(SipTable::class)
            ->assertSee('Preview')
            ->call('openPreview', $submission->id)
            ->assertSee('Test School — SIP Preview')
            ->assertSee('sip-pdf-preview__viewport', false)
            ->assertSee('data-pdf-url="'.route('sip.preview', $submission).'"', false)
            ->call('closePreviewModal')
            ->assertSet('previewSubmissionId', null);

        Livewire::actingAs($head)->test(SipTable::class)
            ->call('openUploadModal', $school->id, $division)
            ->set('sipFile', UploadedFile::fake()->create('Revised-SIP.pdf', 120, 'application/pdf'))
            ->call('uploadSip')
            ->assertHasNoErrors();

        $submission->refresh();
        $this->assertSame('Revised-SIP.pdf', $submission->original_file_name);
        $this->assertNotSame($firstPath, $submission->file_path);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($submission->file_path);

        $this->actingAs($head)
            ->get(route('sip.download', $submission))
            ->assertDownload('Revised-SIP.pdf');
    }

    public function test_school_head_can_upload_multiple_sips_for_distinct_divisions_at_once(): void
    {
        $this->fakeSipStorage();
        [$school] = $this->makeSchool(['ELEMENTARY', 'JUNIOR_HIGH']);
        $head = $this->makeSchoolHead($school, ['ELEMENTARY', 'JUNIOR_HIGH']);

        $component = Livewire::actingAs($head)->test(SipTable::class)
            ->assertSee('Upload SIP')
            ->call('openBulkUploadModal')
            ->assertSet('showBulkUploadModal', true)
            ->assertSee('Add one file for each division you want to upload.')
            ->call('addBulkUploadRow')
            ->set('bulkUploads.0.file', UploadedFile::fake()->create('Elementary-SIP.pdf', 80, 'application/pdf'))
            ->set('bulkUploads.1.division', 'JUNIOR_HIGH')
            ->set('bulkUploads.1.file', UploadedFile::fake()->create('Junior-High-SIP.pdf', 100, 'application/pdf'))
            ->call('uploadMultipleSip')
            ->assertHasNoErrors()
            ->assertSet('showBulkUploadModal', false)
            ->assertSet('successMessage', '2 SIP files were uploaded for Test School.');

        $this->assertSame(2, SipSubmission::query()->where('school_id', $school->id)->count());

        foreach ([
            'ELEMENTARY' => 'Elementary-SIP.pdf',
            'JUNIOR_HIGH' => 'Junior-High-SIP.pdf',
        ] as $division => $fileName) {
            $submission = SipSubmission::query()
                ->where('school_id', $school->id)
                ->where('division', $division)
                ->firstOrFail();

            $this->assertSame($fileName, $submission->original_file_name);
            Storage::disk('local')->assertExists($submission->file_path);
        }

        $duplicateDivision = Livewire::actingAs($head)->test(SipTable::class)
            ->call('openBulkUploadModal')
            ->call('addBulkUploadRow')
            ->set('bulkUploads.0.division', 'ELEMENTARY')
            ->set('bulkUploads.1.division', 'ELEMENTARY')
            ->set('bulkUploads.0.file', UploadedFile::fake()->create('Duplicate-One.pdf', 80, 'application/pdf'))
            ->set('bulkUploads.1.file', UploadedFile::fake()->create('Duplicate-Two.pdf', 80, 'application/pdf'))
            ->call('uploadMultipleSip');

        $duplicateDivision->assertHasErrors(['bulkUploads.1.division']);
        $this->assertSame(2, SipSubmission::query()->where('school_id', $school->id)->count());
    }

    public function test_sip_table_lists_every_configured_division_and_viewers_are_read_only(): void
    {
        [$school] = $this->makeSchool(['ELEMENTARY', 'JUNIOR_HIGH']);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);

        $component = Livewire::actingAs($viewer)->test(SipTable::class)
            ->assertSee('Test School')
            ->assertSee('Elementary')
            ->assertSee('Junior High School')
            ->assertDontSee('Upload SIP');

        $this->assertSame(2, substr_count($component->html(), 'Not uploaded'));

        Livewire::actingAs($viewer)->test(SipTable::class)
            ->call('openUploadModal', $school->id, 'ELEMENTARY')
            ->assertForbidden();
    }

    public function test_school_heads_cannot_upload_or_download_another_schools_sip(): void
    {
        $this->fakeSipStorage();
        [$ownSchool] = $this->makeSchool(['ELEMENTARY'], '123456');
        [$otherSchool] = $this->makeSchool(['ELEMENTARY'], '654321');
        $head = $this->makeSchoolHead($ownSchool, ['ELEMENTARY']);
        $submission = SipSubmission::create([
            'school_id' => $otherSchool->id,
            'division' => 'ELEMENTARY',
            'uploaded_by' => $head->id,
            'original_file_name' => 'other-school.pdf',
            'file_path' => 'sip-uploads/other.pdf',
            'file_size' => 100,
            'mime_type' => 'application/pdf',
            'uploaded_at' => now(),
        ]);
        Storage::disk('local')->put($submission->file_path, 'test');

        Livewire::actingAs($head)->test(SipTable::class)
            ->call('openUploadModal', $otherSchool->id, 'ELEMENTARY')
            ->assertForbidden();

        $this->actingAs($head)
            ->get(route('sip.download', $submission))
            ->assertNotFound();

        $this->get(route('sip.preview', $submission))
            ->assertNotFound();

        Livewire::actingAs($head)->test(SipTable::class)
            ->call('openPreview', $submission->id)
            ->assertNotFound();
    }

    public function test_viewers_can_preview_pdf_and_word_sips_in_the_browser(): void
    {
        $this->fakeSipStorage();
        [$school] = $this->makeSchool(['ELEMENTARY', 'JUNIOR_HIGH']);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $disk = Storage::disk('local');
        $pdfPath = 'sip-uploads/'.$school->id.'/ELEMENTARY/preview.pdf';
        $docxPath = 'sip-uploads/'.$school->id.'/JUNIOR_HIGH/preview.docx';

        $disk->put($pdfPath, '%PDF-1.4 test SIP');
        $disk->makeDirectory(dirname($docxPath));
        $word = new PhpWord;
        $word->addSection()->addText('Full SIP Word preview content');
        IOFactory::createWriter($word, 'Word2007')->save($disk->path($docxPath));

        $pdf = SipSubmission::create([
            'school_id' => $school->id,
            'division' => 'ELEMENTARY',
            'original_file_name' => 'School-SIP.pdf',
            'file_path' => $pdfPath,
            'file_size' => $disk->size($pdfPath),
            'mime_type' => 'application/pdf',
            'uploaded_at' => now(),
        ]);
        $docx = SipSubmission::create([
            'school_id' => $school->id,
            'division' => 'JUNIOR_HIGH',
            'original_file_name' => 'School-SIP.docx',
            'file_path' => $docxPath,
            'file_size' => $disk->size($docxPath),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'uploaded_at' => now(),
        ]);

        Livewire::actingAs($viewer)->test(SipTable::class)
            ->call('openPreview', $pdf->id)
            ->assertSet('previewSubmissionId', $pdf->id)
            ->assertSee('Test School — SIP Preview')
            ->assertSee('School-SIP.pdf')
            ->assertSee('data-pdf-url="'.route('sip.preview', $pdf).'"', false)
            ->assertSee('data-cmap-url="'.asset('vendor/pdfjs/cmaps').'/'.'"', false)
            ->call('closePreviewModal')
            ->call('openPreview', $docx->id)
            ->assertSet('previewSubmissionId', $docx->id)
            ->assertSee('Test School — SIP Preview')
            ->assertSee('School-SIP.docx')
            ->assertSee('src="'.route('sip.preview', $docx).'"', false)
            ->call('closePreviewModal')
            ->assertSet('previewSubmissionId', null);

        $pdfResponse = $this->actingAs($viewer)
            ->get(route('sip.preview', $pdf))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('private', $pdfResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $pdfResponse->headers->get('Cache-Control'));

        $this->get(route('sip.preview', $docx))
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertSee('Full SIP Word preview content')
            ->assertHeader('content-security-policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox");
    }

    /**
     * @param  array<int, string>  $divisions
     * @return array{0: School, 1: string}
     */
    private function makeSchool(array $divisions = ['ELEMENTARY'], string $schoolCode = '111111'): array
    {
        $district = District::create(['name' => 'Test District '.uniqid()]);
        $school = School::create([
            'district_id' => $district->id,
            'name' => 'Test School',
            'school_id' => $schoolCode,
        ]);

        foreach ($divisions as $division) {
            SchoolDivisionSetting::create([
                'school_id' => $school->id,
                'division_code' => $division,
                'is_active' => true,
            ]);
        }

        return [$school, $divisions[0]];
    }

    /**
     * @param  array<int, string>  $divisions
     */
    private function makeSchoolHead(School $school, array $divisions): User
    {
        return User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
            'school_level' => in_array('JUNIOR_HIGH', $divisions, true) || in_array('SENIOR_HIGH', $divisions, true)
                ? 'HIGH_SCHOOL'
                : 'ELEMENTARY',
            'school_sublevel' => in_array('JUNIOR_HIGH', $divisions, true) ? 'JUNIOR_HIGH' : 'NONE',
            'division_access' => $divisions,
        ]);
    }

    private function fakeSipStorage(): void
    {
        Storage::fake('sip-local');
        Storage::set('local', Storage::disk('sip-local'));
    }
}
