<?php

namespace Tests\Feature;

use App\Livewire\WfpGenerateButton;
use App\Models\AccountClassification;
use App\Models\District;
use App\Models\School;
use App\Models\User;
use App\Support\WfpAccountCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\BuildsWfpTemplates;
use Tests\TestCase;

/**
 * The user-side WFP page: the /wfp route, its role guards, and the
 * "Generate and Download WFP" action. The workbook is produced on the server
 * from the account-code master list — there is no upload round-trip — so the
 * download either yields a real .xlsx or reports a toastable error.
 */
class WfpPageTest extends TestCase
{
    use BuildsWfpTemplates;
    use RefreshDatabase;

    private function schoolHead(string $username = 'schoolhead'): User
    {
        $district = District::create(['name' => 'District '.$username]);
        $school = School::create(['district_id' => $district->id, 'name' => 'Smoke School']);

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

    private function classification(string $title, string $code, bool $active = true): AccountClassification
    {
        return AccountClassification::create([
            'account_title' => $title,
            'rca_code' => 'RCA-'.$code,
            'uacs_sub_object_code' => 'SUB-'.$code,
            'uacs_object_code' => $code,
            'covering_ppas' => null,
            'is_active' => $active,
        ]);
    }

    public function test_the_school_head_opens_the_wfp_page_with_the_generate_action(): void
    {
        $this->actingAs($this->schoolHead())
            ->get(route('wfp.index'))
            ->assertOk()
            ->assertSee('Work and Financial Plan')
            ->assertSee('Generate and Download WFP');
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

    public function test_the_school_head_can_generate_and_download_the_wfp(): void
    {
        $head = $this->schoolHead();

        $this->classification('Travelling Expenses - Local', '5020101000');
        $this->classification('Office Supplies Expenses', '5020301000');

        // A synthetic template so the test does not depend on the shipped
        // file's exact layout.
        $name = 'test-wfp-'.uniqid().'.xlsx';
        file_put_contents(storage_path('forms/'.$name), $this->wfpTemplateBytes(3));
        config(['wfp.template_file' => $name]);

        try {
            $expected = WfpAccountCodeGenerator::downloadName($head);

            Livewire::actingAs($head)
                ->test(WfpGenerateButton::class)
                ->call('download')
                ->assertFileDownloaded($expected);
        } finally {
            @unlink(storage_path('forms/'.$name));
        }
    }

    public function test_generating_with_no_account_codes_reports_an_error_and_downloads_nothing(): void
    {
        $head = $this->schoolHead();

        Livewire::actingAs($head)
            ->test(WfpGenerateButton::class)
            ->call('download')
            ->assertSet('errorMessage', fn ($value): bool => is_string($value) && $value !== '')
            ->assertNoFileDownloaded();
    }

    public function test_a_non_school_head_cannot_reach_the_generate_button(): void
    {
        $this->classification('Office Supplies Expenses', '5020301000');

        $html = Livewire::actingAs($this->viewer())
            ->test(WfpGenerateButton::class)
            ->html();

        $this->assertStringContainsString('Page Not Found', $html);
    }
}
