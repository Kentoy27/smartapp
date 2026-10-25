<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\WfpFundingSource;
use App\Models\WfpLineTemplate;
use App\Models\WfpOrgClassification;
use App\Models\WfpResourceKind;
use App\Models\WfpUnit;
use App\Support\WfpSetupExporter;
use Tests\TestCase;

class WfpSetupExporterTest extends TestCase
{
    private function administrator(): User
    {
        return User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
    }

    private function seedLookups(): void
    {
        WfpFundingSource::create(['code' => 'MOOE', 'name' => 'Maintenance and Other Operating Expenses', 'description' => 'Regular school fund', 'is_active' => true]);
        WfpFundingSource::create(['code' => 'SEF', 'name' => 'Special Education Fund', 'description' => 'Special education levy', 'is_active' => false]);
        WfpUnit::create(['code' => 'REAM', 'name' => 'Ream', 'abbreviation' => 'rm', 'is_active' => true]);
        WfpResourceKind::create(['code' => 'MOOE', 'name' => 'MOOE', 'description' => 'Maintenance and Other Operating Expenses', 'is_active' => true]);
        WfpOrgClassification::create(['code' => 'SCHOOL', 'name' => 'School', 'description' => 'The school itself', 'is_active' => true]);
    }

    public function test_lookup_csv_exports_all_rows_active_and_retired(): void
    {
        $this->administrator();
        $this->seedLookups();

        $csv = WfpSetupExporter::lookupCsv('funding-sources');

        $lines = explode("\n", trim($csv));
        $this->assertSame('Code,Name,Description,Active', $lines[0]);
        $this->assertSame('MOOE,Maintenance and Other Operating Expenses,Regular school fund,Yes', $lines[1]);
        $this->assertSame('SEF,Special Education Fund,Special education levy,No', $lines[2]);
        $this->assertSame(3, count($lines));
    }

    public function test_lookup_csv_uses_codes_where_available_and_names_otherwise(): void
    {
        $this->administrator();
        $this->seedLookups();

        $csv = WfpSetupExporter::lookupCsv('units');

        $lines = explode("\n", trim($csv));
        $this->assertSame('Code,Name,Abbreviation,Active', $lines[0]);
        $this->assertSame('REAM,Ream,rm,Yes', $lines[1]);
    }

    public function test_lookup_template_csv_returns_header_and_example(): void
    {
        $this->administrator();

        $csv = WfpSetupExporter::lookupTemplateCsv('funding-sources');

        $lines = explode("\n", trim($csv));
        $this->assertSame('Code,Name,Description,Active', $lines[0]);
        $this->assertSame('MOOE,Maintenance and Other Operating Expenses,Regular school fund,Yes', $lines[1]);
        $this->assertSame(2, count($lines));
    }

    public function test_template_csv_exports_all_activity_templates(): void
    {
        $this->administrator();
        $this->seedLookups();

        WfpLineTemplate::create([
            'name' => 'Procure office supplies',
            'line_type' => 'operations',
            'description' => 'A template',
            'objective' => 'Improved outcomes',
            'activity_expenditure' => 'Procure office supplies',
            'resource_kind_id' => WfpResourceKind::first()->id,
            'unit_id' => WfpUnit::first()->id,
            'suggested_quantity' => 10.00,
            'suggested_unit_cost' => 250.00,
            'account_classification_id' => null,
            'funding_source_id' => WfpFundingSource::first()->id,
            'org_classification_id' => WfpOrgClassification::first()->id,
            'major_output' => 'Supplies procured',
            'performance_indicator' => 'Number of reams',
            'activity_movs' => 'PO and RIS',
            'tags' => 'supplies,mooe',
            'is_active' => true,
        ]);

        $csv = WfpSetupExporter::templateCsv();

        $lines = explode("\n", trim($csv));
        $this->assertSame('Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Suggested Quantity,Unit,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active', $lines[0]);
        $this->assertStringContainsString('Procure office supplies', $lines[1]);
        $this->assertStringContainsString('operations', $lines[1]);
        $this->assertStringContainsString('250', $lines[1]);
        $this->assertSame(2, count($lines));
    }

    public function test_template_csv_exports_empty_when_no_templates(): void
    {
        $this->administrator();

        $csv = WfpSetupExporter::templateCsv();

        $lines = explode("\n", trim($csv));
        $this->assertSame(1, count($lines));
        $this->assertStringContainsString('Template Name', $lines[0]);
    }

    public function test_template_template_csv_returns_header_and_example(): void
    {
        $this->administrator();

        $csv = WfpSetupExporter::templateTemplateCsv();

        $lines = explode("\n", trim($csv));
        $this->assertSame('Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Suggested Quantity,Unit,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active', $lines[0]);
        $this->assertSame('Procure office supplies,operations,Delete this example row before filling your own.,Improved learner outcomes,Procure office supplies,MOOE,10,ream,250.00,5020301000,MOOE,School,Supplies procured and distributed,Number of reams procured,PO and RIS,supplies, mooe,Yes', $lines[1]);
        $this->assertSame(2, count($lines));
    }

    public function test_error_report_csv_formats_failed_and_skipped_rows(): void
    {
        $this->administrator();

        $csv = WfpSetupExporter::errorReportCsv([
            [
                'line' => 3,
                'result' => 'Failed',
                'errors' => ['Name is required', 'Code must be unique'],
                'data' => ['code' => '', 'name' => '', 'description' => '', 'is_active' => 'Yes'],
            ],
            [
                'line' => 5,
                'result' => 'Skipped',
                'errors' => ['Already exists — skipped (insert only)'],
                'data' => ['code' => 'MOOE', 'name' => 'Maintenance and Other Operating Expenses', 'description' => 'Regular school fund', 'is_active' => 'Yes'],
            ],
        ]);

        $lines = explode("\n", trim($csv));
        $this->assertSame('Row,Result,Reason(s),Values', $lines[0]);
        $this->assertStringContainsString('3', $lines[1]);
        $this->assertStringContainsString('Failed', $lines[1]);
        $this->assertStringContainsString('Name is required', $lines[1]);
        $this->assertStringContainsString('Code must be unique', $lines[1]);
        $this->assertStringContainsString('5', $lines[2]);
        $this->assertStringContainsString('Skipped', $lines[2]);
        $this->assertStringContainsString('Already exists', $lines[2]);
        $this->assertSame(3, count($lines));
    }

    public function test_error_report_csv_handles_empty_rows(): void
    {
        $this->administrator();

        $csv = WfpSetupExporter::errorReportCsv([]);

        $lines = explode("\n", trim($csv));
        $this->assertSame('Row,Result,Reason(s),Values', $lines[0]);
        $this->assertSame(1, count($lines));
    }
}
