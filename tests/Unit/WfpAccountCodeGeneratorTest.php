<?php

namespace Tests\Unit;

use App\Models\AccountClassification;
use App\Models\District;
use App\Models\School;
use App\Models\User;
use App\Support\WfpAccountCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsWfpTemplates;
use Tests\TestCase;

/**
 * The account-code-driven generator behind "Generate and Download WFP": the
 * rows come from the live Account Classifications, in UACS order, and only the
 * Account Title and Object Code are written — everything else stays blank.
 */
class WfpAccountCodeGeneratorTest extends TestCase
{
    use BuildsWfpTemplates;
    use RefreshDatabase;

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

    public function test_rows_are_ordered_by_uacs_object_code(): void
    {
        // Inserted out of order, to prove the ordering is the query's.
        $this->classification('Office Supplies Expenses', '5020301000');
        $this->classification('Travelling Expenses - Local', '5020101000');

        $this->assertSame([
            ['account_title' => 'Travelling Expenses - Local', 'account_code' => '5020101000'],
            ['account_title' => 'Office Supplies Expenses', 'account_code' => '5020301000'],
        ], WfpAccountCodeGenerator::rows());
    }

    public function test_rows_skip_inactive_classifications_and_trim_values(): void
    {
        $this->classification('Active Title', ' 5020101000 ');
        $this->classification('Retired Title', '5020301000', false);

        $this->assertSame([
            ['account_title' => 'Active Title', 'account_code' => '5020101000'],
        ], WfpAccountCodeGenerator::rows());
    }

    public function test_bytes_write_only_the_account_title_and_object_code(): void
    {
        $this->classification('Travelling Expenses - Local', '5020101000');
        $this->classification('Office Supplies Expenses', '5020301000');

        $name = 'test-wfp-'.uniqid().'.xlsx';
        file_put_contents(storage_path('forms/'.$name), $this->wfpTemplateBytes(3));
        config(['wfp.template_file' => $name]);

        try {
            $bytes = WfpAccountCodeGenerator::bytes();
        } finally {
            @unlink(storage_path('forms/'.$name));
        }

        $sheet = $this->wfpSheetXml($bytes);

        // Header row 3 → data rows 4 and 5. Account Title = column I, Object Code = column J.
        $this->assertSame('Travelling Expenses - Local', $this->wfpCellText($sheet, 'I4'));
        $this->assertSame('5020101000', $this->wfpCellText($sheet, 'J4'));
        $this->assertSame('Office Supplies Expenses', $this->wfpCellText($sheet, 'I5'));
        $this->assertSame('5020301000', $this->wfpCellText($sheet, 'J5'));

        // Every other planning column stays blank.
        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'K', 'L', 'M'] as $col) {
            $this->assertSame('', (string) $this->wfpCellText($sheet, $col.'4'), $col.'4 should be blank');
            $this->assertSame('', (string) $this->wfpCellText($sheet, $col.'5'), $col.'5 should be blank');
        }
    }

    public function test_bytes_stamp_the_accounts_school_name_into_the_title_block(): void
    {
        $this->classification('Office Supplies Expenses', '5020301000');

        $district = District::create(['name' => 'D']);
        $school = School::create(['district_id' => $district->id, 'name' => 'Balisong Elementary School']);
        $user = User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => $school->id]);

        $name = 'test-wfp-'.uniqid().'.xlsx';
        file_put_contents(storage_path('forms/'.$name), $this->wfpTemplateBytes(3));
        config(['wfp.template_file' => $name, 'wfp.template.school_name_cell' => 'B5']);

        try {
            $bytes = WfpAccountCodeGenerator::bytes($user);
        } finally {
            @unlink(storage_path('forms/'.$name));
        }

        $this->assertSame('Balisong Elementary School', $this->wfpCellText($this->wfpSheetXml($bytes), 'B5'));
    }

    public function test_the_shipped_template_names_each_account_in_its_first_column(): void
    {
        if (! is_file(storage_path('forms/WFP-Template.xlsx'))) {
            $this->markTestSkipped('The shipped WFP template is not present.');
        }

        $this->classification('Travelling Expenses-Local', '5020101000');

        $bytes = WfpAccountCodeGenerator::bytes();
        $sheet = $this->wfpSheetXml($bytes);

        // The FY-2026 form has no Account Title column, so the account is
        // named in the first column — the form's own convention — and its code
        // lands in the January Object Code column.
        $this->assertSame('Travelling Expenses-Local', $this->wfpCellText($sheet, 'A14'));
        $this->assertSame('5020101000', $this->wfpCellText($sheet, 'S14'));
        $this->assertSame('5020101000', $this->wfpCellText($sheet, 'AO14'));
    }

    public function test_bytes_leave_the_title_block_blank_without_a_school(): void
    {
        $this->classification('Office Supplies Expenses', '5020301000');

        $name = 'test-wfp-'.uniqid().'.xlsx';
        file_put_contents(storage_path('forms/'.$name), $this->wfpTemplateBytes(3));
        config(['wfp.template_file' => $name, 'wfp.template.school_name_cell' => 'B5']);

        try {
            $withoutUser = WfpAccountCodeGenerator::bytes();
            $withoutSchool = WfpAccountCodeGenerator::bytes(
                User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => null])
            );
        } finally {
            @unlink(storage_path('forms/'.$name));
        }

        $this->assertSame('', (string) $this->wfpCellText($this->wfpSheetXml($withoutUser), 'B5'));
        $this->assertSame('', (string) $this->wfpCellText($this->wfpSheetXml($withoutSchool), 'B5'));
    }

    public function test_no_active_classifications_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No account codes');

        WfpAccountCodeGenerator::rows();
    }

    public function test_a_blank_object_code_throws(): void
    {
        $this->classification('Missing Code', '   ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing its Account Title or Object Code');

        WfpAccountCodeGenerator::rows();
    }

    public function test_a_blank_account_title_throws(): void
    {
        $this->classification('   ', '5020101000');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing its Account Title or Object Code');

        WfpAccountCodeGenerator::rows();
    }

    public function test_a_duplicate_object_code_throws(): void
    {
        $this->classification('First Title', '5020101000');
        $this->classification('Second Title', '5020101000');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('share the object code 5020101000');

        WfpAccountCodeGenerator::rows();
    }

    public function test_active_count_counts_only_active_rows(): void
    {
        $this->classification('One', '5020101000');
        $this->classification('Two', '5020301000', false);

        $this->assertSame(1, WfpAccountCodeGenerator::activeCount());
    }

    public function test_download_name_uses_year_and_school_and_drops_an_empty_school(): void
    {
        $district = District::create(['name' => 'D']);
        $school = School::create(['district_id' => $district->id, 'name' => 'Smoke School']);
        $user = User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => $school->id]);

        $this->assertSame('WFP_2026_Smoke_School.xlsx', WfpAccountCodeGenerator::downloadName($user, 2026));
        $this->assertSame('WFP_2026.xlsx', WfpAccountCodeGenerator::downloadName(null, 2026));
    }
}
