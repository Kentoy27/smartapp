<?php

namespace Tests\Feature;

use App\Livewire\MemMatrixList;
use App\Models\District;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Models\WfpPlan;
use App\Models\WfpReportCheck;
use App\Support\MemYearXlsxExporter;
use App\Support\MemXlsxExporter;
use App\Support\PurePhpZipReader;
use App\Support\SchoolDivision;
use App\Support\WfpGenerator;
use App\Support\WfpPlanExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Monthly Expenditure Matrix page's year export: one workbook carrying
 * twelve worksheets (JAN…DEC), each the month's printed DepEd form, then the
 * year's Per Line Item Expenditure report and — when a WFP plan exists — the
 * official WFP, in a signed or unsigned e-signature mode.
 */
class MemYearXlsxExportTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2n1cAAAAASUVORK5CYII=';

    private const PNG_RED = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const PNG_BLACK = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private const PNG_BLUE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function schoolHead(string $name = 'Test School'): User
    {
        $district = District::create(['name' => 'District '.$name]);
        $school = School::create(['district_id' => $district->id, 'name' => $name]);

        return User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);
    }

    private function matrixFor(User $sh, int $month, int $year = 2030): MonthlyExpenditureMatrix
    {
        return MonthlyExpenditureMatrix::create([
            'school_id' => $sh->school_id,
            'school_division' => SchoolDivision::ELEMENTARY,
            'year' => $year,
            'month' => $month,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $sh->id,
        ]);
    }

    private function registerSignature(User $user, string $encoded = self::PNG): void
    {
        $path = 'signatures/'.$user->id.'/signature.png';
        Storage::disk('local')->put($path, base64_decode($encoded));

        $user->forceFill([
            'signature_path' => $path,
            'signature_original_name' => 'signature.png',
            'signature_mime_type' => 'image/png',
        ])->save();
    }

    /**
     * The response's bytes written to a temporary path, with a reader over
     * them. The caller unlinks the file.
     *
     * @return array{0: PurePhpZipReader, 1: string}
     */
    private function readerFor(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'mem-year-export-').'.xlsx';
        file_put_contents($path, $contents);

        return [new PurePhpZipReader($path), $path];
    }

    /**
     * @param  array<string, string>  $parts
     * @return array<int, string>
     */
    private function partsMatching(array $parts, string $pattern): array
    {
        return array_values(array_filter(
            array_keys($parts),
            fn (string $path): bool => preg_match($pattern, $path) === 1,
        ));
    }

    public function test_the_selected_year_exports_as_twelve_printed_form_worksheets(): void
    {
        $sh = $this->schoolHead('Alpha Elementary School');

        $this->matrixFor($sh, 1)->items()->create([
            'position' => 1,
            'section' => 'operations',
            'objective' => 'Keep the office running',
            'activity_description' => 'January supplies purchase',
            'resource_kind' => 'MOOE',
            'quantity' => 10,
            'unit' => 'ream',
            'unit_cost' => 250,
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '0012345678',
        ]);

        $this->matrixFor($sh, 7)->items()->create([
            'position' => 1,
            'section' => 'project',
            'objective' => 'Improve learner outcomes',
            'activity_description' => 'July training materials',
            'resource_kind' => 'MOOE',
            'quantity' => 2,
            'unit' => 'set',
            'unit_cost' => 500,
            'account_title' => 'Training Expenses',
            'account_code' => '5020301000',
        ]);

        // February has a matrix but no lines; March has no matrix at all.
        $this->matrixFor($sh, 2);

        // Noise: another year and another school must not leak into the file.
        $this->matrixFor($sh, 3, 2029)->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Line from another year',
            'quantity' => 1,
            'unit_cost' => 1,
        ]);

        $otherSh = $this->schoolHead('Beta Elementary School');
        $this->matrixFor($otherSh, 7)->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Line from another school',
            'quantity' => 1,
            'unit_cost' => 1,
        ]);

        $response = $this->actingAs($sh)->get(route('wfp.matrices.year-export', ['year' => 2030]));

        $response->assertOk()
            ->assertHeader('Content-Type', MemYearXlsxExporter::CONTENT_TYPE)
            ->assertDownload('Monthly_Expenditure_Matrix_CY_2030_Signed.xlsx');

        [$zip, $path] = $this->readerFor($response->getContent());

        // Every part must be WELL-FORMED XML: Excel repairs (and mangles) a
        // file whose package parts are malformed, which a zip-level check
        // alone would never catch.
        foreach ($zip->readAll() as $name => $contents) {
            $this->assertNotFalse(
                simplexml_load_string($contents),
                $name.' is not well-formed XML'
            );
        }

        $workbook = $zip->part('xl/workbook.xml');
        $this->assertNotNull($workbook);

        // The monthly tabs in calendar order, then the line-item report. No
        // WFP tab: this school has no plan for the year.
        preg_match_all('/<sheet name="([^"]+)"/', $workbook, $matches);
        $this->assertSame(
            ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC', 'Per Line Item'],
            $matches[1]
        );

        $january = $zip->part('xl/worksheets/sheet1.xml');
        $february = $zip->part('xl/worksheets/sheet2.xml');
        $july = $zip->part('xl/worksheets/sheet7.xml');
        $december = $zip->part('xl/worksheets/sheet12.xml');

        $this->assertNotNull($january);
        $this->assertNotNull($february);
        $this->assertNotNull($july);
        $this->assertNotNull($december);

        // The printed form's letterhead and titles.
        $this->assertStringContainsString('REPUBLIC OF THE PHILIPPINES', $january);
        $this->assertStringContainsString('DEPARTMENT OF EDUCATION', $january);
        $this->assertStringContainsString('District Alpha Elementary School', $january);
        $this->assertStringContainsString('Alpha Elementary School', $january);
        $this->assertStringContainsString('2030 MONTHLY EXPENDITURE MATRIX', $january);
        $this->assertStringContainsString('MONTH: JANUARY 2030', $january);

        // The form's two-tier column header, not the old flat data table.
        foreach ([
            'Objectives',
            'Project/Activity/Expenditure',
            'Resources needed',
            'Kind',
            'Quantity',
            'Unit',
            'Unit Cost',
            'Total Estimated Cost',
            'Account Title',
            'Account Code',
        ] as $heading) {
            $this->assertStringContainsString($heading, $january);
        }

        $this->assertStringNotContainsString('Funding Source', $january);
        $this->assertStringNotContainsString('Major Output', $january);
        $this->assertStringNotContainsString('Performance Indicator', $january);
        $this->assertStringNotContainsString('Activity MOVs', $january);

        // Each month carries only its own lines, under the form's banners.
        $this->assertStringContainsString('Operations-Related Expenditures', $january);
        $this->assertStringContainsString('Project-Related Expenditures', $january);
        $this->assertStringContainsString('January supplies purchase', $january);
        $this->assertStringContainsString('Office Supplies Expenses', $january);
        $this->assertStringNotContainsString('July training materials', $january);
        $this->assertStringContainsString('July training materials', $july);
        $this->assertStringNotContainsString('January supplies purchase', $july);

        // Neither another year's nor another school's lines.
        $this->assertStringNotContainsString('Line from another year', $january.$july);
        $this->assertStringNotContainsString('Line from another school', $january.$july);

        // Account/UACS codes keep their exact value as text, and the account
        // code column uses Excel's builtin text format.
        $this->assertStringContainsString('0012345678', $january);
        $this->assertStringContainsString('numFmtId="49"', (string) $zip->part('xl/styles.xml'));

        // 10 × 250, as a real number in the Total Estimated Cost column.
        $this->assertStringContainsString('<v>2500</v>', $january);
        $this->assertStringContainsString('Subtotal', $january);
        $this->assertStringContainsString('TOTAL:', $january);

        // The form's four signatory blocks, in its own words.
        $this->assertStringContainsString('Prepared by:', $january);
        $this->assertStringContainsString('Checked as the Correctness of', $january);
        $this->assertStringContainsString('Account Titles and Codes:', $january);
        $this->assertStringContainsString('Certifying Availability of', $january);
        $this->assertStringContainsString('Allotment:', $january);
        $this->assertStringContainsString('Approved:', $january);
        $this->assertStringContainsString('School Principal I', $january);
        $this->assertStringContainsString('Senior Bookkeeper', $january);
        $this->assertStringContainsString('Budget Officer III', $january);
        $this->assertStringContainsString('Schools Division Superintendent', $january);

        // The header row is frozen, but the form carries no data-table filter.
        $this->assertStringContainsString('ySplit="7"', $january);
        $this->assertStringContainsString('state="frozen"', $january);
        $this->assertStringNotContainsString('<autoFilter', $january);

        // Empty months are still present, with the form and a clear message.
        $this->assertStringContainsString('No expenditure entries for this month.', $february);
        $this->assertStringContainsString('No expenditure entries for this month.', $december);
        $this->assertStringContainsString('Account Code', $february);
        $this->assertStringContainsString('TOTAL:', $february);

        // The appended Per Line Item tab is the year's own report: its month
        // roll-up and its account-title roll-up, not a summary table.
        $lineItem = $zip->part('xl/worksheets/sheet13.xml');
        $this->assertNotNull($lineItem);
        $this->assertStringContainsString(
            '2030 ANNUAL MOOE ALLOTMENT ALLOCATED PER LINE ITEM OR OBJECT OF EXPENDITURE',
            $lineItem,
        );
        $this->assertStringContainsString('Office Supplies Expenses', $lineItem);
        $this->assertStringContainsString('Training Expenses', $lineItem);
        $this->assertStringContainsString('JANUARY', $lineItem);
        $this->assertStringContainsString('JULY', $lineItem);
        $this->assertStringContainsString('<v>3500</v>', $lineItem);
        $this->assertStringNotContainsString('Line from another year', $lineItem);
        $this->assertStringNotContainsString('Line from another school', $lineItem);

        // The line-item donor table is appended after the MEM table: its
        // title style (3) lands at 24 + 3 in the single merged styles part.
        $this->assertStringContainsString('<c r="A5" s="27"', $lineItem);
        $this->assertStringNotContainsString('tabSelected="1"', $lineItem);

        // 2030 is not the current year, so JAN is the selected tab and no
        // month is artificially highlighted.
        $this->assertStringContainsString('tabSelected="1"', $january);
        $this->assertStringNotContainsString('tabSelected="1"', $july);
        $this->assertStringContainsString('activeTab="0"', $workbook);

        unlink($path);
    }

    public function test_the_signed_export_stamps_available_workflow_signatures_on_every_month(): void
    {
        $sh = $this->schoolHead('Signature School');
        $bookkeeper = User::factory()->create([
            'role' => 'bookkeeper',
            'is_superadmin' => false,
            'school_id' => $sh->school_id,
            'name' => 'Edilyn Lirazan',
            'position' => 'Senior Bookkeeper',
        ]);
        $budget = User::factory()->create([
            'role' => 'budget',
            'is_superadmin' => false,
            'name' => 'Lyn Rose E. Otea',
            'position' => 'Budget Officer III',
        ]);
        $sds = User::factory()->create([
            'role' => 'superadmin',
            'is_superadmin' => true,
            'name' => 'Grace Villanueva',
        ]);

        $this->registerSignature($sh, self::PNG);
        $this->registerSignature($bookkeeper, self::PNG_RED);
        $this->registerSignature($budget, self::PNG_BLACK);
        $this->registerSignature($sds, self::PNG_BLUE);

        $this->matrixFor($sh, 1);

        WfpReportCheck::create([
            'school_id' => $sh->school_id,
            'school_division' => SchoolDivision::ELEMENTARY,
            'year' => 2030,
            'checked_by' => $bookkeeper->id,
            'checked_at' => now(),
        ]);
        SchoolAllotment::create([
            'school_id' => $sh->school_id,
            'school_division' => SchoolDivision::ELEMENTARY,
            'year' => 2030,
            'amount' => 50000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        $signed = $this->actingAs($sh)->get(route('wfp.matrices.year-export', ['year' => 2030]));
        $signed->assertOk()
            ->assertHeader('Content-Type', MemYearXlsxExporter::CONTENT_TYPE)
            ->assertDownload('Monthly_Expenditure_Matrix_CY_2030_Signed.xlsx');

        [$zip, $path] = $this->readerFor($signed->getContent());
        $parts = $zip->readAll();

        // Each signer's distinct image is stored once and reused across the
        // year; every month sheet still points at a drawing of its own.
        $images = $this->partsMatching($parts, '#^xl/media/image\d+\.png$#');
        $drawings = $this->partsMatching($parts, '#^xl/drawings/drawing\d+\.xml$#');

        $this->assertCount(3, $images);
        // Twelve month sheets plus the appended Per Line Item report.
        $this->assertCount(13, $drawings);

        $drawingRels = $parts['xl/drawings/_rels/drawing1.xml.rels'] ?? '';
        $this->assertSame(3, substr_count($drawingRels, 'relationships/image'));
        $this->assertSame(3, substr_count($drawingRels, '/media/image'));
        $this->assertStringContainsString('Extension="png"', $parts['[Content_Types].xml']);
        $this->assertStringContainsString(
            'ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"',
            $parts['[Content_Types].xml'],
        );

        foreach (range(1, 12) as $month) {
            $sheet = $parts['xl/worksheets/sheet'.$month.'.xml'];
            $this->assertMatchesRegularExpression('/<drawing r:id="rId\d+"\/>/', $sheet);
            $this->assertStringContainsString($sh->name, $sheet);
            $this->assertStringContainsString($bookkeeper->name, $sheet);
            $this->assertStringContainsString($budget->name, $sheet);
        }

        // The Per Line Item tab carries the same workflow signatures. Its
        // form prints the signers' names in upper case, as the standalone
        // report does.
        $lineItem = $parts['xl/worksheets/sheet13.xml'];
        $this->assertMatchesRegularExpression('/<drawing r:id="rId\d+"\/>/', $lineItem);
        $this->assertStringContainsString(mb_strtoupper($sh->name), $lineItem);
        $this->assertStringContainsString(mb_strtoupper($bookkeeper->name), $lineItem);
        $this->assertStringContainsString(mb_strtoupper($budget->name), $lineItem);

        // The descriptor is an XML attribute, so a name like O'Keefe arrives
        // escaped; compare against the decoded text so the assertion does not
        // depend on which decorative characters the factory name carries.
        $drawing = html_entity_decode($parts['xl/drawings/drawing1.xml'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $this->assertSame(3, substr_count($drawing, '<xdr:twoCellAnchor'));
        $this->assertStringContainsString('Digital signature of '.$sh->name, $drawing);
        $this->assertStringContainsString('Digital signature of '.$bookkeeper->name, $drawing);
        $this->assertStringContainsString('Digital signature of '.$budget->name, $drawing);

        // The SDS has no approval record for a MEM, so its signature is not
        // substituted into the Approved block.
        $this->assertStringNotContainsString($sds->name, $drawing);

        unlink($path);

        // Without e-signatures the same form is produced with the names but
        // no images or drawings, ready for wet signing.
        $unsigned = $this->actingAs($sh)->get(route('wfp.matrices.year-export', [
            'year' => 2030,
            'signatures' => 'without',
        ]));

        $unsigned->assertOk()
            ->assertDownload('Monthly_Expenditure_Matrix_CY_2030_Unsigned.xlsx');

        [$zip, $path] = $this->readerFor($unsigned->getContent());
        $parts = $zip->readAll();

        $this->assertSame([], $this->partsMatching($parts, '#^xl/media/#'));
        $this->assertSame([], $this->partsMatching($parts, '#^xl/drawings/#'));
        $this->assertStringNotContainsString('<drawing', $parts['xl/worksheets/sheet1.xml']);

        $this->assertStringContainsString($sh->name, $parts['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString($bookkeeper->name, $parts['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString($budget->name, $parts['xl/worksheets/sheet1.xml']);

        // The appended Per Line Item tab follows the same mode: the printed
        // names stay, no image is stamped.
        $this->assertStringNotContainsString('<drawing', $parts['xl/worksheets/sheet13.xml']);
        $this->assertStringContainsString(mb_strtoupper($sh->name), $parts['xl/worksheets/sheet13.xml']);
        $this->assertStringContainsString(mb_strtoupper($bookkeeper->name), $parts['xl/worksheets/sheet13.xml']);
        $this->assertStringContainsString(mb_strtoupper($budget->name), $parts['xl/worksheets/sheet13.xml']);

        unlink($path);
    }

    public function test_the_export_rejects_a_missing_or_out_of_range_year(): void
    {
        $sh = $this->schoolHead();

        foreach ([
            route('wfp.matrices.year-export'),
            route('wfp.matrices.year-export', ['year' => 'abc']),
            route('wfp.matrices.year-export', ['year' => 1999]),
            route('wfp.matrices.year-export', ['year' => 2101]),
        ] as $url) {
            $this->actingAs($sh)
                ->get($url)
                ->assertStatus(422)
                ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        }

        // An API caller gets a JSON error, not an HTML page.
        $this->actingAs($sh)
            ->getJson(route('wfp.matrices.year-export', ['year' => 1999]))
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Choose a year between 2000 and 2100 to export.',
            ]);
    }

    public function test_the_export_rejects_an_unknown_signature_mode(): void
    {
        $sh = $this->schoolHead();

        $this->actingAs($sh)
            ->get(route('wfp.matrices.year-export', ['year' => 2030, 'signatures' => 'maybe']))
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $this->actingAs($sh)
            ->getJson(route('wfp.matrices.year-export', ['year' => 2030, 'signatures' => 'maybe']))
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Choose “with” or “without” for the e-signature export option.',
            ]);
    }

    public function test_guests_and_non_school_roles_cannot_export(): void
    {
        $this->get(route('wfp.matrices.year-export', ['year' => 2030]))
            ->assertRedirect(route('login'));

        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $administrator = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $superadmin = User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);
        $bookkeeper = User::factory()->create(['role' => 'bookkeeper', 'is_superadmin' => false]);

        foreach ([$viewer, $administrator, $superadmin, $bookkeeper] as $user) {
            // Each user is a different browser session; reusing one would
            // trip the password fingerprint that revokes a switched account.
            $this->flushSession();

            $this->actingAs($user)
                ->get(route('wfp.matrices.year-export', ['year' => 2030]))
                ->assertNotFound();
        }

        // A School Head with no school has no matrix (and no workbook) to own.
        $homeless = User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => null]);

        $this->flushSession();

        $this->actingAs($homeless)
            ->get(route('wfp.matrices.year-export', ['year' => 2030]))
            ->assertNotFound();
    }

    public function test_the_page_action_downloads_the_year_selected_on_the_page(): void
    {
        $sh = $this->schoolHead();

        $this->matrixFor($sh, 7, 2030)->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'July training materials',
            'quantity' => 1,
            'unit_cost' => 100,
        ]);

        Livewire::actingAs($sh)
            ->test(MemMatrixList::class)
            ->set('year', '2030')
            ->call('exportYear')
            ->assertFileDownloaded('Monthly_Expenditure_Matrix_CY_2030_Signed.xlsx');

        Livewire::actingAs($sh)
            ->test(MemMatrixList::class)
            ->set('year', '2030')
            ->call('exportYear', 'without')
            ->assertFileDownloaded('Monthly_Expenditure_Matrix_CY_2030_Unsigned.xlsx');
    }

    public function test_the_page_action_will_not_export_the_all_years_view(): void
    {
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)
            ->test(MemMatrixList::class)
            ->set('year', '')
            ->call('exportYear')
            ->assertSet('errorMessage', 'Choose a specific year to export. “All” has no single year to put on the workbook.')
            ->assertNoFileDownloaded();
    }

    public function test_the_export_choices_are_visible_on_the_matrix_page(): void
    {
        $sh = $this->schoolHead();

        $this->matrixFor($sh, 1, 2030);

        Livewire::actingAs($sh)
            ->test(MemMatrixList::class)
            ->set('year', '2030')
            ->assertSee('Export with E-Signatures')
            ->assertSee('Export without E-Signatures');
    }

    public function test_the_export_prints_a_repeated_objective_and_activity_only_once(): void
    {
        $sh = $this->schoolHead('Grouped School');

        $this->matrixFor($sh, 1, 2030)->items()->createMany([
            [
                'position' => 1,
                'section' => 'operations',
                'objective' => 'Keep the office running',
                'activity_description' => 'January supplies purchase',
                'resource_kind' => 'MOOE',
                'quantity' => 2,
                'unit' => 'ream',
                'unit_cost' => 240,
                'account_title' => 'Office Supplies Expenses',
                'account_code' => '5020301000',
            ],
            [
                'position' => 2,
                'section' => 'operations',
                'objective' => 'Keep the office running',
                'activity_description' => 'January supplies purchase',
                'resource_kind' => 'Bond Paper',
                'quantity' => 4,
                'unit' => 'ream',
                'unit_cost' => 550,
                'account_title' => 'Office Supplies Expenses',
                'account_code' => '5020301000',
            ],
            [
                'position' => 3,
                'section' => 'operations',
                'objective' => 'Keep the office running',
                'activity_description' => 'January supplies purchase',
                'resource_kind' => 'Ink Cartridge',
                'quantity' => 10,
                'unit' => 'piece',
                'unit_cost' => 250,
                'account_title' => 'Office Supplies Expenses',
                'account_code' => '5020301000',
            ],
            [
                'position' => 4,
                'section' => 'operations',
                'objective' => 'Keep the office running',
                'activity_description' => 'July training materials',
                'resource_kind' => 'MOOE',
                'quantity' => 1,
                'unit' => 'set',
                'unit_cost' => 500,
                'account_title' => 'Training Expenses',
                'account_code' => '5020301000',
            ],
        ]);

        $response = $this->actingAs($sh)->get(route('wfp.matrices.year-export', ['year' => 2030]));

        $response->assertOk();

        [$zip, $path] = $this->readerFor($response->getContent());
        $january = $zip->part('xl/worksheets/sheet1.xml');

        // The objective and activity print once for the group of resources
        // beneath them; the rows below repeat neither column.
        $this->assertSame(1, substr_count($january, 'Keep the office running'));
        $this->assertSame(1, substr_count($january, 'January supplies purchase'));
        // A new activity shows even when the objective stays the same, while
        // the repeated objective stays blank.
        $this->assertSame(1, substr_count($january, 'July training materials'));

        // The grouped rows read as one continuous block: interior rows carry
        // a style with no top or bottom rule (s=22), and only the block's
        // outer edges are ruled (s=21 above, s=23 below). A new activity
        // under the same objective closes the activity block (s=23) while the
        // objective block carries on.
        $this->assertStringContainsString('<c r="A10" s="21"', $january);
        $this->assertStringContainsString('<c r="A11" s="22"/>', $january);
        $this->assertStringContainsString('<c r="A12" s="22"/>', $january);
        $this->assertStringContainsString('<c r="A13" s="23"/>', $january);
        $this->assertStringContainsString('<c r="B10" s="21"', $january);
        $this->assertStringContainsString('<c r="B11" s="22"/>', $january);
        $this->assertStringContainsString('<c r="B12" s="23"/>', $january);
        $this->assertStringContainsString('<c r="B13" s="7"', $january);

        // All four resource lines are still there, each with its own cost.
        $this->assertSame(3, substr_count($january, 'Office Supplies Expenses'));
        $this->assertSame(1, substr_count($january, 'Training Expenses'));
        $this->assertStringContainsString('<v>480</v>', $january);
        $this->assertStringContainsString('<v>2200</v>', $january);
        $this->assertStringContainsString('<v>2500</v>', $january);
        $this->assertStringContainsString('<v>500</v>', $january);

        unlink($path);
    }

    public function test_an_activity_block_does_not_continue_across_a_new_objective(): void
    {
        $sh = $this->schoolHead('Split School');

        $this->matrixFor($sh, 1, 2030)->items()->createMany([
            [
                'position' => 1,
                'section' => 'operations',
                'objective' => 'First objective',
                'activity_description' => 'Shared activity',
                'resource_kind' => 'MOOE',
                'quantity' => 1,
                'unit' => 'ream',
                'unit_cost' => 10,
                'account_title' => 'Office Supplies Expenses',
                'account_code' => '5020301000',
            ],
            [
                'position' => 2,
                'section' => 'operations',
                'objective' => 'First objective',
                'activity_description' => 'Shared activity',
                'resource_kind' => 'MOOE',
                'quantity' => 1,
                'unit' => 'ream',
                'unit_cost' => 20,
                'account_title' => 'Office Supplies Expenses',
                'account_code' => '5020301000',
            ],
            [
                'position' => 3,
                'section' => 'operations',
                'objective' => 'Second objective',
                'activity_description' => 'Shared activity',
                'resource_kind' => 'MOOE',
                'quantity' => 1,
                'unit' => 'ream',
                'unit_cost' => 30,
                'account_title' => 'Office Supplies Expenses',
                'account_code' => '5020301000',
            ],
        ]);

        $response = $this->actingAs($sh)->get(route('wfp.matrices.year-export', ['year' => 2030]));

        $response->assertOk();

        [$zip, $path] = $this->readerFor($response->getContent());
        $january = $zip->part('xl/worksheets/sheet1.xml');

        // Rows 10-11 are the first objective, row 12 starts the second. The
        // repeated activity text prints again because it belongs to a new
        // objective, and both blocks close and reopen.
        $this->assertSame(2, substr_count($january, 'Shared activity'));
        $this->assertStringContainsString('<c r="A10" s="21"', $january);
        $this->assertStringContainsString('<c r="A11" s="23"/>', $january);
        $this->assertStringContainsString('<c r="A12" s="7"', $january);
        $this->assertStringContainsString('<c r="B10" s="21"', $january);
        $this->assertStringContainsString('<c r="B11" s="23"/>', $january);
        $this->assertStringContainsString('<c r="B12" s="7"', $january);

        unlink($path);
    }

    public function test_the_wfp_tab_is_the_official_plan_workbook_when_a_plan_exists(): void
    {
        $sh = $this->schoolHead('WFP Combined School');

        $this->matrixFor($sh, 1, 2030)->items()->create([
            'position' => 1,
            'section' => 'operations',
            'objective' => 'Keep the office running',
            'activity_description' => 'January supplies purchase',
            'resource_kind' => 'MOOE',
            'quantity' => 10,
            'unit' => 'ream',
            'unit_cost' => 250,
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '0012345678',
            'funding_source' => 'MOOE',
            'organizational_classification' => 'School',
            'activity_movs' => 'PO, RIS',
        ]);

        $plan = WfpPlan::create([
            'school_id' => $sh->school_id,
            'school_division' => SchoolDivision::ELEMENTARY,
            'year' => 2030,
            'status' => WfpPlan::STATUS_DRAFT,
        ]);

        WfpGenerator::generate($plan);

        $response = $this->actingAs($sh)->get(route('wfp.matrices.year-export', ['year' => 2030]));

        $response->assertOk()
            ->assertHeader('Content-Type', MemYearXlsxExporter::CONTENT_TYPE)
            ->assertDownload('Monthly_Expenditure_Matrix_CY_2030_Signed.xlsx');

        [$zip, $path] = $this->readerFor($response->getContent());
        $parts = $zip->readAll();

        foreach ($parts as $name => $contents) {
            // The package also carries binary printer settings; only the XML
            // parts are expected to parse.
            if (preg_match('/\.(xml|rels)$/', $name) !== 1) {
                continue;
            }

            $this->assertNotFalse(
                @simplexml_load_string($contents),
                $name.' is not well-formed XML'
            );
        }

        $workbook = $parts['xl/workbook.xml'];

        // The months, then the line-item report, then the plan's official WFP.
        preg_match_all('/<sheet name="([^"]+)"/', $workbook, $matches);
        $this->assertSame(
            ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC', 'Per Line Item', 'WFP'],
            $matches[1],
        );

        // The WFP workbook was the base package: its sheet and sheetId are
        // untouched, and only its tab position and Print_Area scope moved.
        $this->assertStringContainsString('<sheet name="WFP" sheetId="83"', $workbook);
        $this->assertStringContainsString('localSheetId="13"', $workbook);
        $this->assertStringContainsString('_xlnm.Print_Area', $workbook);
        $this->assertStringContainsString('WFP!$A$1:$AV$', $workbook);

        // Appending relationships must never collide with the template's own.
        $relationships = $parts['xl/_rels/workbook.xml.rels'];
        preg_match_all('/<Relationship\b[^>]*\bId="([^"]+)"/', $relationships, $relationshipIds);
        $this->assertSame(
            $relationshipIds[1],
            array_values(array_unique($relationshipIds[1])),
            'The workbook has duplicate relationship IDs.',
        );

        preg_match_all('/<sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $sheetRelationshipIds);
        $this->assertSame(
            [],
            array_values(array_diff($sheetRelationshipIds[1], $relationshipIds[1])),
            'A sheet points at a missing relationship.',
        );

        // January is the selected tab, and it is the appended month sheet —
        // the WFP template's own sheet stays sheet1.
        $january = $parts['xl/worksheets/sheet2.xml'];
        $this->assertStringContainsString('2030 MONTHLY EXPENDITURE MATRIX', $january);
        $this->assertStringContainsString('MONTH: JANUARY 2030', $january);
        $this->assertStringContainsString('tabSelected="1"', $january);
        $this->assertStringContainsString('activeTab="0"', $workbook);
        $this->assertStringNotContainsString('tabSelected="1"', $parts['xl/worksheets/sheet1.xml']);

        $lineItem = $parts['xl/worksheets/sheet14.xml'];
        $this->assertStringContainsString('ANNUAL MOOE ALLOTMENT ALLOCATED PER LINE ITEM', $lineItem);
        $this->assertStringContainsString('Office Supplies Expenses', $lineItem);

        // The WFP tab is the plan's real workbook, not a summary: its title
        // block carries the school and year and its generated rows are there.
        $wfp = $parts['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('WFP Combined School', $wfp);
        $this->assertStringContainsString('2030', $wfp);
        $this->assertStringContainsString('Office Supplies Expenses', $wfp);

        // One styles table: the filled WFP workbook's own cellXfs, then the
        // MEM and the line-item tables, and every appended style index
        // resolves inside it. The WFP filler appends styles of its own, so the
        // base is the filled workbook the exporter actually started from.
        [$wfpZip, $wfpPath] = $this->readerFor(WfpPlanExporter::bytes($plan, true, $sh));
        $baseStyles = (string) $wfpZip->part('xl/styles.xml');
        unlink($wfpPath);

        preg_match('/<cellXfs\b[^>]*\bcount="(\d+)"/', $baseStyles, $base);
        $baseXfs = (int) $base[1];

        preg_match('/<cellXfs\b[^>]*\bcount="(\d+)"[^>]*>(.*?)<\/cellXfs>/s', $parts['xl/styles.xml'], $merged);
        $mergedCount = (int) $merged[1];

        $this->assertSame($baseXfs + 24 + 16, $mergedCount);
        $this->assertSame($mergedCount, substr_count($merged[2], '<xf '));

        // The donor xfs are re-based onto the template's font and border
        // tables too, not only its cellXfs.
        preg_match('/<fonts\b[^>]*\bcount="(\d+)"/', $baseStyles, $baseFonts);
        preg_match('/<borders\b[^>]*\bcount="(\d+)"/', $baseStyles, $baseBorders);
        preg_match('/<fonts\b[^>]*\bcount="(\d+)"/', MemXlsxExporter::stylesXml(), $memFonts);

        preg_match_all('/<xf\b[^>]*\/>|<xf\b[^>]*>.*?<\/xf>/s', $merged[2], $mergedXfs);
        $this->assertCount($mergedCount, $mergedXfs[0]);

        $this->assertStringContainsString(
            'borderId="'.((int) $baseBorders[1] + 1).'"',
            $mergedXfs[0][$baseXfs + 7],
            'The MEM table was not re-based onto the template border table.',
        );
        $this->assertStringContainsString(
            'fontId="'.((int) $baseFonts[1] + (int) $memFonts[1] + 2).'"',
            $mergedXfs[0][$baseXfs + 24 + 3],
            'The line-item table was not re-based onto the template font table.',
        );

        // The template already owns custom numFmt 164/165, so the line-item
        // 0.00% format must be re-allocated and its xfs pointed at the new id.
        preg_match_all('/<numFmt numFmtId="(\d+)" formatCode="0\.00%"/', $parts['xl/styles.xml'], $percentFormats);
        $this->assertNotEmpty($percentFormats[1], 'The line-item percentage format is missing.');

        $percentId = max(array_map('intval', $percentFormats[1]));
        $percentXfs = array_filter(
            $mergedXfs[0],
            static fn (string $xf): bool => str_contains($xf, 'numFmtId="'.$percentId.'"'),
        );
        $this->assertNotEmpty($percentXfs, 'No cell style uses the line-item percentage format.');

        $appendedParts = array_map(
            static fn (int $sheet): string => 'xl/worksheets/sheet'.$sheet.'.xml',
            range(2, 14),
        );

        foreach ($appendedParts as $part) {
            preg_match_all('/\bs="(\d+)"/', $parts[$part], $indexes);
            $this->assertNotSame([], $indexes[1], $part.' has no styled cells.');

            $highest = max(array_map('intval', $indexes[1]));
            $this->assertLessThan($mergedCount, $highest, $part.' uses a style index outside the merged table.');
        }

        // The donor tables are appended after the template's own: MEM style 7
        // (a one-row objective/activity block) and the line-item title
        // (style 3) land at those offsets.
        $this->assertStringContainsString('<c r="A10" s="'.($baseXfs + 7).'"', $january);
        $this->assertStringContainsString('<c r="A5" s="'.($baseXfs + 24 + 3).'"', $lineItem);

        unlink($path);
    }

    public function test_the_current_years_workbook_opens_the_current_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 15, 12, 0, 0));

        try {
            $sh = $this->schoolHead('Current Year School');

            $this->matrixFor($sh, 11, 2026)->items()->create([
                'position' => 1,
                'section' => 'operations',
                'objective' => 'Keep the office running',
                'activity_description' => 'November supplies purchase',
                'resource_kind' => 'MOOE',
                'quantity' => 10,
                'unit' => 'ream',
                'unit_cost' => 250,
                'account_title' => 'Office Supplies Expenses',
                'account_code' => '0012345678',
                'funding_source' => 'MOOE',
                'organizational_classification' => 'School',
                'activity_movs' => 'PO, RIS',
            ]);

            $plan = WfpPlan::create([
                'school_id' => $sh->school_id,
                'school_division' => SchoolDivision::ELEMENTARY,
                'year' => 2026,
                'status' => WfpPlan::STATUS_DRAFT,
            ]);

            WfpGenerator::generate($plan);

            $response = $this->actingAs($sh)->get(route('wfp.matrices.year-export', ['year' => 2026]));
            $response->assertOk();

            [$zip, $path] = $this->readerFor($response->getContent());

            // The WFP workbook is the base, so the months are sheet2…sheet13:
            // November is sheet12 and its tab opens selected.
            $workbook = (string) $zip->part('xl/workbook.xml');
            $this->assertStringContainsString('activeTab="10"', $workbook);
            $this->assertStringContainsString('tabSelected="1"', (string) $zip->part('xl/worksheets/sheet12.xml'));
            $this->assertStringNotContainsString('tabSelected="1"', (string) $zip->part('xl/worksheets/sheet2.xml'));

            unlink($path);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_the_export_never_creates_a_wfp_plan(): void
    {
        $sh = $this->schoolHead('No Plan School');

        $this->matrixFor($sh, 1, 2030)->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'January supplies purchase',
            'quantity' => 1,
            'unit_cost' => 100,
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
        ]);

        $this->assertSame(0, WfpPlan::query()->count());

        $response = $this->actingAs($sh)->get(route('wfp.matrices.year-export', ['year' => 2030]));
        $response->assertOk();

        // Reading the workbook must not write a plan: the WFP tab is simply
        // absent until the school generates one.
        $this->assertSame(0, WfpPlan::query()->count());

        [$zip, $path] = $this->readerFor($response->getContent());
        $workbook = (string) $zip->part('xl/workbook.xml');

        $this->assertStringNotContainsString('name="WFP"', $workbook);
        $this->assertStringContainsString('name="Per Line Item"', $workbook);

        unlink($path);
    }
}
