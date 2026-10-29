<?php

namespace Tests\Unit;

use App\Models\District;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\SchoolAllotmentAudit;
use App\Models\User;
use App\Support\SchoolAllotmentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolAllotmentImporterTest extends TestCase
{
    use RefreshDatabase;

    private function school(string $name, string $schoolId): School
    {
        $district = District::create(['name' => $name.' District']);

        return School::create([
            'district_id' => $district->id,
            'name' => $name,
            'school_id' => $schoolId,
        ]);
    }

    private function budgetOfficer(): User
    {
        return User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function csv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'allotment-bulk-test-').'.csv';
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    /* ------------------------------------------------------------- parsing */

    public function test_a_pasted_list_is_read_with_an_optional_header_and_any_separator(): void
    {
        $this->school('school1', '12345');
        $this->school('Bugtong Elementary School', '117833');
        $this->school('Far School', '1271287182');

        $analysis = SchoolAllotmentImporter::analyzeText(
            "School ID, Amount\n12345, 2000000.00\n117833\t1500000\n1271287182; 850000.50",
            2026,
            false,
        );

        $this->assertSame(3, $analysis['readyCount']);
        $this->assertSame(0, $analysis['invalidCount']);

        $rows = $analysis['rows'];

        $this->assertSame('12345', $rows[0]['school_id']);
        $this->assertSame('school1', $rows[0]['school_name']);
        $this->assertSame(2000000.0, $rows[0]['amount']);
        $this->assertSame('create', $rows[0]['action']);
        $this->assertSame(1500000.0, $rows[1]['amount']);
        $this->assertSame(850000.5, $rows[2]['amount']);
    }

    public function test_a_comma_separated_amount_keeps_its_thousands_separators(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText("12345, 2,000,000.00", 2026, false);

        $this->assertSame(1, $analysis['readyCount']);
        $this->assertSame(2000000.0, $analysis['rows'][0]['amount']);
    }

    public function test_a_school_id_matches_case_insensitively(): void
    {
        $school = $this->school('Mixed Case School', 'AbC-12');

        $analysis = SchoolAllotmentImporter::analyzeText('abc-12, 100', 2026, false);

        $this->assertSame(1, $analysis['readyCount']);
        $this->assertSame($school->id, $analysis['rows'][0]['school_db_id']);
    }

    public function test_a_csv_is_read_by_its_header_names_and_ignores_extra_columns(): void
    {
        $this->school('school1', '12345');

        $path = $this->csv([
            'school_id,allotted_budget,notes',
            '12345,2000000.00,first tranche',
        ]);

        $analysis = SchoolAllotmentImporter::analyzeCsv($path, 2026, false);

        $this->assertSame(1, $analysis['readyCount']);
        $this->assertSame('12345', $analysis['rows'][0]['school_id']);
        $this->assertSame(2000000.0, $analysis['rows'][0]['amount']);
    }

    public function test_an_unknown_school_id_is_reported_not_guessed(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText("99999, 100000", 2026, false);

        $this->assertSame(0, $analysis['readyCount']);
        $this->assertSame(1, $analysis['invalidCount']);
        $this->assertContains('School not found.', $analysis['rows'][0]['errors']);
        $this->assertTrue($analysis['rows'][0]['valid'] === false);
    }

    public function test_an_amount_that_is_not_a_number_or_is_negative_is_refused(): void
    {
        $this->school('school1', '12345');
        $this->school('Bugtong Elementary School', '117833');

        $analysis = SchoolAllotmentImporter::analyzeText("12345, abc\n117833, -5", 2026, false);

        $this->assertSame(0, $analysis['readyCount']);
        $this->assertSame(2, $analysis['invalidCount']);
        $this->assertContains('Enter the amount as a number.', $analysis['rows'][0]['errors']);
        $this->assertContains('The allotment cannot be negative.', $analysis['rows'][1]['errors']);
    }

    public function test_amounts_may_carry_currency_symbols_and_spaces(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText("12345, ₱2 000 000\n12345, PHP 1,500,000.25", 2026, false);

        // The school appears twice: the second row wins, the first is superseded.
        $this->assertSame(1, $analysis['readyCount']);
        $this->assertSame(1500000.25, $analysis['rows'][1]['amount']);
    }

    public function test_more_than_two_decimals_are_rounded_to_the_stored_figure(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText('12345, 100.375', 2026, false);

        $this->assertSame(100.38, $analysis['rows'][0]['amount']);
        $this->assertContains('Rounded to 2 decimals.', $analysis['rows'][0]['warnings']);
    }

    public function test_zero_is_an_allowed_amount(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText('12345, 0', 2026, false);

        $this->assertSame(1, $analysis['readyCount']);
        $this->assertSame(0.0, $analysis['rows'][0]['amount']);
    }

    /* ---------------------------------------------------------- blank rows */

    public function test_a_blank_amount_is_refused_until_the_clear_toggle_is_on(): void
    {
        $school = $this->school('school1', '12345');

        SchoolAllotment::create(['school_id' => $school->id, 'year' => 2026, 'amount' => 500]);

        $refused = SchoolAllotmentImporter::analyzeText('12345,', 2026, false);

        $this->assertSame(0, $refused['readyCount']);
        $this->assertSame(1, $refused['invalidCount']);
        $this->assertContains('Amount is required.', $refused['rows'][0]['errors']);

        $clearing = SchoolAllotmentImporter::analyzeText('12345,', 2026, true);

        $this->assertSame(1, $clearing['readyCount']);
        $this->assertSame('clear', $clearing['rows'][0]['action']);
        $this->assertNull($clearing['rows'][0]['amount']);
        $this->assertSame(500.0, $clearing['rows'][0]['previous_amount']);
    }

    /* ----------------------------------------------------------- duplicate */

    public function test_a_duplicate_school_id_keeps_the_last_row_and_flags_the_earlier_one(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText("12345, 100\n12345, 250", 2026, false);

        $this->assertSame(1, $analysis['readyCount']);
        $this->assertSame(1, $analysis['supersededCount']);

        $this->assertTrue($analysis['rows'][0]['superseded']);
        $this->assertSame(2, $analysis['rows'][0]['superseded_line']);
        $this->assertFalse($analysis['rows'][1]['superseded']);
        $this->assertSame(250.0, $analysis['rows'][1]['amount']);
    }

    /* -------------------------------------------------------- certification */

    public function test_a_changed_amount_clears_the_certification_in_the_preview(): void
    {
        $school = $this->school('school1', '12345');
        $budget = $this->budgetOfficer();

        SchoolAllotment::create([
            'school_id' => $school->id,
            'year' => 2026,
            'amount' => 1000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        $analysis = SchoolAllotmentImporter::analyzeText('12345, 2000', 2026, false);

        $this->assertSame('update', $analysis['rows'][0]['action']);
        $this->assertTrue($analysis['rows'][0]['clears_certification']);
        $this->assertSame(1, $analysis['clearsCertificationCount']);

        $same = SchoolAllotmentImporter::analyzeText('12345, 1000.00', 2026, false);

        $this->assertSame('unchanged', $same['rows'][0]['action']);
        $this->assertFalse($same['rows'][0]['clears_certification']);
        $this->assertSame(0, $same['clearsCertificationCount']);
    }

    /* --------------------------------------------------------------- commit */

    public function test_commit_writes_new_and_updated_rows_and_audits_them(): void
    {
        $new = $this->school('school1', '12345');
        $existing = $this->school('Bugtong Elementary School', '117833');
        $budget = $this->budgetOfficer();

        SchoolAllotment::create(['school_id' => $existing->id, 'year' => 2026, 'amount' => 1000]);

        $analysis = SchoolAllotmentImporter::analyzeText("12345, 2000000.00\n117833, 1500000", 2026, false);
        $report = SchoolAllotmentImporter::commit($analysis['rows'], [], 2026, $budget);

        $this->assertSame(2, $report['processed']);
        $this->assertSame(1, $report['created']);
        $this->assertSame(1, $report['updated']);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame([], $report['errors']);

        $this->assertSame(2000000.0, (float) SchoolAllotment::query()->where('school_id', $new->id)->value('amount'));
        $this->assertSame(1500000.0, (float) SchoolAllotment::query()->where('school_id', $existing->id)->value('amount'));

        $audits = SchoolAllotmentAudit::query()->orderBy('id')->get();

        $this->assertCount(2, $audits);
        $this->assertSame(SchoolAllotmentAudit::ACTION_SET, $audits[0]->action);
        $this->assertSame(SchoolAllotmentAudit::SOURCE_BULK, $audits[0]->source);
        $this->assertNull($audits[0]->previous_amount);
        $this->assertSame(2000000.0, (float) $audits[0]->amount);
        $this->assertSame($budget->id, $audits[0]->user_id);

        $this->assertSame(1000.0, (float) $audits[1]->previous_amount);
        $this->assertSame(1500000.0, (float) $audits[1]->amount);
    }

    public function test_commit_clears_the_certification_an_amount_change_invalidates(): void
    {
        $school = $this->school('school1', '12345');
        $budget = $this->budgetOfficer();

        SchoolAllotment::create([
            'school_id' => $school->id,
            'year' => 2026,
            'amount' => 1000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        $analysis = SchoolAllotmentImporter::analyzeText('12345, 2000', 2026, false);
        $report = SchoolAllotmentImporter::commit($analysis['rows'], [], 2026, $budget);

        $this->assertSame(1, $report['cleared_certification']);

        $allotment = SchoolAllotment::query()->firstOrFail();

        $this->assertFalse($allotment->isCertified());
    }

    public function test_commit_keeps_a_certification_when_the_same_amount_is_restated(): void
    {
        $school = $this->school('school1', '12345');
        $budget = $this->budgetOfficer();

        SchoolAllotment::create([
            'school_id' => $school->id,
            'year' => 2026,
            'amount' => 1000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        $analysis = SchoolAllotmentImporter::analyzeText('12345, 1000.00', 2026, false);
        $report = SchoolAllotmentImporter::commit($analysis['rows'], [], 2026, $budget);

        $this->assertSame(0, $report['processed']);
        $this->assertSame(1, $report['unchanged']);
        $this->assertSame(0, $report['cleared_certification']);
        $this->assertTrue(SchoolAllotment::query()->firstOrFail()->isCertified());
        // An unchanged row writes nothing to the trail.
        $this->assertSame(0, SchoolAllotmentAudit::query()->count());
    }

    public function test_commit_clears_the_allotment_when_the_amount_is_blank_and_the_toggle_is_on(): void
    {
        $school = $this->school('school1', '12345');
        $budget = $this->budgetOfficer();

        SchoolAllotment::create([
            'school_id' => $school->id,
            'year' => 2026,
            'amount' => 1000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        $analysis = SchoolAllotmentImporter::analyzeText('12345,', 2026, true);
        $report = SchoolAllotmentImporter::commit($analysis['rows'], [], 2026, $budget);

        $this->assertSame(1, $report['cleared']);
        $this->assertSame(0, SchoolAllotment::query()->count());

        $audit = SchoolAllotmentAudit::query()->firstOrFail();

        $this->assertSame(SchoolAllotmentAudit::ACTION_CLEAR, $audit->action);
        $this->assertSame(1000.0, (float) $audit->previous_amount);
        $this->assertNull($audit->amount);
    }

    public function test_commit_leaves_invalid_superseded_and_unchecked_rows_untouched(): void
    {
        $school = $this->school('school1', '12345');
        $other = $this->school('Other School', '117833');
        $budget = $this->budgetOfficer();

        $analysis = SchoolAllotmentImporter::analyzeText(
            "99999, 100\n12345, 100\n12345, 250\n117833, 300",
            2026,
            false,
        );

        $report = SchoolAllotmentImporter::commit($analysis['rows'], [4], 2026, $budget);

        // 99999 is unknown, line 2 is superseded by line 3, line 4 is unchecked.
        $this->assertSame(1, $report['processed']);
        $this->assertSame(1, $report['created']);
        $this->assertSame(3, $report['skipped']);

        $this->assertSame(0, SchoolAllotment::query()->where('school_id', $other->id)->count());
        $this->assertSame(250.0, (float) SchoolAllotment::query()->where('school_id', $school->id)->value('amount'));
        $this->assertSame(1, SchoolAllotmentAudit::query()->count());
    }

    public function test_commit_reports_a_school_that_vanished_after_the_preview(): void
    {
        $school = $this->school('school1', '12345');
        $budget = $this->budgetOfficer();

        $analysis = SchoolAllotmentImporter::analyzeText('12345, 500', 2026, false);

        $school->delete();

        $report = SchoolAllotmentImporter::commit($analysis['rows'], [], 2026, $budget);

        $this->assertSame(0, $report['processed']);
        $this->assertSame(1, $report['skipped']);
        $this->assertStringContainsString('SCHOOL_NOT_FOUND', $report['errors'][0]['error']);
        $this->assertSame(0, SchoolAllotmentAudit::query()->count());
    }

    public function test_commit_writes_against_the_year_it_is_given(): void
    {
        $school = $this->school('school1', '12345');
        $budget = $this->budgetOfficer();

        SchoolAllotment::create(['school_id' => $school->id, 'year' => 2026, 'amount' => 100]);

        $analysis = SchoolAllotmentImporter::analyzeText('12345, 900', 2027, false);
        SchoolAllotmentImporter::commit($analysis['rows'], [], 2027, $budget);

        $this->assertSame(100.0, (float) SchoolAllotment::query()->where('year', 2026)->value('amount'));
        $this->assertSame(900.0, (float) SchoolAllotment::query()->where('year', 2027)->value('amount'));
    }

    /* --------------------------------------------------------------- limits */

    public function test_it_refuses_more_rows_than_the_ceiling(): void
    {
        $lines = [];

        for ($i = 0; $i <= SchoolAllotmentImporter::MAX_ROWS; $i++) {
            $lines[] = '12345, '.$i;
        }

        $this->expectException(\RuntimeException::class);

        SchoolAllotmentImporter::analyzeText(implode("\n", $lines), 2026, false);
    }

    public function test_the_error_report_lists_every_row_that_could_not_be_applied(): void
    {
        $csv = SchoolAllotmentImporter::errorReportCsv([
            ['line' => 2, 'school_id' => '99999', 'error' => 'School not found.'],
            ['line' => 3, 'school_id' => '12345', 'error' => 'Superseded by row 4, the last occurrence wins.'],
        ]);

        $lines = explode("\n", trim($csv));

        $this->assertSame('Row,School ID,Reason', $lines[0]);
        $this->assertSame('2,99999,School not found.', $lines[1]);
        // A reason carrying a comma is quoted so the report stays one row.
        $this->assertSame('3,12345,"Superseded by row 4, the last occurrence wins."', $lines[2]);
    }
}
