<?php

namespace Tests\Unit;

use App\Models\District;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Support\MemImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemImporterTest extends TestCase
{
    use RefreshDatabase;

    private function matrix(int $month = 5, int $year = 2026): MonthlyExpenditureMatrix
    {
        $district = District::create(['name' => 'District 1']);
        $school = School::create(['district_id' => $district->id, 'name' => 'Test School']);
        $user = User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => $school->id]);

        return MonthlyExpenditureMatrix::create([
            'school_id' => $school->id,
            'year' => $year,
            'month' => $month,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function csv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mem-test-').'.csv';
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    public function test_it_analyzes_a_csv_with_a_header_row_and_recomputes_nothing_yet(): void
    {
        $matrix = $this->matrix();

        $path = $this->csv([
            'Section,Objective,Activity Description,Resource Kind,Quantity,Unit,Unit Cost,Account Title,Account Code,Funding Source,Organizational Classification,Activity MOVs',
            'operations,Improved outcomes,Procure supplies,MOOE,10,ream,250.00,Office Supplies,5020301000,MOOE,School,PO and RIS',
            'Project,,Build a fence,,5,,100,Construction,5021301000,,,',
        ]);

        $analysis = MemImporter::analyzeCsv($path, $matrix);

        $this->assertSame(2, $analysis['validCount']);
        $this->assertSame(0, $analysis['invalidCount']);

        $first = $analysis['valid'][0];
        $this->assertSame('operations', $first['section']);
        $this->assertSame('Procure supplies', $first['activity_description']);
        $this->assertSame(10.0, $first['quantity']);
        $this->assertSame(250.0, $first['unit_cost']);
        // The total is never taken from the file.
        $this->assertArrayNotHasKey('calculated_total', $first);

        $second = $analysis['valid'][1];
        $this->assertSame('project', $second['section']);
        $this->assertNull($second['objective']);
    }

    public function test_it_flags_missing_required_fields_and_bad_numbers(): void
    {
        $matrix = $this->matrix();

        $path = $this->csv([
            'Section,Activity Description,Quantity,Unit Cost',
            ',Some activity,not-a-number,10',
            'bogus,Another activity,5,10',
            'operations,,5,10',
        ]);

        $analysis = MemImporter::analyzeCsv($path, $matrix);

        $this->assertSame(0, $analysis['validCount']);
        $this->assertSame(3, $analysis['invalidCount']);
        $this->assertStringContainsString('Section must be one of', implode(' ', $analysis['rows'][0]['errors']));
        $this->assertStringContainsString('Quantity must be a number', implode(' ', $analysis['rows'][0]['errors']));
        $this->assertStringContainsString('Activity Description is required', implode(' ', $analysis['rows'][2]['errors']));
    }

    public function test_it_rejects_a_month_that_does_not_match_the_target_matrix(): void
    {
        $matrix = $this->matrix(month: 5); // May

        $path = $this->csv([
            'Section,Activity Description,Quantity,Unit Cost,Month',
            'operations,Some activity,5,10,June',
        ]);

        $analysis = MemImporter::analyzeCsv($path, $matrix);

        $this->assertSame(0, $analysis['validCount']);
        $this->assertStringContainsString('Month does not match', implode(' ', $analysis['rows'][0]['errors']));
    }

    public function test_commit_inserts_lines_recomputes_totals_and_bumps_the_version(): void
    {
        $matrix = $this->matrix();

        $analysis = MemImporter::analyzeRows([
            ['line' => 1, 'data' => ['section' => 'operations', 'activity_description' => 'A', 'quantity' => '10', 'unit_cost' => '250']],
            ['line' => 2, 'data' => ['section' => 'project', 'activity_description' => 'B', 'quantity' => '5', 'unit_cost' => '100']],
        ], $matrix);

        $report = MemImporter::commit($analysis['valid'], $matrix, null);

        $this->assertSame(2, $report['imported']);

        $items = $matrix->items()->get();
        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]->position);
        $this->assertSame(2, $items[1]->position);
        // 10 x 250 and 5 x 100, recomputed server-side.
        $this->assertEquals(2500.0, (float) $items[0]->calculated_total);
        $this->assertEquals(500.0, (float) $items[1]->calculated_total);
        // The version advanced, so a WFP generated from v1 now reads out of date.
        $this->assertSame(2, (int) $matrix->fresh()->version);
    }
}
