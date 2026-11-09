<?php

namespace Tests\Feature;

use App\Livewire\MemMatrixList;
use App\Models\District;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Models\WfpReportCheck;
use App\Support\MemYearXlsxExporter;
use App\Support\PurePhpZipReader;
use App\Support\SchoolDivision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Monthly Expenditure Matrix page's year export: one workbook carrying
 * twelve worksheets (JAN…DEC), each the month's printed DepEd form, in a
 * signed or unsigned e-signature mode.
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

        // Exactly the monthly tabs, in calendar order.
        preg_match_all('/<sheet name="([^"]+)"/', $workbook, $matches);
        $this->assertSame(
            ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'],
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
        $this->assertCount(12, $drawings);

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
            $this->actingAs($user)
                ->get(route('wfp.matrices.year-export', ['year' => 2030]))
                ->assertNotFound();
        }

        // A School Head with no school has no matrix (and no workbook) to own.
        $homeless = User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => null]);

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
}
