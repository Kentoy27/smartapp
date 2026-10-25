<?php

namespace Tests\Feature;

use App\Livewire\WfpGenerateButton;
use App\Livewire\WfpMyUploadsList;
use App\Models\AccountClassification;
use App\Models\District;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Models\WfpPlan;
use App\Models\WfpSubmission;
use App\Support\WfpAccountCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    private function upload(User $owner, string $name, ?string $year = null): WfpSubmission
    {
        $path = 'wfp/'.$owner->id.'/'.$name;
        Storage::disk('local')->put($path, 'wfp-bytes');

        return WfpSubmission::create([
            'user_id' => $owner->id,
            'original_file_name' => $name,
            'file_path' => $path,
            'file_size' => 9,
            'file_type' => 'xlsx',
            'school_year' => $year,
            'status' => WfpSubmission::STATUS_UPLOADED,
            'uploaded_at' => now(),
        ]);
    }

    /**
     * One matrix for the given year carrying one line, written the way the app
     * writes a line: the total is computed server-side, never taken from a
     * request.
     */
    private function matrixWithLine(User $head, int $year, string $activity, string $code, int $month = 3): MonthlyExpenditureMatrix
    {
        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $head->school_id,
            'year' => $year,
            'month' => $month,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
        ]);

        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'objective' => 'To keep the school lit',
            'activity_description' => $activity,
            'quantity' => 1,
            'unit' => 'bill',
            'unit_cost' => 6000,
            'account_title' => 'Electricity Expense',
            'account_code' => $code,
        ]);
        $item->syncTotal();
        $item->save();

        return $matrix;
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

    public function test_the_download_is_the_shipped_form_stamped_with_the_school_its_year_and_its_accounts(): void
    {
        if (! is_file(storage_path('forms/WFP-Template.xlsx'))) {
            $this->markTestSkipped('The shipped WFP template is not present.');
        }

        $head = $this->schoolHead();
        $this->classification('Travelling Expenses-Local', '5020101000');

        $component = Livewire::actingAs($head)->test(WfpGenerateButton::class)->call('download');

        // The workbook actually handed to the browser, opened as a workbook.
        $bytes = base64_decode((string) data_get($component->effects, 'download.content'));
        $sheet = $this->wfpSheetXml($bytes);

        // The school's own copy: named in the title block, headed for the year
        // it is generated for, its account named and coded on the first line
        // of the table.
        $this->assertSame('Smoke School', $this->wfpCellText($sheet, 'A6'));
        $this->assertSame('CY '.now()->year.' Work and Financial Plan', $this->wfpCellText($sheet, 'A9'));
        $this->assertSame('Travelling Expenses-Local', $this->wfpCellText($sheet, 'A14'));
        $this->assertSame('5020101000', $this->wfpCellText($sheet, 'S14'));

        // The form's own machinery — the Total row — is intact, and the
        // export is trimmed to the single account line it carries, with the
        // signature block moved up beneath it.
        $this->assertSame('Total', $this->wfpCellText($sheet, 'A15'));
        $this->assertSame('SUM(R14:R14)', $this->wfpCellFormula($sheet, 'R15'));
        $this->assertSame('Prepared by:', $this->wfpCellText($sheet, 'B18'));
    }

    /**
     * Once the school's matrix carries lines, the button hands back the WFP
     * generated FROM them — the filled form — rather than the blank account-code
     * form, because the blank form is not what the School Head has been working
     * towards.
     */
    public function test_the_button_downloads_the_plan_generated_from_the_matrix_when_there_is_one(): void
    {
        if (! is_file(storage_path('forms/WFP-Template.xlsx'))) {
            $this->markTestSkipped('The shipped WFP template is not present.');
        }

        $head = $this->schoolHead();

        // An account code the blank form would have written, so the two
        // workbooks cannot be confused.
        $this->classification('Travelling Expenses-Local', '5020101000');

        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $head->school_id,
            'year' => 2026,
            'month' => 3,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
        ]);
        // Written the way the app writes a line: the total is computed
        // server-side, never taken from a request.
        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'objective' => 'To keep the school lit',
            'activity_description' => 'Payment for Electricity',
            'quantity' => 1,
            'unit' => 'bill',
            'unit_cost' => 6000,
            'account_title' => 'Electricity Expense',
            'account_code' => '5020402000',
        ]);
        $item->syncTotal();
        $item->save();

        $component = Livewire::actingAs($head)->test(WfpGenerateButton::class)->call('download');

        // Still named for the school and the year the plan belongs to.
        $component->assertFileDownloaded(WfpAccountCodeGenerator::downloadName($head, 2026));

        $bytes = base64_decode((string) data_get($component->effects, 'download.content'));
        $sheet = $this->wfpSheetXml($bytes);

        // The account-title gray section row heads the account group at row
        // 14, so the plan's own line lands at row 15: March is physical column
        // G, the financial band alternates month/object-code columns, so March
        // is V with the object code in W.
        $this->assertSame('Electricity Expense', $this->wfpCellText($sheet, 'A14'));
        $this->assertSame('To keep the school lit', $this->wfpCellText($sheet, 'A15'));
        $this->assertSame('Payment for Electricity', $this->wfpCellText($sheet, 'B15'));
        $this->assertSame('1.00', $this->wfpCellText($sheet, 'G15'));
        $this->assertSame('6000.00', $this->wfpCellText($sheet, 'V15'));
        $this->assertSame('5020402000', $this->wfpCellText($sheet, 'W15'));

        // The export is as long as the plan: the TOTAL row sits under the two
        // rows written, its range covers exactly them, and no row carrying
        // data is left hidden (the template hides its second blank line).
        $this->assertSame('Total', $this->wfpCellText($sheet, 'A16'));
        $this->assertSame('SUM(V14:V15)', $this->wfpCellFormula($sheet, 'V16'));
        $this->assertDoesNotMatchRegularExpression('/<row\b[^>]*\bhidden="1"/', $sheet);

        // The blank form's own line is nowhere in it.
        $this->assertNotSame('Travelling Expenses-Local', $this->wfpCellText($sheet, 'A14'));

        // And the plan it was generated from was created and stamped.
        $plan = WfpPlan::query()->where('school_id', $head->school_id)->where('year', 2026)->first();

        $this->assertNotNull($plan);
        $this->assertSame(1, $plan->items()->count());
        $this->assertSame((int) $head->id, (int) $plan->generated_by);
    }

    public function test_the_year_select_offers_every_year_with_data_newest_first(): void
    {
        $head = $this->schoolHead();

        $this->matrixWithLine($head, 2025, 'Payment for Electricity', '5020402000');
        $this->matrixWithLine($head, 2026, 'Payment for Water', '5020403000');

        Livewire::actingAs($head)
            ->test(WfpGenerateButton::class)
            ->assertSet('year', '2026')
            ->assertSeeHtmlInOrder(['CY 2026', 'CY 2025']);
    }

    public function test_a_school_with_no_data_is_offered_the_current_year_for_the_blank_form(): void
    {
        $this->classification('Office Supplies Expenses', '5020301000');

        Livewire::actingAs($this->schoolHead())
            ->test(WfpGenerateButton::class)
            ->assertSet('year', (string) now()->year)
            ->assertSee('CY '.now()->year)
            ->assertSee('Built from your 1 account code');
    }

    public function test_a_year_the_school_has_no_data_in_falls_back_to_the_newest_year_that_does(): void
    {
        $head = $this->schoolHead();
        $this->matrixWithLine($head, 2026, 'Payment for Electricity', '5020402000');

        // The select is a choice between real workbooks, not a way to retitle
        // one year's numbers as another's.
        Livewire::actingAs($head)
            ->test(WfpGenerateButton::class)
            ->set('year', '2019')
            ->assertSet('year', '2026');
    }

    /**
     * The picker is the whole point: a school with two years of matrices
     * generates the WFP for the year it chose, from that year's lines only,
     * and the form carries that year in its title block.
     */
    public function test_the_school_head_can_generate_for_the_year_they_pick(): void
    {
        if (! is_file(storage_path('forms/WFP-Template.xlsx'))) {
            $this->markTestSkipped('The shipped WFP template is not present.');
        }

        $head = $this->schoolHead();

        $this->matrixWithLine($head, 2025, 'Payment for Electricity', '5020402000');
        $this->matrixWithLine($head, 2026, 'Payment for Water', '5020403000');

        $component = Livewire::actingAs($head)
            ->test(WfpGenerateButton::class)
            ->set('year', '2025')
            ->call('download')
            ->assertFileDownloaded(WfpAccountCodeGenerator::downloadName($head, 2025));

        // Only the picked year's plan was generated.
        $plan = WfpPlan::query()->where('school_id', $head->school_id)->where('year', 2025)->firstOrFail();
        $this->assertSame(1, $plan->items()->count());
        $this->assertSame(0, WfpPlan::query()->where('school_id', $head->school_id)->where('year', 2026)->count());

        $bytes = base64_decode((string) data_get($component->effects, 'download.content'));
        $sheet = $this->wfpSheetXml($bytes);

        // The form names the year it was generated for — in the title and in
        // both target headings — rather than keeping the template's year.
        $this->assertSame('CY 2025 Work and Financial Plan', $this->wfpCellText($sheet, 'A9'));
        $this->assertSame('Physical Target (2025)', $this->wfpCellText($sheet, 'E11'));
        $this->assertSame('Financial Target (2025)', $this->wfpCellText($sheet, 'R11'));

        // And it holds the 2025 line, not the 2026 one — sitting under its
        // account-title section row at row 14.
        $this->assertSame('Payment for Electricity', $this->wfpCellText($sheet, 'B15'));
        $this->assertSame('6000.00', $this->wfpCellText($sheet, 'V15'));
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

    public function test_the_school_head_sees_only_their_own_uploads_in_the_list(): void
    {
        Storage::fake('local');

        $head = $this->schoolHead();
        $other = $this->schoolHead('otherhead');

        $this->upload($head, 'my-plan.xlsx', '2026');
        $this->upload($other, 'their-plan.xlsx');

        Livewire::actingAs($head)
            ->test(WfpMyUploadsList::class)
            ->assertSee('My WFP Uploads')
            ->assertSee('my-plan.xlsx')
            ->assertSee('2026')
            ->assertDontSee('their-plan.xlsx')
            ->assertSee('Download');
    }

    public function test_the_uploads_list_shows_an_empty_state_without_uploads(): void
    {
        Storage::fake('local');

        Livewire::actingAs($this->schoolHead())
            ->test(WfpMyUploadsList::class)
            ->assertSee('No WFP uploads yet.')
            ->assertDontSee('Download');
    }

    public function test_a_non_school_head_cannot_reach_the_uploads_list(): void
    {
        $html = Livewire::actingAs($this->viewer())
            ->test(WfpMyUploadsList::class)
            ->html();

        $this->assertStringContainsString('Page Not Found', $html);
    }
}
