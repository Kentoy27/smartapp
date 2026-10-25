<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\WfpFundingSource;
use App\Models\WfpImportLog;
use App\Models\WfpLookupAudit;
use App\Models\WfpOrgClassification;
use App\Models\WfpResourceKind;
use App\Models\WfpUnit;
use App\Support\WfpLookupImporter;
use Tests\TestCase;

class WfpLookupImporterTest extends TestCase
{
    private function administrator(): User
    {
        return User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
    }

    /**
     * @param  string  $content
     * @return string
     */
    private function tempCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wfp-lookup-import-test-').'.csv';
        file_put_contents($path, $content);

        return $path;
    }

    public function test_it_previews_without_writing(): void
    {
        $this->administrator();

        $csv = implode("\n", [
            'Code,Name,Description,Active',
            'SEF,Special Education Fund,Special education levy,Yes',
            'PTA,PTA Fund,Parent-teacher association funds,Yes',
        ]);

        $analysis = WfpLookupImporter::analyzeCsv(
            $this->tempCsv($csv),
            'funding-sources',
            WfpImportLog::MODE_INSERT
        );

        $this->assertSame(2, $analysis['validCount']);
        $this->assertSame(0, $analysis['invalidCount']);
        $this->assertSame(2, $analysis['insertCount']);

        // Nothing written yet.
        $this->assertSame(0, WfpFundingSource::count());
    }

    public function test_it_commits_valid_rows_and_records_the_import_log(): void
    {
        $admin = $this->administrator();

        $csv = implode("\n", [
            'Code,Name,Description,Active',
            'SEF,Special Education Fund,Special education levy,Yes',
            'PTA,PTA Fund,Parent-teacher association funds,Yes',
        ]);

        $analysis = WfpLookupImporter::analyzeCsv(
            $this->tempCsv($csv),
            'funding-sources',
            WfpImportLog::MODE_INSERT
        );

        $report = WfpLookupImporter::commit($analysis, 'funding-sources', WfpImportLog::MODE_INSERT, $admin, 'funding-sources.csv');

        $this->assertSame(2, $report['inserted']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame(0, $report['failed']);

        $this->assertSame(2, WfpFundingSource::count());
        $this->assertDatabaseHas('wfp_funding_sources', ['code' => 'SEF', 'name' => 'Special Education Fund']);
        $this->assertDatabaseHas('wfp_funding_sources', ['code' => 'PTA', 'name' => 'PTA Fund']);

        // Import log recorded.
        $this->assertSame(1, WfpImportLog::count());
        $this->assertSame('funding-sources', WfpImportLog::first()->category);
        $this->assertSame(WfpImportLog::MODE_INSERT, WfpImportLog::first()->mode);
        $this->assertSame($admin->id, WfpImportLog::first()->user_id);
        $this->assertSame('funding-sources.csv', WfpImportLog::first()->filename);

        // Audit trail recorded.
        $this->assertSame(2, WfpLookupAudit::where('auditable_type', 'funding_source')->where('action', 'CREATE')->count());
        $this->assertSame($admin->id, WfpLookupAudit::first()->user_id);
    }

    public function test_it_skips_invalid_rows_and_reports_them(): void
    {
        $admin = $this->administrator();

        $csv = implode("\n", [
            'Code,Name,Description,Active',
            'GF,General Fund,General fund,Yes',
            ',Missing Name,,Yes', // missing required name
        ]);

        $analysis = WfpLookupImporter::analyzeCsv(
            $this->tempCsv($csv),
            'funding-sources',
            WfpImportLog::MODE_INSERT
        );

        $this->assertSame(1, $analysis['validCount']);
        $this->assertSame(1, $analysis['invalidCount']);
        $this->assertStringContainsString('Name is required', implode(' ', $analysis['rows'][1]['errors']));

        $report = WfpLookupImporter::commit($analysis, 'funding-sources', WfpImportLog::MODE_INSERT, $admin);

        $this->assertSame(1, $report['inserted']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(1, WfpFundingSource::count());
        $this->assertDatabaseHas('wfp_funding_sources', ['code' => 'GF', 'name' => 'General Fund']);
    }

    public function test_it_handles_duplicate_by_code_and_by_name(): void
    {
        $admin = $this->administrator();
        WfpFundingSource::create(['code' => 'MOOE', 'name' => 'Maintenance and Other Operating Expenses', 'is_active' => true]);

        // Same name, different code → duplicate by name.
        $csv = implode("\n", ['Code,Name,Description,Active', 'OTHER,Maintenance and Other Operating Expenses,Duplicate name,Yes']);
        $analysis = WfpLookupImporter::analyzeCsv($this->tempCsv($csv), 'funding-sources', WfpImportLog::MODE_INSERT);
        $this->assertSame(0, $analysis['validCount']);
        $this->assertSame(1, $analysis['invalidCount']);
        $this->assertStringContainsString('Duplicate', implode(' ', $analysis['rows'][1]['errors']));

        // Same code, different name → duplicate by code.
        $csv = implode("\n", ['Code,Name,Description,Active', 'MOOE,Something Else,Duplicate code,Yes']);
        $analysis = WfpLookupImporter::analyzeCsv($this->tempCsv($csv), 'funding-sources', WfpImportLog::MODE_INSERT);
        $this->assertSame(0, $analysis['validCount']);
        $this->assertSame(1, $analysis['invalidCount']);
        $this->assertStringContainsString('Duplicate', implode(' ', $analysis['rows'][1]['errors']));

        $this->assertSame(1, WfpFundingSource::count());
    }

    public function test_insert_mode_skips_existing_records(): void
    {
        $admin = $this->administrator();
        WfpFundingSource::create(['code' => 'MOOE', 'name' => 'Maintenance and Other Operating Expenses', 'is_active' => true]);

        $csv = implode("\n", ['Code,Name,Description,Active', 'MOOE,Maintenance and Other Operating Expenses,Regular school fund,Yes']);
        $analysis = WfpLookupImporter::analyzeCsv($this->tempCsv($csv), 'funding-sources', WfpImportLog::MODE_INSERT);

        $this->assertSame(0, $analysis['validCount']);
        $this->assertSame(1, $analysis['skippedCount']);

        $report = WfpLookupImporter::commit($analysis, 'funding-sources', WfpImportLog::MODE_INSERT, $admin);
        $this->assertSame(0, $report['inserted']);
        $this->assertSame(1, $report['skipped']);
        $this->assertSame(1, WfpFundingSource::count());
    }

    public function test_update_mode_updates_matching_records(): void
    {
        $admin = $this->administrator();
        WfpFundingSource::create(['code' => 'MOOE', 'name' => 'Old Name', 'description' => 'Old desc', 'is_active' => true]);

        $csv = implode("\n", ['Code,Name,Description,Active', 'MOOE,New Name,New description,Yes']);
        $analysis = WfpLookupImporter::analyzeCsv($this->tempCsv($csv), 'funding-sources', WfpImportLog::MODE_UPDATE);
        $report = WfpLookupImporter::commit($analysis, 'funding-sources', WfpImportLog::MODE_UPDATE, $admin);

        $this->assertSame(1, $report['updated']);
        $this->assertSame(0, $report['inserted']);
        $this->assertSame(1, WfpFundingSource::count());
        $this->assertDatabaseHas('wfp_funding_sources', ['code' => 'MOOE', 'name' => 'New Name', 'description' => 'New description']);
    }

    public function test_update_mode_skips_unmatched_records(): void
    {
        $admin = $this->administrator();
        WfpFundingSource::create(['code' => 'MOOE', 'name' => 'Existing', 'is_active' => true]);

        $csv = implode("\n", ['Code,Name,Description,Active', 'NEW,Something New,New record,Yes']);
        $analysis = WfpLookupImporter::analyzeCsv($this->tempCsv($csv), 'funding-sources', WfpImportLog::MODE_UPDATE);

        $this->assertSame(0, $analysis['validCount']);
        $this->assertSame(1, $analysis['skippedCount']);
        $this->assertSame(1, WfpFundingSource::count());
    }

    public function test_upsert_updates_matches_and_inserts_new(): void
    {
        $admin = $this->administrator();
        WfpFundingSource::create(['code' => 'MOOE', 'name' => 'Old Name', 'is_active' => true]);

        $csv = implode("\n", [
            'Code,Name,Description,Active',
            'MOOE,Updated Name,Updated desc,Yes', // match by code → update
            'SEF,New Fund,New fund,Yes', // no match → insert
        ]);
        $analysis = WfpLookupImporter::analyzeCsv($this->tempCsv($csv), 'funding-sources', WfpImportLog::MODE_UPSERT);
        $report = WfpLookupImporter::commit($analysis, 'funding-sources', WfpImportLog::MODE_UPSERT, $admin);

        $this->assertSame(1, $report['updated']);
        $this->assertSame(1, $report['inserted']);
        $this->assertSame(2, WfpFundingSource::count());
        $this->assertDatabaseHas('wfp_funding_sources', ['code' => 'MOOE', 'name' => 'Updated Name']);
        $this->assertDatabaseHas('wfp_funding_sources', ['code' => 'SEF', 'name' => 'New Fund']);
    }

    public function test_it_works_for_all_lookup_categories(): void
    {
        $admin = $this->administrator();

        foreach (['units', 'resource-kinds', 'org-classifications'] as $category) {
            $spec = config('wfp_lookups.categories.'.$category);
            $model = $spec['model'];

            $csv = implode("\n", [
                'Code,Name,Description,Active',
                'TEST1,Test One,First test,Yes',
                'TEST2,Test Two,Second test,Yes',
            ]);

            $analysis = WfpLookupImporter::analyzeCsv(
                $this->tempCsv($csv),
                $category,
                WfpImportLog::MODE_INSERT
            );
            $this->assertSame(2, $analysis['validCount'], "Category {$category} should have 2 valid rows");

            $report = WfpLookupImporter::commit($analysis, $category, WfpImportLog::MODE_INSERT, $admin);
            $this->assertSame(2, $report['inserted'], "Category {$category} should insert 2 rows");
            $this->assertSame(2, $model::count(), "Category {$category} should have 2 rows in DB");
        }
    }

    public function test_unknown_category_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown WFP lookup category');

        WfpLookupImporter::category('nothing-such');
    }

    public function test_unknown_mode_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown import mode');

        WfpLookupImporter::normalizeMode('bogus');
    }

    public function test_codes_keep_leading_zeroes(): void
    {
        $admin = $this->administrator();

        $csv = implode("\n", ['Code,Name,Description,Active', '00123,Zero Padded Code,A code with leading zeroes,Yes']);
        $analysis = WfpLookupImporter::analyzeCsv($this->tempCsv($csv), 'funding-sources', WfpImportLog::MODE_INSERT);
        $report = WfpLookupImporter::commit($analysis, 'funding-sources', WfpImportLog::MODE_INSERT, $admin);

        $this->assertSame(1, $report['inserted']);
        $this->assertDatabaseHas('wfp_funding_sources', ['code' => '00123']);
    }

    public function test_inactive_rows_can_be_updated_through_import(): void
    {
        $admin = $this->administrator();
        $record = WfpFundingSource::create(['code' => 'RET', 'name' => 'Retired', 'description' => 'Old', 'is_active' => false]);

        $csv = implode("\n", ['Code,Name,Description,Active', 'RET,Retired,Still retired,No']);
        $analysis = WfpLookupImporter::analyzeCsv($this->tempCsv($csv), 'funding-sources', WfpImportLog::MODE_UPSERT);
        $report = WfpLookupImporter::commit($analysis, 'funding-sources', WfpImportLog::MODE_UPSERT, $admin);

        $this->assertSame(1, $report['updated']);
        $this->assertFalse($record->fresh()->is_active);
        $this->assertSame('Still retired', $record->fresh()->description);
    }

    public function test_it_records_audit_on_update_and_deactivation(): void
    {
        $admin = $this->administrator();
        $record = WfpFundingSource::create(['code' => 'MOOE', 'name' => 'Maintenance and Other Operating Expenses', 'is_active' => true]);

        // Update via import.
        $csv = implode("\n", ['Code,Name,Description,Active', 'MOOE,Updated Name,Updated desc,Yes']);
        $analysis = WfpLookupImporter::analyzeCsv($this->tempCsv($csv), 'funding-sources', WfpImportLog::MODE_UPSERT);
        WfpLookupImporter::commit($analysis, 'funding-sources', WfpImportLog::MODE_UPSERT, $admin);

        $this->assertSame(1, WfpLookupAudit::where('auditable_type', 'funding_source')->where('action', 'UPDATE')->count());

        // Deactivate via import (setting Active to No).
        $csv = implode("\n", ['Code,Name,Description,Active', 'MOOE,Updated Name,Updated desc,No']);
        $analysis = WfpLookupImporter::analyzeCsv($this->tempCsv($csv), 'funding-sources', WfpImportLog::MODE_UPSERT);
        WfpLookupImporter::commit($analysis, 'funding-sources', WfpImportLog::MODE_UPSERT, $admin);

        $this->assertSame(1, WfpLookupAudit::where('auditable_type', 'funding_source')->where('action', 'UPDATE')->count());
        $this->assertFalse($record->fresh()->is_active);
    }
}
