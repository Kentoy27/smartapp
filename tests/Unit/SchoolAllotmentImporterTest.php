<?php

namespace Tests\Unit;

use App\Models\District;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\SchoolAllotmentAudit;
use App\Models\User;
use App\Support\SchoolAllotmentImporter;
use App\Support\SchoolDivision;
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

        $analysis = SchoolAllotmentImporter::analyzeText('12345, 2,000,000.00', 2026, false);

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

        $analysis = SchoolAllotmentImporter::analyzeText('99999, 100000', 2026, false);

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

    /* ------------------------------------------------------------- division */

    public function test_a_pasted_row_may_name_its_own_division(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText(
            "12345, Senior High, 2000000\n12345, JHS, 1500000",
            2026,
            false,
        );

        // The same school in two divisions is two allotments, not a duplicate.
        $this->assertSame(2, $analysis['readyCount']);
        $this->assertSame(0, $analysis['supersededCount']);
        $this->assertSame(0, $analysis['invalidCount']);

        $this->assertSame(SchoolDivision::SENIOR_HIGH, $analysis['rows'][0]['school_division']);
        $this->assertSame(2000000.0, $analysis['rows'][0]['amount']);
        $this->assertSame(SchoolDivision::JUNIOR_HIGH, $analysis['rows'][1]['school_division']);
        $this->assertSame(1500000.0, $analysis['rows'][1]['amount']);
    }

    public function test_the_division_column_accepts_the_common_spellings(): void
    {
        $this->school('school1', '12345');

        $spellings = [
            SchoolDivision::ELEMENTARY => ['Elementary', 'elem', 'ES'],
            SchoolDivision::JUNIOR_HIGH => ['Junior High', 'JHS', 'junior_high'],
            SchoolDivision::SENIOR_HIGH => ['Senior High', 'SHS', 'senior high school'],
        ];

        foreach ($spellings as $division => $values) {
            foreach ($values as $spelling) {
                $analysis = SchoolAllotmentImporter::analyzeText("12345, {$spelling}, 100", 2026, false);

                $this->assertSame(1, $analysis['readyCount'], $spelling);
                $this->assertSame($division, $analysis['rows'][0]['school_division'], $spelling);
            }
        }
    }

    public function test_a_csv_division_column_is_read_by_its_header(): void
    {
        $this->school('school1', '12345');

        $path = $this->csv([
            'school_id,division,allotted_budget',
            '12345,Senior High,2000000.00',
        ]);

        $analysis = SchoolAllotmentImporter::analyzeCsv($path, 2026, false);

        $this->assertSame(1, $analysis['readyCount']);
        $this->assertSame(SchoolDivision::SENIOR_HIGH, $analysis['rows'][0]['school_division']);
        $this->assertSame(2000000.0, $analysis['rows'][0]['amount']);
    }

    public function test_a_row_without_a_division_belongs_to_the_imports_own_division(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText('12345, 2000000', 2026, false, SchoolDivision::SENIOR_HIGH);

        $this->assertSame(1, $analysis['readyCount']);
        $this->assertSame(SchoolDivision::SENIOR_HIGH, $analysis['rows'][0]['school_division']);
    }

    public function test_a_line_that_stops_after_its_division_carries_no_amount(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText('12345, Senior High', 2026, false);

        $this->assertSame(0, $analysis['readyCount']);
        $this->assertContains('Amount is required.', $analysis['rows'][0]['errors']);
        $this->assertSame(SchoolDivision::SENIOR_HIGH, $analysis['rows'][0]['school_division']);
    }

    public function test_a_division_that_names_nothing_is_refused(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText('12345, College, 100', 2026, false);

        $this->assertSame(0, $analysis['readyCount']);
        $this->assertSame(1, $analysis['invalidCount']);
        $this->assertStringContainsString('Division must be', $analysis['rows'][0]['errors'][0]);
    }

    public function test_a_row_cannot_name_a_division_the_importer_may_not_write(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText(
            '12345, Senior High, 100',
            2026,
            false,
            SchoolDivision::ELEMENTARY,
            [SchoolDivision::ELEMENTARY],
        );

        $this->assertSame(0, $analysis['readyCount']);
        $this->assertSame(1, $analysis['invalidCount']);
        $this->assertContains(
            'You cannot set allotments for the Senior High School division.',
            $analysis['rows'][0]['errors'],
        );
    }

    public function test_commit_refuses_a_row_whose_division_is_not_allowed(): void
    {
        $this->school('school1', '12345');

        // Analyzed without a restriction, so the row is valid in the preview —
        // the commit must still re-check the caller's divisions rather than
        // trust the row it was handed.
        $analysis = SchoolAllotmentImporter::analyzeText('12345, Senior High, 100', 2026, false);

        $this->assertSame(1, $analysis['readyCount']);

        $report = SchoolAllotmentImporter::commit(
            $analysis['rows'],
            [],
            2026,
            $this->budgetOfficer(),
            SchoolDivision::ELEMENTARY,
            [SchoolDivision::ELEMENTARY],
        );

        $this->assertSame(0, $report['processed']);
        $this->assertSame(1, $report['skipped']);
        $this->assertStringContainsString('DIVISION_NOT_ALLOWED', $report['errors'][0]['error']);
        $this->assertSame(SchoolDivision::SENIOR_HIGH, $report['errors'][0]['school_division']);
        $this->assertSame(0, SchoolAllotment::query()->count());
    }

    public function test_commit_writes_each_row_to_the_division_it_names(): void
    {
        $school = $this->school('High School One', '12345');
        $budget = $this->budgetOfficer();

        $analysis = SchoolAllotmentImporter::analyzeText(
            "12345, Junior High, 1000000\n12345, Senior High, 2000000",
            2026,
            false,
        );

        $report = SchoolAllotmentImporter::commit($analysis['rows'], [], 2026, $budget);

        $this->assertSame(2, $report['created']);
        $this->assertSame([], $report['errors']);

        $rows = SchoolAllotment::query()->orderBy('school_division')->get();

        $this->assertCount(2, $rows);
        $this->assertSame($school->id, $rows[0]->school_id);
        $this->assertSame(SchoolDivision::JUNIOR_HIGH, $rows[0]->school_division);
        $this->assertSame(1000000.0, (float) $rows[0]->amount);
        $this->assertSame(SchoolDivision::SENIOR_HIGH, $rows[1]->school_division);
        $this->assertSame(2000000.0, (float) $rows[1]->amount);

        // The trail carries the division the money belongs to.
        $audits = SchoolAllotmentAudit::query()->orderBy('id')->get();

        $this->assertSame(SchoolDivision::JUNIOR_HIGH, $audits[0]->school_division);
        $this->assertSame(SchoolDivision::SENIOR_HIGH, $audits[1]->school_division);
    }

    public function test_a_duplicate_within_one_division_is_still_superseded(): void
    {
        $this->school('school1', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText(
            "12345, Senior High, 100\n12345, Senior High, 250",
            2026,
            false,
        );

        $this->assertSame(1, $analysis['readyCount']);
        $this->assertSame(1, $analysis['supersededCount']);
        $this->assertTrue($analysis['rows'][0]['superseded']);
        $this->assertSame(250.0, $analysis['rows'][1]['amount']);
    }

    public function test_a_senior_high_row_does_not_read_the_elementary_record(): void
    {
        $school = $this->school('High School One', '12345');

        SchoolAllotment::create([
            'school_id' => $school->id,
            'school_division' => SchoolDivision::ELEMENTARY,
            'year' => 2026,
            'amount' => 1000,
        ]);

        $analysis = SchoolAllotmentImporter::analyzeText('12345, Senior High, 2000', 2026, false);

        // The row's "current amount" is its own division's record, not the
        // school's Elementary figure.
        $this->assertSame('create', $analysis['rows'][0]['action']);
        $this->assertNull($analysis['rows'][0]['previous_amount']);
    }

    public function test_the_selection_names_the_divisions_its_rows_would_write_to(): void
    {
        $this->school('High School One', '12345');

        $analysis = SchoolAllotmentImporter::analyzeText(
            "12345, Senior High, 100\n12345, Junior High, 200\n12345, Senior High, 300",
            2026,
            false,
        );

        // Line 1 is superseded by line 3; the unchecked line 2 drops out.
        $counts = SchoolAllotmentImporter::selectionCounts($analysis['rows'], [2]);

        $this->assertSame(1, $counts['selected']);
        $this->assertSame([SchoolDivision::SENIOR_HIGH], $counts['divisions']);
    }

    public function test_the_division_summary_names_one_or_several_divisions(): void
    {
        $this->assertSame(
            'Senior High School',
            SchoolAllotmentImporter::divisionSummary([SchoolDivision::SENIOR_HIGH]),
        );

        // The canonical order holds however the list arrives.
        $this->assertSame(
            'Elementary and Senior High School',
            SchoolAllotmentImporter::divisionSummary([SchoolDivision::SENIOR_HIGH, SchoolDivision::ELEMENTARY]),
        );

        $this->assertSame(
            'Elementary, Junior High School and Senior High School',
            SchoolAllotmentImporter::divisionSummary(SchoolDivision::all()),
        );

        $this->assertSame('', SchoolAllotmentImporter::divisionSummary([]));
    }

    public function test_export_csv_round_trips_through_the_importer(): void
    {
        $this->school('school1', '12345');

        $csv = SchoolAllotmentImporter::exportCsv([
            ['school_id' => '12345', 'school_name' => 'school1', 'school_division' => SchoolDivision::ELEMENTARY, 'amount' => 2000000.0],
            ['school_id' => '12345', 'school_name' => 'school1', 'school_division' => SchoolDivision::SENIOR_HIGH, 'amount' => null],
        ]);

        $path = $this->csv(explode("\n", trim($csv)));

        $analysis = SchoolAllotmentImporter::analyzeCsv($path, 2026, true);

        // The export is the bulk template: its headers are the ones the
        // importer reads, so a filled-in copy uploads as-is.
        $this->assertSame(2, $analysis['readyCount']);
        $this->assertSame(0, $analysis['invalidCount']);
        $this->assertSame(SchoolDivision::ELEMENTARY, $analysis['rows'][0]['school_division']);
        $this->assertSame(2000000.0, $analysis['rows'][0]['amount']);
        $this->assertSame(SchoolDivision::SENIOR_HIGH, $analysis['rows'][1]['school_division']);
        // The blank amount reads as "clear" while the toggle is on.
        $this->assertNull($analysis['rows'][1]['amount']);
        $this->assertSame('clear', $analysis['rows'][1]['action']);
    }

    /* -------------------------------------------------------- certification */

    public function test_a_changed_amount_on_a_certified_allotment_is_locked_in_the_preview(): void
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

        $this->assertTrue($analysis['rows'][0]['locked']);
        $this->assertNull($analysis['rows'][0]['action']);
        $this->assertFalse($analysis['rows'][0]['valid']);
        $this->assertSame(SchoolAllotmentImporter::LOCKED_MESSAGE, $analysis['rows'][0]['errors'][0]);
        $this->assertSame(0, $analysis['readyCount']);
        $this->assertSame(1, $analysis['lockedCount']);
        $this->assertSame(0, $analysis['invalidCount']);

        // A blank amount (the clear toggle on) is refused the same way — the
        // certificate is never cleared by a bulk row either.
        $clear = SchoolAllotmentImporter::analyzeText('12345,', 2026, true);

        $this->assertTrue($clear['rows'][0]['locked']);
        $this->assertSame(1, $clear['lockedCount']);

        // Re-stating the same figure changes nothing and stays accepted, so a
        // re-uploaded export never trips the lock.
        $same = SchoolAllotmentImporter::analyzeText('12345, 1000.00', 2026, false);

        $this->assertSame('unchanged', $same['rows'][0]['action']);
        $this->assertFalse($same['rows'][0]['locked']);
        $this->assertTrue($same['rows'][0]['valid']);
        $this->assertSame(0, $same['lockedCount']);
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

    public function test_commit_leaves_a_certified_allotment_untouched(): void
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

        // The preview refused the row, so commit skips it: nothing written,
        // nothing audited, the certificate intact.
        $this->assertSame(0, $report['processed']);
        $this->assertSame(1, $report['skipped']);

        $allotment = SchoolAllotment::query()->firstOrFail();

        $this->assertSame(1000.0, (float) $allotment->amount);
        $this->assertTrue($allotment->isCertified());
        $this->assertSame(0, SchoolAllotmentAudit::query()->count());
    }

    public function test_commit_holds_its_own_lock_for_a_certified_row_handed_in_directly(): void
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

        // The analysis already refuses such a row; commit re-checks the stored
        // state itself, so a caller that skips the preview cannot slip past it.
        $report = SchoolAllotmentImporter::commit([
            [
                'line' => 1,
                'school_id' => '12345',
                'school_db_id' => $school->id,
                'school_division' => SchoolDivision::ELEMENTARY,
                'amount' => 2000.0,
                'valid' => true,
                'superseded' => false,
            ],
        ], [], 2026, $budget);

        $this->assertSame(0, $report['processed']);
        $this->assertSame(1, $report['skipped']);
        $this->assertSame(SchoolAllotmentImporter::LOCKED_MESSAGE, $report['errors'][0]['error']);
        $this->assertSame(1000.0, (float) SchoolAllotment::query()->firstOrFail()->amount);
        $this->assertSame(0, SchoolAllotmentAudit::query()->count());
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
        $this->assertTrue(SchoolAllotment::query()->firstOrFail()->isCertified());
        // An unchanged row writes nothing to the trail.
        $this->assertSame(0, SchoolAllotmentAudit::query()->count());
    }

    public function test_commit_clears_the_allotment_when_the_amount_is_blank_and_the_toggle_is_on(): void
    {
        $school = $this->school('school1', '12345');
        $budget = $this->budgetOfficer();

        SchoolAllotment::create(['school_id' => $school->id, 'year' => 2026, 'amount' => 1000]);

        $analysis = SchoolAllotmentImporter::analyzeText('12345,', 2026, true);
        $report = SchoolAllotmentImporter::commit($analysis['rows'], [], 2026, $budget);

        $this->assertSame(1, $report['cleared']);
        $this->assertSame(0, SchoolAllotment::query()->count());

        $audit = SchoolAllotmentAudit::query()->firstOrFail();

        $this->assertSame(SchoolAllotmentAudit::ACTION_CLEAR, $audit->action);
        $this->assertSame(1000.0, (float) $audit->previous_amount);
        $this->assertNull($audit->amount);
    }

    public function test_commit_never_clears_a_certified_allotment(): void
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

        // The preview refuses the row; commit's own guard holds even when it
        // is handed the row directly.
        $report = SchoolAllotmentImporter::commit([
            [
                'line' => 1,
                'school_id' => '12345',
                'school_db_id' => $school->id,
                'school_division' => SchoolDivision::ELEMENTARY,
                'amount' => null,
                'valid' => true,
                'superseded' => false,
            ],
        ], [], 2026, $budget);

        $this->assertSame(0, $report['processed']);
        $this->assertSame(1, $report['skipped']);
        $this->assertSame(SchoolAllotmentImporter::LOCKED_MESSAGE, $report['errors'][0]['error']);
        $this->assertSame(1, SchoolAllotment::query()->count());
        $this->assertTrue(SchoolAllotment::query()->firstOrFail()->isCertified());
        $this->assertSame(0, SchoolAllotmentAudit::query()->count());
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
            ['line' => 3, 'school_id' => '12345', 'school_division' => SchoolDivision::SENIOR_HIGH, 'error' => 'Superseded by row 4, the last occurrence wins.'],
        ]);

        $lines = explode("\n", trim($csv));

        $this->assertSame('Row,School ID,Division,Reason', $lines[0]);
        // A row with no division on record carries an empty cell.
        $this->assertSame('2,99999,,School not found.', $lines[1]);
        // A reason carrying a comma is quoted so the report stays one row.
        $this->assertSame('3,12345,Senior High School,"Superseded by row 4, the last occurrence wins."', $lines[2]);
    }
}
