<?php

namespace Tests\Feature;

use App\Livewire\WfpManager;
use App\Livewire\WfpUpload;
use App\Models\User;
use App\Models\WfpSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The user-side WFP page: the /wfp route, its role guards, and the manager
 * component's submission lookup — the query that 500'd with "Table
 * 'smartapp.wfp_submissions' doesn't exist" when the create migration was
 * never run. RefreshDatabase runs every migration, so these tests fail if
 * the table (or any column the query needs) is ever missing again.
 */
class WfpPageTest extends TestCase
{
    use RefreshDatabase;

    private function schoolHead(string $username = 'schoolhead'): User
    {
        return User::create([
            'name' => ucfirst($username),
            'username' => $username,
            'email' => $username.'@example.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
            'is_superadmin' => false,
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

    private function viewer(): User
    {
        return User::create([
            'name' => 'SDS Viewer',
            'username' => 'viewer',
            'email' => 'viewer@example.com',
            'password' => Hash::make('password123'),
            'role' => 'viewer',
            'is_superadmin' => false,
        ]);
    }

    public function test_school_head_opens_the_wfp_page_with_the_empty_state(): void
    {
        $this->actingAs($this->schoolHead())
            ->get(route('wfp.index'))
            ->assertOk()
            ->assertSee('Work and Financial Plan')
            ->assertSee('No WFP uploaded yet');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('wfp.index'))->assertRedirect(route('login'));
    }

    public function test_superadmins_cannot_open_the_wfp_page(): void
    {
        $this->actingAs($this->superadmin())
            ->get(route('wfp.index'))
            ->assertNotFound();
    }

    public function test_viewers_cannot_open_the_wfp_page(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('wfp.index'))
            ->assertNotFound();
    }

    public function test_the_manager_reads_the_users_most_recent_submission(): void
    {
        $head = $this->schoolHead();

        WfpSubmission::create([
            'user_id' => $head->id,
            'original_file_name' => 'WFP-older.xlsx',
            'file_path' => 'wfp/'.$head->id.'/older.xlsx',
            'uploaded_at' => now()->subDay(),
        ]);

        $newest = WfpSubmission::create([
            'user_id' => $head->id,
            'original_file_name' => 'WFP-newest.xlsx',
            'file_path' => 'wfp/'.$head->id.'/newest.xlsx',
            'uploaded_at' => now(),
        ]);

        $component = Livewire::actingAs($head)
            ->test(WfpManager::class)
            ->assertOk();

        $this->assertSame($newest->id, $component->instance()->submission->id);
        $component->assertSee('WFP-newest.xlsx');
    }

    public function test_another_accounts_submission_is_never_shown(): void
    {
        $head = $this->schoolHead();
        $colleague = $this->schoolHead('colleague');

        WfpSubmission::create([
            'user_id' => $colleague->id,
            'original_file_name' => 'Not-yours.xlsx',
            'file_path' => 'wfp/'.$colleague->id.'/not-yours.xlsx',
            'uploaded_at' => now(),
        ]);

        $component = Livewire::actingAs($head)->test(WfpManager::class)->assertOk();

        $this->assertNull($component->instance()->submission);
        $component->assertDontSee('Not-yours.xlsx');
    }

    public function test_the_school_head_can_review_analysis_for_every_saved_worksheet(): void
    {
        $head = $this->schoolHead();
        $submission = WfpSubmission::create([
            'user_id' => $head->id,
            'original_file_name' => 'School-WFP.xlsx',
            'file_path' => 'wfp/'.$head->id.'/school-wfp.xlsx',
            'sheet_data' => [
                [
                    'name' => 'Overview',
                    'column_indexes' => [1],
                    'header_index' => null,
                    'rows' => [['number' => 1, 'cells' => [1 => 'Work plan overview']]],
                ],
                [
                    'name' => 'Activities',
                    'column_indexes' => [1],
                    'header_index' => null,
                    'rows' => [['number' => 1, 'cells' => [1 => 'Reading recovery activity']]],
                ],
            ],
            'analysis' => [
                'sheet_count' => 2,
                'row_count' => 2,
                'populated_cell_count' => 2,
                'sections_found' => 2,
                'sections_required' => 3,
                'required_headings' => [['label' => 'Work and Financial Plan', 'found' => true]],
                'sections' => [['label' => 'Objectives', 'found' => true]],
                'sheets' => [],
            ],
            'validation' => ['passed' => true, 'errors' => [], 'warnings' => []],
        ]);

        $component = Livewire::actingAs($head)->test(WfpManager::class)->assertOk();

        $this->assertSame($submission->id, $component->instance()->submission->id);
        $component
            ->assertSee('WFP Analysis & Review')
            ->assertSee('Overview')
            ->assertSee('Activities')
            ->assertSee('Reading recovery activity')
            ->assertSee('Populated rows reviewed');
        $component->assertSee('<strong>2</strong>', false);
    }

    public function test_uploading_the_official_wfp_saves_its_full_workbook_analysis(): void
    {
        Storage::fake('local');
        $head = $this->schoolHead();
        $template = storage_path('forms/'.config('wfp.template_file'));

        $this->assertFileExists($template);

        Livewire::actingAs($head)
            ->test(WfpUpload::class)
            ->call('openUpload')
            ->set('file', UploadedFile::fake()->createWithContent(
                'Completed-WFP.xlsx',
                (string) file_get_contents($template),
            ))
            ->assertHasNoErrors();

        $submission = WfpSubmission::where('user_id', $head->id)->firstOrFail();

        Storage::disk('local')->assertExists($submission->file_path);
        $this->assertNotEmpty($submission->sheet_data);
        $this->assertGreaterThan(0, $submission->analysis['sheet_count']);
        $this->assertGreaterThan(0, $submission->analysis['row_count']);
        $this->assertTrue($submission->validation['passed']);

        Livewire::actingAs($head)
            ->test(WfpManager::class)
            ->assertSee('WFP Analysis & Review')
            ->assertSee('Worksheets analyzed');
    }
}
