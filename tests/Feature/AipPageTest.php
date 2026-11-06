<?php

namespace Tests\Feature;

use App\Livewire\AipSubmissionUpload;
use App\Livewire\Sidebar;
use App\Models\AipPlan;
use App\Models\District;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The School Head can analyze and submit AIPs, then view their records,
 * structured previews, and original uploaded documents.
 */
class AipPageTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR4nGP8z8DAwMDAwMDEAAWMDAwAKrQCAo0Y7sMAAAAASUVORK5CYII=';

    private function addSignatureTo(User $user): void
    {
        $path = 'signatures/'.$user->id.'/aip.png';
        Storage::disk('local')->put($path, (string) base64_decode(self::PNG, true));
        $user->forceFill([
            'signature_path' => $path,
            'signature_original_name' => 'aip.png',
            'signature_mime_type' => 'image/png',
            'signature_uploaded_at' => now(),
            'signature_updated_at' => now(),
        ])->save();
    }

    private function schoolHead(): User
    {
        return User::factory()->create(['role' => 'user', 'is_superadmin' => false]);
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'name' => 'Eve',
            'username' => 'Eve',
            'role' => 'superadmin',
            'is_superadmin' => true,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('aip.index'))->assertRedirect(route('login'));
    }

    public function test_school_heads_can_open_their_aip_records_page(): void
    {
        $user = $this->schoolHead();

        $response = $this->actingAs($user)
            ->get(route('aip.index'))
            ->assertOk()
            ->assertSee('Annual Implementation Plan (AIP)')
            ->assertSee('My AIP records')
            ->assertSee('Download blank AIP')
            ->assertSee('No AIP records yet.')
            ->assertSee('Upload AIP');

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('aip.index'), '/').'"\s+class="nav-item active"/',
            $response->getContent()
        );
    }

    public function test_school_heads_only_see_their_own_aip_submissions(): void
    {
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $district->id]);
        $owner = $this->schoolHead();
        $otherHead = $this->schoolHead();
        $owner->update(['school_id' => $school->id]);
        $otherHead->update(['school_id' => $school->id]);

        $ownPlan = AipPlan::create([
            'school_id' => $school->id,
            'year' => 2026,
            'created_by' => $owner->id,
            'original_file_name' => 'my-school-aip.docx',
        ]);
        $otherPlan = AipPlan::create([
            'school_id' => $school->id,
            'year' => 2027,
            'created_by' => $otherHead->id,
            'original_file_name' => 'another-head-aip.docx',
        ]);

        $this->actingAs($owner)
            ->get(route('aip.index'))
            ->assertOk()
            ->assertSee('my-school-aip.docx')
            ->assertDontSee('another-head-aip.docx');

        $this->actingAs($owner)
            ->get(route('aip.plans.export', ['plan' => $otherPlan->id]))
            ->assertNotFound();

        $this->actingAs($otherHead)
            ->get(route('aip.index'))
            ->assertOk()
            ->assertSee('another-head-aip.docx')
            ->assertDontSee('my-school-aip.docx');

        $this->assertTrue($ownPlan->managedBy($owner));
        $this->assertFalse($otherPlan->managedBy($owner));
    }

    public function test_school_head_can_open_and_update_a_returned_aip_from_the_records_page(): void
    {
        $owner = $this->schoolHead();
        $plan = AipPlan::create([
            'school_id' => $owner->school_id,
            'year' => 2026,
            'created_by' => $owner->id,
            'status' => AipPlan::STATUS_RETURNED,
            'review_remarks' => 'Please update the activity targets.',
            'submitted_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('aip.index'))
            ->assertOk()
            ->assertSee('Please update the activity targets.')
            ->assertSee('Update / Upload AIP')
            ->assertSee(route('aip.plans.edit', ['plan' => $plan->id]));

        $this->get(route('aip.plans.edit', ['plan' => $plan->id]))
            ->assertOk()
            ->assertSee('Import AIP DOCX')
            ->assertSee('Resubmit for review');
    }

    public function test_the_authoring_page_is_closed_to_superadmins_and_viewers(): void
    {
        $this->actingAs($this->superadmin())->get(route('aip.index'))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'viewer']))->get(route('aip.index'))->assertNotFound();
    }

    public function test_aip_is_listed_in_the_files_sidebar_for_school_heads_only(): void
    {
        $items = collect(Livewire::actingAs($this->schoolHead())->test(Sidebar::class)->instance()->items());

        $aip = $items->firstWhere('label', 'AIP');

        $this->assertNotNull($aip);
        $this->assertSame('aip.index', $aip['route']);
        $this->assertSame('Files', $aip['section']);
        $this->assertNotNull($items->firstWhere('label', 'MOV'));
        $schoolHeadLabels = $items->pluck('label')->all();
        $this->assertLessThan(
            array_search('OPCRF', $schoolHeadLabels, true),
            array_search('AIP', $schoolHeadLabels, true),
            'AIP should appear above OPCRF in the School Head sidebar.'
        );

        // School Heads author plans; reviewers only get the review queue.
        $adminItems = collect(Livewire::actingAs($this->superadmin())->test(Sidebar::class)->instance()->items());
        $aipReview = $adminItems->firstWhere('label', 'AIP Review');

        $this->assertNull($adminItems->firstWhere('label', 'AIP'));
        $this->assertNotNull($aipReview);
        $this->assertSame('aip.review', $aipReview['route']);
        $this->assertSame('Files', $aipReview['section']);
        $this->assertNull($adminItems->firstWhere('label', 'Review AIP'));

        $sy = User::factory()->create([
            'username' => 'SY',
            'role' => 'superadmin',
            'is_superadmin' => true,
        ]);
        $syItems = collect(Livewire::actingAs($sy)->test(Sidebar::class)->instance()->items());
        $this->assertNotNull($syItems->firstWhere('label', 'AIP Review'));

        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $viewerItems = collect(Livewire::actingAs($viewer)->test(Sidebar::class)->instance()->items());
        $aipReview = $viewerItems->firstWhere('label', 'AIP Review');
        $this->assertNotNull($aipReview);
        $this->assertSame('aip.review', $aipReview['route']);
        $this->assertSame('Files', $aipReview['section']);
        $this->assertNull($viewerItems->firstWhere('label', 'AIP'));
        $this->assertNull($viewerItems->firstWhere('label', 'MOV'));
        $viewerLabels = $viewerItems->pluck('label')->all();
        $this->assertSame(
            ['AIP Review', 'OPCRF', 'WFP Review', 'SIP'],
            array_values(array_intersect($viewerLabels, ['AIP Review', 'OPCRF', 'WFP Review', 'SIP']))
        );

        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);
        $budgetItems = collect(Livewire::actingAs($budget)->test(Sidebar::class)->instance()->items());
        $this->assertNull($budgetItems->firstWhere('label', 'AIP Review'));

        // The Budget office's WFP pages are one group, opened by default.
        $wfp = $budgetItems->firstWhere('label', 'WFP');

        $this->assertSame('wfp.submissions.index', $wfp['route']);
        $this->assertSame(
            route('wfp.submissions.index'),
            collect($wfp['children'])->firstWhere('label', 'Work & Financial Plan')['url']
        );

        $this->assertNull($budgetItems->firstWhere('label', 'WFP Uploads'));
        $this->assertNull($budgetItems->firstWhere('label', 'AIP'));
        $this->assertNull($budgetItems->firstWhere('label', 'OPCRF'));
        $this->assertNull($budgetItems->firstWhere('label', 'MOV'));
        $this->assertNull($budgetItems->firstWhere('label', 'APP'));
    }

    public function test_the_blank_template_can_be_downloaded_by_school_heads(): void
    {
        $user = $this->schoolHead();

        $this->actingAs($user)
            ->get(route('aip.template.download'))
            ->assertOk()
            ->assertDownload('AIP-Blank-Template.docx');
    }

    public function test_a_school_head_can_analyze_preview_and_submit_a_completed_aip(): void
    {
        Storage::fake('local');
        $user = $this->schoolHead();
        $user->update(['name' => 'Morgan Elena School Head', 'position' => 'School Head']);
        $this->addSignatureTo($user);
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $district->id]);
        $user->update(['school_id' => $school->id]);

        $template = storage_path('forms/FY-2026-ANNUAL-IMPLEMENTATION-PLAN-1.docx');
        $file = UploadedFile::fake()->createWithContent('completed-aip.docx', file_get_contents($template));

        Livewire::actingAs($user)
            ->test(AipSubmissionUpload::class)
            ->call('openModal')
            ->set('aipFile', $file)
            ->assertSee('AIP content preview')
            ->assertSet('detectedYear', 2027)
            ->call('submit')
            ->assertRedirect(route('aip.index'));

        $this->get(route('aip.index'))
            ->assertOk()
            ->assertSee('FY 2027 AIP submitted and is Pending Review.')
            ->assertSee('Pending Review')
            ->assertSee('completed-aip.docx');

        $plan = AipPlan::where('school_id', $school->id)->where('year', 2027)->firstOrFail();
        $this->assertSame(AipPlan::STATUS_PENDING, $plan->status);
        $this->assertNotNull($plan->submitted_at);
        $this->assertNotEmpty($plan->form_data['rows']);
        $this->assertSame(1, $plan->versions()->count());
        $this->assertSame(1, $plan->reviews()->count());
        Storage::disk('local')->assertExists($plan->source_file_path);

        $this->actingAs($user)
            ->get(route('aip.plans.preview', ['plan' => $plan->id]))
            ->assertOk()
            ->assertSee('Submitted AIP preview')
            ->assertSee('Download AIP')
            ->assertSee('Pillar 1: ACCESS');

        $this->get(route('aip.plans.source', ['plan' => $plan->id]))
            ->assertOk()
            ->assertDownload('completed-aip.docx');

        $download = $this->get(route('aip.plans.export', ['plan' => $plan->id]))
            ->assertOk()
            ->assertDownload('AIP 2027 - Alpha Elementary School.xlsx');
        $xlsxPath = tempnam(sys_get_temp_dir(), 'aip-download-').'.xlsx';
        file_put_contents($xlsxPath, $download->getContent());
        $parts = (new \App\Support\PurePhpZipReader($xlsxPath))->readAll();
        $this->assertArrayHasKey('xl/media/image1.png', $parts);
        $drawingXml = $parts['xl/drawings/drawing1.xml'] ?? '';
        $this->assertStringContainsString('Digital signature of Morgan Elena School Head', $drawingXml);
        $workbook = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsxPath);
        $exportedValues = [];

        foreach ($workbook->getActiveSheet()->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $exportedValues[] = (string) $cell->getFormattedValue();
            }
        }

        $workbook->disconnectWorksheets();
        unlink($xlsxPath);
        $this->assertContains('Morgan Elena School Head', $exportedValues);
        $this->assertContains('School Head', $exportedValues);

        $otherUser = $this->schoolHead();
        $otherDistrict = District::create(['name' => 'District II']);
        $otherSchool = School::create(['name' => 'Beta Elementary School', 'district_id' => $otherDistrict->id]);
        $otherUser->update(['school_id' => $otherSchool->id]);
        $this->actingAs($otherUser)
            ->get(route('aip.plans.preview', ['plan' => $plan->id]))
            ->assertNotFound();
        $this->get(route('aip.plans.source', ['plan' => $plan->id]))
            ->assertNotFound();
        $this->get(route('aip.plans.export', ['plan' => $plan->id]))
            ->assertNotFound();

        $duplicate = UploadedFile::fake()->createWithContent('another-aip.docx', file_get_contents($template));
        Livewire::actingAs($user)
            ->test(AipSubmissionUpload::class)
            ->call('openModal')
            ->set('aipFile', $duplicate)
            ->assertHasErrors('aipFile');
        $this->assertSame(1, AipPlan::where('school_id', $school->id)->where('year', 2027)->count());
    }

    public function test_school_head_can_replace_and_resubmit_a_returned_aip_repeatedly(): void
    {
        Storage::fake('local');
        $user = $this->schoolHead();
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $district->id]);
        $user->update(['school_id' => $school->id]);
        $reviewer = $this->superadmin();
        $template = storage_path('forms/FY-2026-ANNUAL-IMPLEMENTATION-PLAN-1.docx');
        $bytes = file_get_contents($template);
        $oldPath = 'aip-uploads/'.$school->id.'/old.docx';
        Storage::disk('local')->put($oldPath, $bytes);

        $plan = AipPlan::create([
            'school_id' => $school->id,
            'year' => 2027,
            'created_by' => $user->id,
            'form_data' => ['rows' => [['ppa' => 'Original plan content']]],
            'status' => AipPlan::STATUS_PENDING,
            'reviewer_id' => $reviewer->id,
            'source_file_path' => $oldPath,
            'original_file_name' => 'old.docx',
            'submitted_at' => now(),
        ]);
        $plan->returnForRevision($reviewer, 'Please revise this AIP.');

        foreach (['revision-one.docx', 'revision-two.docx'] as $index => $fileName) {
            $upload = UploadedFile::fake()->createWithContent($fileName, $bytes);

            Livewire::actingAs($user)
                ->test(AipSubmissionUpload::class)
                ->call('openModal')
                ->set('aipFile', $upload)
                ->assertSet('detectedYear', 2027)
                ->assertSet('isReplacingReturned', true)
                ->assertHasNoErrors()
                ->call('submit')
                ->assertRedirect(route('aip.index'));

            $plan->refresh();
            $this->assertSame(AipPlan::STATUS_RESUBMITTED, $plan->status);
            $this->assertSame($fileName, $plan->original_file_name);
            Storage::disk('local')->assertExists($plan->source_file_path);
            $this->assertSame($index + 1, $plan->versions()->count());
            $this->assertDatabaseHas('aip_reviews', [
                'aip_plan_id' => $plan->id,
                'reviewer_id' => $user->id,
                'action' => 'resubmit',
            ]);

            if ($index === 0) {
                $this->assertSame(
                    'Original plan content',
                    $plan->versions()->orderBy('version_number')->firstOrFail()->snapshot['form_data']['rows'][0]['ppa'],
                );
                $plan->returnForRevision($reviewer, 'Please revise the updated AIP.');
            }
        }
    }

    public function test_aip_record_preview_opens_in_a_modal_with_the_analyzed_document_rows(): void
    {
        $user = $this->schoolHead();
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $district->id]);
        $user->update(['school_id' => $school->id]);
        $plan = AipPlan::create([
            'school_id' => $school->id,
            'year' => 2027,
            'created_by' => $user->id,
            'original_file_name' => 'school-aip.docx',
            'form_data' => [
                'rows' => [[
                    'pillar' => 'Pillar 1: ACCESS',
                    'strategic_objective' => 'SO 1.1',
                    'strategy' => 'Improve early literacy',
                    'ppa' => 'Reading intervention',
                    'resources' => 'Learning materials',
                    'budget' => 'c/o approved WFP',
                    'persons_responsible' => 'School Head',
                    'timeline' => 'June to March',
                ]],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('aip.index'))
            ->assertOk()
            ->assertSee('aria-haspopup="dialog"', false)
            ->assertSee('aip-record-preview-title-'.$plan->id, false)
            ->assertSee('Reading intervention')
            ->assertSee('Improve early literacy')
            ->assertSee('school-aip.docx')
            ->assertDontSee('aip-record-preview-dialog__actions', false)
            ->assertDontSee('Download original DOCX')
            ->assertDontSee('href="'.route('aip.plans.preview', ['plan' => $plan->id]).'"', false);
    }

    public function test_upload_preview_shows_completed_aip_items_as_readable_cards(): void
    {
        $user = $this->schoolHead();

        Livewire::actingAs($user)
            ->test(AipSubmissionUpload::class)
            ->call('openModal')
            ->set('detectedYear', 2027)
            ->set('originalName', 'school-aip.docx')
            ->set('analysisMessage', 'AIP rows found.')
            ->set('previewRows', [
                [
                    'pillar' => 'Pillar 1: ACCESS',
                    'strategic_objective' => 'SO 1.1',
                    'strategy' => 'Improve early literacy',
                    'ppa' => 'Reading intervention',
                    'resources' => 'Learning materials',
                    'budget' => 'c/o approved WFP',
                    'persons_responsible' => 'School Head',
                    'timeline' => 'June to March',
                ],
                [
                    'pillar' => 'Pillar 1: ACCESS',
                    'strategic_objective' => 'SO 1.1',
                    'strategy' => '',
                    'ppa' => '',
                    'resources' => '',
                    'budget' => '',
                    'persons_responsible' => '',
                    'timeline' => '',
                ],
            ])
            ->assertSee('Pillar 1: ACCESS')
            ->assertSee('Reading intervention')
            ->assertSee('Improve early literacy')
            ->assertSee('1 completed item')
            ->assertDontSee('aip-upload-preview__table')
            ->assertDontSee('SO 1.1</h4>');
    }

    public function test_viewers_can_open_the_aip_review_queue_but_not_author_aips(): void
    {
        $this->get(route('aip.review'))->assertRedirect(route('login'));
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $this->actingAs($viewer)
            ->get(route('aip.review'))
            ->assertOk()
            ->assertSee('Review AIP')
            ->assertSee('AIP submissions');

        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);
        $this->actingAs($budget)->get(route('aip.review'))->assertOk();
        $this->actingAs($this->superadmin())->get(route('aip.review'))->assertOk();

        $user = $this->schoolHead();
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $district->id]);
        $user->update(['school_id' => $school->id]);
        $plan = AipPlan::create([
            'school_id' => $school->id,
            'year' => 2026,
            'created_by' => $user->id,
        ]);

        $this->actingAs($budget)->get(route('aip.template.download'))->assertNotFound();
        $this->actingAs($budget)->get(route('aip.plans.preview', ['plan' => $plan->id]))->assertNotFound();
        $this->actingAs($budget)->get(route('aip.plans.source', ['plan' => $plan->id]))->assertNotFound();
        $this->get(route('aip.plans.edit', ['plan' => $plan->id]))->assertNotFound();
        $this->get(route('aip.plans.print', ['plan' => $plan->id]))->assertNotFound();
        $this->get(route('aip.plans.export', ['plan' => $plan->id]))->assertNotFound();

        $this->actingAs($budget)->get(route('aip.index'))->assertNotFound();
        $this->get(route('wfp.index'))->assertNotFound();
        $this->get(route('opcrf.review'))->assertNotFound();
        $this->get(route('users.index'))->assertNotFound();
    }

    public function test_viewers_can_approve_or_return_submitted_aips(): void
    {
        $owner = $this->schoolHead();
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $district->id]);
        $owner->update(['school_id' => $school->id]);

        $approvedPlan = AipPlan::create([
            'school_id' => $school->id,
            'year' => 2026,
            'created_by' => $owner->id,
        ]);
        $approvedPlan->submit($owner);

        $returnedPlan = AipPlan::create([
            'school_id' => $school->id,
            'year' => 2027,
            'created_by' => $owner->id,
        ]);
        $returnedPlan->submit($owner);

        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);

        Livewire::actingAs($viewer)
            ->test(\App\Livewire\AipReview::class)
            ->call('openReview', $approvedPlan->id)
            ->call('approve')
            ->assertSet('successMessage', 'AIP fully approved.');

        $this->assertSame(AipPlan::STATUS_APPROVED, $approvedPlan->fresh()->status);
        $this->assertSame($viewer->id, $approvedPlan->fresh()->approved_by);

        Livewire::actingAs($viewer)
            ->test(\App\Livewire\AipReview::class)
            ->call('openReview', $returnedPlan->id)
            ->set('reviewRemarks', 'Please revise the activity targets.')
            ->call('returnForRevision')
            ->assertSet('successMessage', 'AIP returned for revision.');

        $this->assertSame(AipPlan::STATUS_RETURNED, $returnedPlan->fresh()->status);
        $this->assertSame('Please revise the activity targets.', $returnedPlan->fresh()->review_remarks);
    }

    public function test_aip_approval_routes_from_rebonie_to_eve_then_sy(): void
    {
        Storage::fake('local');
        config([
            'aip.review_route' => 'Rebonie',
            'aip.next_reviewer' => 'Eve',
            'aip.final_reviewer' => 'SY',
        ]);

        $rebonie = User::factory()->create([
            'name' => 'Rebonie F. Emboltorio',
            'username' => 'Rebonie',
            'role' => 'viewer',
            'is_superadmin' => false,
        ]);
        $eve = $this->superadmin();
        $sy = User::factory()->create([
            'name' => 'Superadmin SY',
            'username' => 'SY',
            'role' => 'superadmin',
            'is_superadmin' => true,
        ]);
        $owner = $this->schoolHead();
        $this->addSignatureTo($owner);
        $this->addSignatureTo($eve);
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $district->id]);
        $owner->update(['school_id' => $school->id]);
        $plan = AipPlan::create([
            'school_id' => $school->id,
            'year' => 2027,
            'created_by' => $owner->id,
        ]);

        $this->assertSame('Eve', config('aip.next_reviewer'));
        $this->assertSame($eve->id, AipPlan::nextConfiguredReviewer($rebonie)?->id);
        $plan->submit($owner);
        $this->assertSame($rebonie->id, $plan->fresh()->reviewer_id);
        $this->assertSame($rebonie->id, $plan->fresh()->assigned_to);

        Livewire::actingAs($rebonie)
            ->test(\App\Livewire\AipReview::class)
            ->call('openReview', $plan->id)
            ->assertSee(route('aip.signature', ['plan' => $plan, 'signer' => $owner]), false)
            ->assertDontSee(route('aip.signature', ['plan' => $plan, 'signer' => $eve]), false)
            ->call('approve')
            ->assertSet('successMessage', 'AIP approved and forwarded to Eve.');

        $this->actingAs($rebonie)
            ->get(route('aip.signature', ['plan' => $plan, 'signer' => $owner]))
            ->assertOk()
            ->assertStreamedContent((string) base64_decode(self::PNG, true));

        $plan->refresh();
        $this->assertSame(AipPlan::STATUS_FORWARDED, $plan->status);
        $this->assertSame($eve->id, $plan->assigned_to);
        $this->assertNull($plan->approved_at);
        $this->assertNull($plan->approved_by);
        $this->assertFalse($plan->isAssignedTo($rebonie));
        $this->assertTrue($plan->isAssignedTo($eve));

        Livewire::actingAs($eve)
            ->test(\App\Livewire\AipReview::class)
            ->call('openReview', $plan->id)
            ->call('approve')
            ->assertSet('successMessage', 'AIP approved and forwarded to Superadmin SY.');

        $plan->refresh();
        $this->assertSame(AipPlan::STATUS_FORWARDED, $plan->status);
        $this->assertSame($sy->id, $plan->assigned_to);
        $this->assertNull($plan->approved_at);
        $this->assertFalse($plan->isAssignedTo($eve));
        $this->assertTrue($plan->isAssignedTo($sy));
        $eveQueue = Livewire::actingAs($eve)->test(\App\Livewire\AipReview::class);
        $this->assertSame(1, $eveQueue->instance()->plans->total());
        $this->assertNotNull(AipPlan::query()->visibleTo($eve)->find($plan->id));
        Livewire::actingAs($eve)
            ->test(\App\Livewire\AipReview::class)
            ->call('openReview', $plan->id)
            ->assertSet('showReview', true)
            ->assertSee('Forwarded to Superadmin SY');
        Livewire::actingAs($eve)
            ->test(\App\Livewire\AipReview::class)
            ->call('openReview', $plan->id)
            ->call('approve')
            ->assertForbidden();

        Livewire::actingAs($sy)
            ->test(\App\Livewire\AipReview::class)
            ->call('openReview', $plan->id)
            ->assertSee(route('aip.signature', ['plan' => $plan, 'signer' => $owner]), false)
            ->assertSee(route('aip.signature', ['plan' => $plan, 'signer' => $eve]), false);

        $this->actingAs($sy)
            ->get(route('aip.signature', ['plan' => $plan, 'signer' => $eve]))
            ->assertOk()
            ->assertStreamedContent((string) base64_decode(self::PNG, true));

        $unrelated = User::factory()->create();
        $this->addSignatureTo($unrelated);
        $this->actingAs($sy)
            ->get(route('aip.signature', ['plan' => $plan, 'signer' => $unrelated]))
            ->assertNotFound();

        Livewire::actingAs($sy)
            ->test(\App\Livewire\AipReview::class)
            ->call('openReview', $plan->id)
            ->call('approve')
            ->assertSet('successMessage', 'AIP fully approved.');

        $plan->refresh();
        $this->assertSame(AipPlan::STATUS_APPROVED, $plan->status);
        $this->assertSame($sy->id, $plan->approved_by);
        $this->assertNotNull($plan->approved_at);
        $this->assertSame(4, $plan->reviews()->count());
    }

    public function test_only_the_assigned_reviewer_can_delete_an_aip(): void
    {
        config([
            'aip.review_route' => 'Rebonie',
            'aip.next_reviewer' => 'Eve',
            'aip.final_reviewer' => 'SY',
        ]);
        $reviewer = User::factory()->create([
            'username' => 'Rebonie',
            'role' => 'viewer',
            'is_superadmin' => false,
        ]);
        $otherViewer = User::factory()->create([
            'username' => 'OtherViewer',
            'role' => 'viewer',
            'is_superadmin' => false,
        ]);
        $owner = $this->schoolHead();
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $district->id]);
        $owner->update(['school_id' => $school->id]);
        $plan = AipPlan::create([
            'school_id' => $school->id,
            'year' => 2027,
            'created_by' => $owner->id,
        ]);
        $plan->submit($owner);

        Livewire::actingAs($reviewer)
            ->test(\App\Livewire\AipReview::class)
            ->dispatch('aip-plan-submitted', planId: $plan->id);
        $this->assertDatabaseHas('aip_plans', ['id' => $plan->id]);

        Livewire::actingAs($otherViewer)
            ->test(\App\Livewire\AipReview::class)
            ->assertDontSee('Delete this AIP submission?')
            ->call('deletePlan', $plan->id)
            ->assertForbidden();
        $this->assertDatabaseHas('aip_plans', ['id' => $plan->id]);

        Livewire::actingAs($reviewer)
            ->test(\App\Livewire\AipReview::class)
            ->assertSee('Delete this AIP submission?')
            ->call('deletePlan', $plan->id)
            ->assertSet('successMessage', 'AIP deleted.');
        $this->assertDatabaseMissing('aip_plans', ['id' => $plan->id]);
    }

    public function test_viewers_can_open_submitted_incomplete_aips(): void
    {
        $owner = $this->schoolHead();
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $district->id]);
        $owner->update(['school_id' => $school->id]);

        $plan = AipPlan::create([
            'school_id' => $school->id,
            'year' => 2027,
            'created_by' => $owner->id,
            'form_data' => [
                'rows' => [[
                    'pillar' => 'Pillar 1: ACCESS',
                    'strategic_objective' => 'Partial objective',
                    'strategy' => '',
                    'ppa' => 'Partial activity',
                    'resources' => '',
                    'budget' => '',
                    'persons_responsible' => '',
                    'timeline' => '',
                ]],
            ],
        ]);
        $plan->submit($owner);

        // A submitted plan with legacy draft status must remain visible too.
        $plan->update(['status' => AipPlan::STATUS_DRAFT]);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);

        Livewire::actingAs($viewer)
            ->test(\App\Livewire\AipReview::class)
            ->assertSee('Alpha Elementary School')
            ->assertSee('CY 2027')
            ->call('openReview', $plan->id)
            ->assertSee('Partial objective')
            ->assertSee('Partial activity');
    }

    public function test_school_head_can_submit_an_existing_incomplete_draft_for_viewer_review(): void
    {
        $owner = $this->schoolHead();
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $district->id]);
        $owner->update(['school_id' => $school->id]);
        $plan = AipPlan::create([
            'school_id' => $school->id,
            'year' => 2027,
            'created_by' => $owner->id,
            'form_data' => [
                'rows' => [[
                    'pillar' => 'Pillar 1: ACCESS',
                    'strategic_objective' => 'Not finished objective',
                    'strategy' => '',
                    'ppa' => '',
                    'resources' => '',
                    'budget' => '',
                    'persons_responsible' => '',
                    'timeline' => '',
                ]],
            ],
        ]);

        $this->actingAs($owner)
            ->get(route('aip.index'))
            ->assertOk()
            ->assertSee('Not visible to reviewers until submitted.')
            ->assertSee('Submit for review');

        $this->post(route('aip.plans.submit', ['plan' => $plan->id]))
            ->assertRedirect(route('aip.index'))
            ->assertSessionHas('status', 'Annual Implementation Plan CY 2027 submitted and is Pending Review.');

        $this->assertSame(AipPlan::STATUS_PENDING, $plan->fresh()->status);
        $this->assertNotNull($plan->fresh()->submitted_at);
        $this->assertSame(1, $plan->versions()->count());

        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        Livewire::actingAs($viewer)
            ->test(\App\Livewire\AipReview::class)
            ->assertSee('Alpha Elementary School')
            ->call('openReview', $plan->id)
            ->assertSee('Not finished objective');

        $otherOwner = $this->schoolHead();
        $this->actingAs($otherOwner)
            ->post(route('aip.plans.submit', ['plan' => $plan->id]))
            ->assertNotFound();

        $this->assertSame(AipPlan::STATUS_PENDING, $plan->fresh()->status);
    }

    public function test_budget_dashboard_only_shows_review_workspaces(): void
    {
        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);

        $this->actingAs($budget)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('BUDGET REVIEW WORKSPACE')
            ->assertDontSee('Review AIP')
            ->assertDontSee('Review submitted AIP plans')
            ->assertSee('WFP Uploads')
            ->assertDontSee('Upload AIP')
            ->assertDontSee('Performance plan')
            ->assertDontSee('Open MOV checklist');
    }
}
