<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Livewire\WfpUpload;
use App\Livewire\WfpUploadsList;
use App\Models\District;
use App\Models\School;
use App\Models\User;
use App\Models\WfpSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsWfpTemplates;
use Tests\TestCase;

/**
 * The WFP upload round trip: the School Head uploads their completed
 * workbook on /wfp, a row lands in wfp_submissions, and the superadmin's
 * dashboard lists every school's upload with a download of the archived
 * file. Covers the guards on both ends — only SHs upload, only admins list,
 * and only the owner or an admin can fetch a file.
 */
class WfpUploadsListTest extends TestCase
{
    use BuildsWfpTemplates;
    use RefreshDatabase;

    private function schoolHead(string $username, string $schoolName): User
    {
        $district = District::create(['name' => 'District '.$username]);
        $school = School::create(['district_id' => $district->id, 'name' => $schoolName]);

        return User::create([
            'name' => ucfirst($username),
            'username' => $username,
            'email' => $username.'@example.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);
    }

    private function superadmin(): User
    {
        return User::create([
            'name' => 'Root Admin',
            'username' => 'rootadmin',
            'email' => 'rootadmin@example.com',
            'password' => Hash::make('password123'),
            'role' => 'superadmin',
            'is_superadmin' => true,
        ]);
    }

    /** A workbook-shaped .xlsx that passes WfpSpreadsheet::validateStructure(). */
    private function wfpFile(string $name = 'completed-wfp.xlsx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->wfpTemplateBytes(3));
    }

    /** One archived upload row with a real file behind it on the local disk. */
    private function storedSubmission(User $head, string $fileName): WfpSubmission
    {
        $path = 'wfp/'.$head->id.'/'.uniqid().'-'.$fileName;
        Storage::disk('local')->put($path, 'fake-xlsx-bytes');

        return WfpSubmission::create([
            'user_id' => $head->id,
            'original_file_name' => $fileName,
            'file_path' => $path,
            'file_size' => 512000,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_type' => 'xlsx',
            'school_year' => 'CY 2026',
            'school_name' => null,
            'status' => WfpSubmission::STATUS_UPLOADED,
            'uploaded_at' => now(),
        ]);
    }

    public function test_the_wfp_page_offers_the_upload_action(): void
    {
        $this->actingAs($this->schoolHead('schoolhead', 'Smoke School'))
            ->get(route('wfp.index'))
            ->assertOk()
            ->assertSee('Generate and Download Work and Financial Plan')
            ->assertSee('Upload WFP');
    }

    public function test_the_school_head_can_upload_their_completed_wfp(): void
    {
        Storage::fake('local');
        $head = $this->schoolHead('schoolhead', 'Smoke School');

        Livewire::actingAs($head)
            ->test(WfpUpload::class)
            ->set('file', $this->wfpFile('completed-wfp.xlsx'))
            ->assertHasNoErrors()
            ->assertSet('showUpload', false);

        $this->assertDatabaseHas('wfp_submissions', [
            'user_id' => $head->id,
            'original_file_name' => 'completed-wfp.xlsx',
            'status' => WfpSubmission::STATUS_UPLOADED,
        ]);
    }

    public function test_an_invalid_workbook_is_rejected_and_nothing_is_saved(): void
    {
        Storage::fake('local');
        $head = $this->schoolHead('schoolhead', 'Smoke School');

        $fake = UploadedFile::fake()->createWithContent('not-a-wfp.xlsx', 'this is not a zip archive');

        Livewire::actingAs($head)
            ->test(WfpUpload::class)
            ->set('file', $fake)
            ->assertHasErrors('file');

        $this->assertDatabaseCount('wfp_submissions', 0);
    }

    public function test_a_reupload_replaces_the_schools_previous_row(): void
    {
        Storage::fake('local');
        $head = $this->schoolHead('schoolhead', 'Smoke School');

        Livewire::actingAs($head)
            ->test(WfpUpload::class)
            ->set('file', $this->wfpFile('first-draft.xlsx'));

        Livewire::actingAs($head)
            ->test(WfpUpload::class)
            ->set('file', $this->wfpFile('final-plan.xlsx'));

        // One active row per school: the list never shows two generations
        // of the same school's plan.
        $this->assertDatabaseCount('wfp_submissions', 1);
        $this->assertDatabaseHas('wfp_submissions', [
            'user_id' => $head->id,
            'original_file_name' => 'final-plan.xlsx',
        ]);
    }

    public function test_the_superadmin_dashboard_lists_every_schools_upload(): void
    {
        Storage::fake('local');

        $bino = $this->schoolHead('binohed', 'Bino Elementary School');
        $isidro = $this->schoolHead('isidrohed', 'San Isidro Elementary School');

        $this->storedSubmission($bino, 'bino-wfp.xlsx');
        $this->storedSubmission($isidro, 'isidro-wfp.xlsx');

        $this->actingAs($this->superadmin())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('WFP Uploads')
            ->assertSee('2 schools uploaded')
            ->assertSee('Bino Elementary School')
            ->assertSee('San Isidro Elementary School')
            ->assertSee('binohed')
            ->assertSee('isidrohed')
            ->assertSee('bino-wfp.xlsx')
            ->assertSee('isidro-wfp.xlsx');
    }

    public function test_the_upload_list_shows_an_empty_state_before_any_school_upload(): void
    {
        $this->actingAs($this->superadmin())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('No WFP uploads yet');
    }

    public function test_the_upload_list_is_hidden_from_staff(): void
    {
        Storage::fake('local');
        $head = $this->schoolHead('schoolhead', 'Smoke School');
        $this->storedSubmission($head, 'hidden-wfp.xlsx');

        $this->actingAs($head)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('WFP Uploads');

        $html = Livewire::actingAs($head)
            ->test(WfpUploadsList::class)
            ->html();

        $this->assertStringContainsString('Page Not Found', $html);
    }

    public function test_the_superadmin_can_download_an_uploaded_wfp(): void
    {
        Storage::fake('local');
        $head = $this->schoolHead('schoolhead', 'Smoke School');
        $submission = $this->storedSubmission($head, 'Completed Plan.xlsx');

        $this->actingAs($this->superadmin())
            ->get(route('wfp.submission.download', $submission))
            ->assertOk()
            ->assertDownload('Completed Plan - Work and Financial Plan.xlsx');
    }

    public function test_the_owner_can_download_their_own_upload(): void
    {
        Storage::fake('local');
        $head = $this->schoolHead('schoolhead', 'Smoke School');
        $submission = $this->storedSubmission($head, 'own-plan.xlsx');

        $this->actingAs($head)
            ->get(route('wfp.submission.download', $submission))
            ->assertOk()
            ->assertDownload('own-plan - Work and Financial Plan.xlsx');
    }

    public function test_the_admin_sidebar_links_the_wfp_uploads_page(): void
    {
        Storage::fake('local');
        $head = $this->schoolHead('schoolhead', 'Smoke School');
        $this->storedSubmission($head, 'listed-wfp.xlsx');

        $this->actingAs($this->superadmin());

        // The sidebar offers WFP under Files, pointing at the uploads page.
        $html = Livewire::test(Sidebar::class)->html();
        $this->assertStringContainsString(route('wfp.submissions.index'), $html);

        // The page it points at is the per-school list.
        $this->get(route('wfp.submissions.index'))
            ->assertOk()
            ->assertSee('WFP Uploads')
            ->assertSee('Smoke School')
            ->assertSee('listed-wfp.xlsx');
    }

    public function test_budget_role_can_review_wfp_uploads_and_download_them_but_cannot_open_authoring(): void
    {
        Storage::fake('local');
        $head = $this->schoolHead('schoolhead', 'Smoke School');
        $submission = $this->storedSubmission($head, 'budget-review-wfp.xlsx');
        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);

        $items = collect(Livewire::actingAs($budget)->test(Sidebar::class)->instance()->items());
        $wfp = $items->firstWhere('label', 'WFP');

        $this->assertSame('wfp.submissions.index', $wfp['route']);
        $this->assertSame(
            route('wfp.submissions.index'),
            collect($wfp['children'])->firstWhere('label', 'Work & Financial Plan')['url']
        );
        $this->assertNull($items->firstWhere('label', 'WFP Uploads'));

        $this->actingAs($budget)
            ->get(route('wfp.submissions.index'))
            ->assertOk()
            ->assertSee('WFP Uploads')
            ->assertSee('budget-review-wfp.xlsx');

        $this->get(route('wfp.submission.download', $submission))
            ->assertOk()
            ->assertDownload('budget-review-wfp - Work and Financial Plan.xlsx');

        $this->get(route('wfp.index'))->assertNotFound();
    }

    public function test_viewer_can_open_wfp_review_and_download_a_school_heads_upload(): void
    {
        Storage::fake('local');
        $head = $this->schoolHead('schoolhead', 'Smoke School');
        $submission = $this->storedSubmission($head, 'viewer-review-wfp.xlsx');
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);

        $items = collect(Livewire::actingAs($viewer)->test(Sidebar::class)->instance()->items());
        $this->assertSame(
            'wfp.submissions.index',
            $items->firstWhere('label', 'WFP Review')['route']
        );

        $this->actingAs($viewer)
            ->get(route('wfp.submissions.index'))
            ->assertOk()
            ->assertSee('WFP Review')
            ->assertSee('Smoke School')
            ->assertSee('viewer-review-wfp.xlsx');

        $this->get(route('wfp.submission.download', $submission))
            ->assertOk()
            ->assertDownload('viewer-review-wfp - Work and Financial Plan.xlsx');

        $this->get(route('wfp.index'))->assertNotFound();
    }

    public function test_staff_do_not_get_the_wfp_sidebar_item_or_the_admin_page(): void
    {
        $head = $this->schoolHead('schoolhead', 'Smoke School');

        // Guests are bounced to login.
        $this->get(route('wfp.submissions.index'))->assertRedirect(route('login'));

        // Staff keep their own /wfp workspace; no admin WFP link for them.
        $html = Livewire::actingAs($head)->test(Sidebar::class)->html();
        $this->assertStringNotContainsString(route('wfp.submissions.index'), $html);

        $this->actingAs($head)
            ->get(route('wfp.submissions.index'))
            ->assertNotFound();
    }

    public function test_another_school_and_guests_cannot_download_it(): void
    {
        Storage::fake('local');
        $head = $this->schoolHead('schoolhead', 'Smoke School');
        $other = $this->schoolHead('otherhead', 'Other School');
        $submission = $this->storedSubmission($head, 'private-plan.xlsx');

        // Guests never reach the file — the auth middleware bounces them.
        $this->get(route('wfp.submission.download', $submission))
            ->assertRedirect(route('login'));

        // A different school's head — not the owner, not an admin.
        $this->actingAs($other)
            ->get(route('wfp.submission.download', $submission))
            ->assertNotFound();
    }
}
