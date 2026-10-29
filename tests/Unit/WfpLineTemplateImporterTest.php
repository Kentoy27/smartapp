<?php

namespace Tests\Unit;

use App\Models\AccountClassification;
use App\Models\User;
use App\Models\WfpFundingSource;
use App\Models\WfpImportLog;
use App\Models\WfpLineTemplate;
use App\Models\WfpLookupAudit;
use App\Models\WfpOrgClassification;
use App\Models\WfpResourceKind;
use App\Models\WfpUnit;
use App\Support\WfpLineTemplateImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WfpLineTemplateImporterTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        return User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
    }

    private function seedLookups(): void
    {
        WfpResourceKind::create(['code' => 'MOOE', 'name' => 'MOOE', 'description' => 'Maintenance and Other Operating Expenses', 'is_active' => true]);
        WfpUnit::create(['code' => 'REAM', 'name' => 'Ream', 'abbreviation' => 'rm', 'is_active' => true]);
        WfpFundingSource::create(['code' => 'MOOE', 'name' => 'Maintenance and Other Operating Expenses', 'is_active' => true]);
        WfpOrgClassification::create(['code' => 'SCHOOL', 'name' => 'School', 'description' => 'The school itself', 'is_active' => true]);
        AccountClassification::create(['account_title' => 'Office Supplies', 'rca_code' => 'RCA001', 'uacs_object_code' => '5020301000', 'uacs_sub_object_code' => '', 'covering_ppas' => '', 'sort_order' => 1, 'is_active' => true]);
    }

    /**
     * @param  string  $content
     * @return string
     */
    private function tempCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wfp-template-import-test-').'.csv';
        file_put_contents($path, $content);

        return $path;
    }

    public function test_it_previews_without_writing(): void
    {
        $this->administrator();
        $this->seedLookups();

        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'Procure office supplies,operations,Delete this example row before filling your own.,Improved learner outcomes,Procure office supplies,MOOE,ream,10,250.00,5020301000,MOOE,School,Supplies procured,Number of reams procured,PO and RIS,"supplies,mooe",Yes',
        ]);

        $analysis = WfpLineTemplateImporter::analyzeCsv(
            $this->tempCsv($csv),
            WfpImportLog::MODE_INSERT
        );

        $this->assertSame(1, $analysis['validCount']);
        $this->assertSame(0, $analysis['invalidCount']);
        $this->assertSame(1, $analysis['insertCount']);

        // Nothing written yet.
        $this->assertSame(0, WfpLineTemplate::count());
    }

    public function test_it_commits_valid_rows_and_records_the_import_log(): void
    {
        $admin = $this->administrator();
        $this->seedLookups();

        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'Procure office supplies,operations,,Improved learner outcomes,Procure office supplies,MOOE,ream,10,250.00,5020301000,MOOE,School,Supplies procured and distributed,Number of reams procured,PO and RIS,"supplies,mooe",Yes',
        ]);

        $analysis = WfpLineTemplateImporter::analyzeCsv(
            $this->tempCsv($csv),
            WfpImportLog::MODE_INSERT
        );

        $report = WfpLineTemplateImporter::commit($analysis, WfpImportLog::MODE_INSERT, $admin, 'templates.csv');

        $this->assertSame(1, $report['inserted']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame(0, $report['failed']);

        $this->assertSame(1, WfpLineTemplate::count());
        $template = WfpLineTemplate::first();
        $this->assertSame('Procure office supplies', $template->name);
        $this->assertSame('operations', $template->line_type);
        $this->assertSame('Improved learner outcomes', $template->objective);
        $this->assertSame('Procure office supplies', $template->activity_expenditure);
        $this->assertSame(10.0, (float) $template->suggested_quantity);
        $this->assertSame(250.0, (float) $template->suggested_unit_cost);
        $this->assertNotNull($template->resourceKind);
        $this->assertSame('MOOE', $template->resourceKind->name);
        $this->assertNotNull($template->unit);
        $this->assertSame('Ream', $template->unit->name);
        $this->assertNotNull($template->accountClassification);
        $this->assertSame('5020301000', $template->accountClassification->uacs_object_code);
        $this->assertNotNull($template->fundingSource);
        // Matched by its stable code "MOOE"; the record's own name is kept.
        $this->assertSame('Maintenance and Other Operating Expenses', $template->fundingSource->name);
        $this->assertNotNull($template->orgClassification);
        $this->assertSame('School', $template->orgClassification->name);
        $this->assertSame('Supplies procured and distributed', $template->major_output);
        $this->assertSame('Number of reams procured', $template->performance_indicator);
        $this->assertSame('PO and RIS', $template->activity_movs);
        $this->assertSame('supplies,mooe', $template->tags);
        $this->assertTrue($template->is_active);

        // Import log recorded.
        $this->assertSame(1, WfpImportLog::count());
        $this->assertSame('line_templates', WfpImportLog::first()->category);

        // Audit trail recorded.
        $this->assertSame(1, WfpLookupAudit::where('auditable_type', 'line_template')->where('action', 'CREATE')->count());
        $this->assertSame($admin->id, WfpLookupAudit::first()->user_id);
    }

    public function test_it_skips_invalid_rows_and_reports_them(): void
    {
        $admin = $this->administrator();
        $this->seedLookups();

        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'Valid Template,operations,,Objective,Activity,MOOE,ream,5,100.00,5020301000,MOOE,School,Output,Indicator,MOVs,tags,Yes',
            ',operations,,,Activity,,,,,,,,,,,', // missing name
        ]);

        $analysis = WfpLineTemplateImporter::analyzeCsv(
            $this->tempCsv($csv),
            WfpImportLog::MODE_INSERT
        );

        $this->assertSame(1, $analysis['validCount']);
        $this->assertSame(1, $analysis['invalidCount']);
        $this->assertStringContainsString('Template Name is required', implode(' ', $analysis['rows'][1]['errors']));

        $report = WfpLineTemplateImporter::commit($analysis, WfpImportLog::MODE_INSERT, $admin);

        $this->assertSame(1, $report['inserted']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(1, WfpLineTemplate::count());
    }

    public function test_it_handles_duplicate_by_name_and_line_type(): void
    {
        $admin = $this->administrator();
        $this->seedLookups();
        WfpLineTemplate::create([
            'name' => 'Procure office supplies',
            'line_type' => 'operations',
            'activity_expenditure' => 'Existing activity',
            'is_active' => true,
        ]);

        // Same name and line type → the existing row's identity: skipped, not
        // a second template.
        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'Procure office supplies,operations,,,New activity,,,,,,,,,,,',
        ]);
        $analysis = WfpLineTemplateImporter::analyzeCsv($this->tempCsv($csv), WfpImportLog::MODE_INSERT);
        $this->assertSame(0, $analysis['validCount']);
        $this->assertSame(0, $analysis['invalidCount']);
        $this->assertSame(1, $analysis['skippedCount']);
        $this->assertStringContainsString('already exists', implode(' ', $analysis['rows'][0]['warnings'] ?? []));

        // Same name, different line type → NOT a duplicate.
        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'Procure office supplies,project,,,Project activity,,,,,,,,,,,',
        ]);
        $analysis = WfpLineTemplateImporter::analyzeCsv($this->tempCsv($csv), WfpImportLog::MODE_INSERT);
        $this->assertSame(1, $analysis['validCount']);

        $this->assertSame(1, WfpLineTemplate::count());
    }

    public function test_insert_mode_skips_existing_templates(): void
    {
        $admin = $this->administrator();
        $this->seedLookups();
        WfpLineTemplate::create([
            'name' => 'Procure office supplies',
            'line_type' => 'operations',
            'activity_expenditure' => 'Existing activity',
            'is_active' => true,
        ]);

        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'Procure office supplies,operations,,,Activity,,,,,,,,,,,',
        ]);
        $analysis = WfpLineTemplateImporter::analyzeCsv($this->tempCsv($csv), WfpImportLog::MODE_INSERT);

        $this->assertSame(0, $analysis['validCount']);
        $this->assertSame(1, $analysis['skippedCount']);
        $this->assertSame(1, WfpLineTemplate::count());
    }

    public function test_update_mode_updates_matching_templates(): void
    {
        $admin = $this->administrator();
        $this->seedLookups();
        $template = WfpLineTemplate::create([
            'name' => 'Old Name',
            'line_type' => 'operations',
            'activity_expenditure' => 'Old activity',
            'is_active' => true,
        ]);

        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'Old Name,operations,,New objective,New activity,MOOE,ream,5,100.00,5020301000,MOOE,School,New output,New indicator,New MOVs,"new,tags",Yes',
        ]);
        $analysis = WfpLineTemplateImporter::analyzeCsv($this->tempCsv($csv), WfpImportLog::MODE_UPDATE);
        $report = WfpLineTemplateImporter::commit($analysis, WfpImportLog::MODE_UPDATE, $admin);

        $this->assertSame(1, $report['updated']);
        $this->assertSame(0, $report['inserted']);
        $this->assertSame(1, WfpLineTemplate::count());
        $this->assertDatabaseHas('wfp_line_templates', ['name' => 'Old Name', 'activity_expenditure' => 'New activity']);
    }

    public function test_update_mode_skips_unmatched_templates(): void
    {
        $admin = $this->administrator();
        $this->seedLookups();
        WfpLineTemplate::create([
            'name' => 'Existing',
            'line_type' => 'operations',
            'activity_expenditure' => 'Activity',
            'is_active' => true,
        ]);

        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'New Template,operations,,,Activity,,,,,,,,,,,',
        ]);
        $analysis = WfpLineTemplateImporter::analyzeCsv($this->tempCsv($csv), WfpImportLog::MODE_UPDATE);

        $this->assertSame(0, $analysis['validCount']);
        $this->assertSame(1, $analysis['skippedCount']);
        $this->assertSame(1, WfpLineTemplate::count());
    }

    public function test_upsert_updates_matches_and_inserts_new(): void
    {
        $admin = $this->administrator();
        $this->seedLookups();
        WfpLineTemplate::create([
            'name' => 'Existing Template',
            'line_type' => 'operations',
            'activity_expenditure' => 'Old activity',
            'is_active' => true,
        ]);

        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'Existing Template,operations,,,New activity,,,,,,,,,,,', // match by name + type → update
            'New Template,operations,,,Another activity,,,,,,,,,,,', // no match → insert
        ]);
        $analysis = WfpLineTemplateImporter::analyzeCsv($this->tempCsv($csv), WfpImportLog::MODE_UPSERT);
        $report = WfpLineTemplateImporter::commit($analysis, WfpImportLog::MODE_UPSERT, $admin);

        $this->assertSame(1, $report['updated']);
        $this->assertSame(1, $report['inserted']);
        $this->assertSame(2, WfpLineTemplate::count());
    }

    public function test_it_resolves_unknown_reference_as_row_error(): void
    {
        $admin = $this->administrator();
        $this->seedLookups();

        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'Bad Template,operations,,,Activity,Unknown Kind,Unit,,,,,Unknown Org,,,,,,,Yes',
        ]);
        $analysis = WfpLineTemplateImporter::analyzeCsv($this->tempCsv($csv), WfpImportLog::MODE_INSERT);

        $this->assertSame(0, $analysis['validCount']);
        $this->assertSame(1, $analysis['invalidCount']);
        $this->assertStringContainsString('Resource Kind', implode(' ', $analysis['rows'][0]['errors']));
        $this->assertStringContainsString('Organizational Classification', implode(' ', $analysis['rows'][0]['errors']));
        $this->assertSame(0, WfpLineTemplate::count());
    }

    public function test_it_resolves_account_by_object_code_first_then_by_title(): void
    {
        $admin = $this->administrator();
        $this->seedLookups();

        // Match by object code.
        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'By Code,operations,,,Activity,MOOE,ream,5,100.00,5020301000,MOOE,School,,,,,Yes',
        ]);
        $analysis = WfpLineTemplateImporter::analyzeCsv($this->tempCsv($csv), WfpImportLog::MODE_INSERT);
        $this->assertSame(1, $analysis['validCount']);
        $report = WfpLineTemplateImporter::commit($analysis, WfpImportLog::MODE_INSERT, $admin);
        $this->assertSame(1, $report['inserted']);
        $this->assertSame('5020301000', WfpLineTemplate::first()->accountClassification->uacs_object_code);

        // Match by title.
        AccountClassification::create(['account_title' => 'Travel Expenses', 'rca_code' => 'RCA002', 'uacs_object_code' => '5020101000', 'uacs_sub_object_code' => '', 'covering_ppas' => '', 'sort_order' => 2, 'is_active' => true]);

        $csv = implode("\n", [
            'Template Name,Line Type,Description,Objective,Project/Activity/Expenditure,Resource Kind,Unit,Suggested Quantity,Suggested Unit Cost,Account Code,Funding Source,Org. Classification,Major Output,Performance Indicator,Activity MOVs,Tags,Active',
            'By Title,operations,,,Activity,MOOE,ream,5,100.00,Travel Expenses,MOOE,School,,,,,Yes',
        ]);
        $analysis = WfpLineTemplateImporter::analyzeCsv($this->tempCsv($csv), WfpImportLog::MODE_INSERT);
        $this->assertSame(1, $analysis['validCount']);
        $report = WfpLineTemplateImporter::commit($analysis, WfpImportLog::MODE_INSERT, $admin);
        $this->assertSame(1, $report['inserted']);
        $this->assertSame(2, WfpLineTemplate::count());
        $this->assertSame('Travel Expenses', WfpLineTemplate::find(2)->accountClassification->account_title);
    }

    public function test_unknown_import_mode_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown import mode');

        WfpLineTemplateImporter::analyzeRows([], 'bogus');
    }
}
