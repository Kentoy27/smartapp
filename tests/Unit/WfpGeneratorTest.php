<?php

namespace Tests\Unit;

use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Models\WfpPlan;
use App\Support\WfpGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WfpGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private function school(): School
    {
        $district = District::create(['name' => 'District 1']);

        return $this->school = School::create(['district_id' => $district->id, 'name' => 'Test School']);
    }

    private function matrix(int $month = 1): MonthlyExpenditureMatrix
    {
        return MonthlyExpenditureMatrix::create([
            'school_id' => $this->school->id,
            'year' => 2026,
            'month' => $month,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
        ]);
    }

    private function line(MonthlyExpenditureMatrix $matrix, array $overrides = []): MonthlyExpenditureItem
    {
        $item = new MonthlyExpenditureItem(array_merge([
            'matrix_id' => $matrix->id,
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'quantity' => 10,
            'unit' => 'ream',
            'unit_cost' => 250,
            'account_title' => 'Office Supplies',
            'account_code' => '5020301000',
            'funding_source' => 'MOOE',
            'organizational_classification' => 'School',
            'activity_movs' => 'PO, RIS',
        ], $overrides));
        $item->syncTotal();
        $item->save();

        return $item;
    }

    private function plan(): WfpPlan
    {
        return WfpPlan::create([
            'school_id' => $this->school->id,
            'year' => 2026,
            'status' => WfpPlan::STATUS_DRAFT,
        ]);
    }

    public function test_it_maps_mem_lines_into_month_grained_wfp_lines(): void
    {
        $this->school();
        $matrix = $this->matrix(month: 3);
        $this->line($matrix);
        $plan = $this->plan();

        $result = WfpGenerator::generate($plan, $matrix);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['flagged']);

        $wfp = $plan->items()->firstOrFail();
        $this->assertSame(3, (int) $wfp->month);
        $this->assertSame('Procure supplies', $wfp->program_activity);
        $this->assertEquals(10.0, (float) $wfp->physical_target);
        $this->assertSame('ream', $wfp->physical_unit);
        $this->assertEquals(2500.0, (float) $wfp->financial_target);
        $this->assertSame('MOOE', $wfp->funding_source);
        $this->assertSame(1, (int) $wfp->source_matrix_version);
        $this->assertSame($matrix->id, $wfp->source_matrix_id);

        // WFP-only fields are empty and flagged on the first generation.
        $this->assertTrue($wfp->needs_review);
        $this->assertContains('major_output', $wfp->review_fields);
        $this->assertContains('performance_indicator', $wfp->review_fields);

        // Generation records the plan's status.
        $this->assertSame(WfpPlan::STATUS_GENERATED, $plan->fresh()->status);
        $this->assertNotNull($plan->fresh()->generated_at);
    }

    public function test_regeneration_preserves_wfp_only_fields_and_refreshes_mem_fields(): void
    {
        $this->school();
        $matrix = $this->matrix();
        $line = $this->line($matrix);
        $plan = $this->plan();

        WfpGenerator::generate($plan, $matrix);

        $wfp = $plan->items()->firstOrFail();
        $wfp->major_output = 'Published report';
        $wfp->performance_indicator = 'One report';
        $wfp->refreshFlags();
        $wfp->save();

        // The MEM changes and its version advances.
        $line->quantity = 20;
        $line->syncTotal();
        $line->save();
        $matrix->bumpVersion();

        $result = WfpGenerator::generate($plan, $matrix);

        $this->assertSame(1, $result['updated']);

        $fresh = $wfp->fresh();
        $this->assertSame('Published report', $fresh->major_output);
        $this->assertSame('One report', $fresh->performance_indicator);
        $this->assertEquals(5000.0, (float) $fresh->financial_target);
        $this->assertSame(2, (int) $fresh->source_matrix_version);
        // The WFP-only fields are filled now, so the plan is no longer flagged.
        $this->assertFalse($fresh->needs_review);
    }

    public function test_shared_carried_fields_take_the_mem_value_when_it_appears(): void
    {
        $this->school();
        $matrix = $this->matrix();
        $line = $this->line($matrix, ['funding_source' => null]);
        $plan = $this->plan();

        WfpGenerator::generate($plan, $matrix);

        $wfp = $plan->items()->firstOrFail();
        $wfp->funding_source = 'Local Funds';
        $wfp->save();
        $this->assertContains('funding_source', $wfp->fresh()->review_fields);

        // Regenerating with the MEM still blank keeps the SH-entered value.
        WfpGenerator::generate($plan, $matrix);
        $this->assertSame('Local Funds', $wfp->fresh()->funding_source);

        // Once the MEM carries one, it wins.
        $line->funding_source = 'MOOE';
        $line->save();
        $matrix->bumpVersion();

        WfpGenerator::generate($plan, $matrix);
        $this->assertSame('MOOE', $wfp->fresh()->funding_source);
    }

    public function test_output_fields_from_the_mem_enrich_the_generated_wfp(): void
    {
        $this->school();
        $matrix = $this->matrix();
        $line = $this->line($matrix, [
            'major_output' => 'Reading camp conducted',
            'performance_indicator' => '80% of learners attend',
        ]);
        $plan = $this->plan();

        WfpGenerator::generate($plan, $matrix);

        $wfp = $plan->items()->firstOrFail();
        $this->assertSame('Reading camp conducted', $wfp->major_output);
        $this->assertSame('80% of learners attend', $wfp->performance_indicator);
        $this->assertFalse($wfp->needs_review);

        // A WFP-entered wording survives while the MEM is blank.
        $line->major_output = null;
        $line->performance_indicator = null;
        $line->save();
        $wfp->major_output = 'WFP wording';
        $wfp->save();
        $matrix->bumpVersion();

        WfpGenerator::generate($plan, $matrix);
        $this->assertSame('WFP wording', $wfp->fresh()->major_output);

        // Once the MEM carries one, it wins.
        $line->major_output = 'MEM wording';
        $line->save();
        $matrix->bumpVersion();

        WfpGenerator::generate($plan, $matrix);
        $this->assertSame('MEM wording', $wfp->fresh()->major_output);
    }

    public function test_gone_lines_are_removed(): void
    {
        $this->school();
        $matrix = $this->matrix();
        $line = $this->line($matrix);
        $plan = $this->plan();

        WfpGenerator::generate($plan, $matrix);
        $this->assertSame(1, $plan->items()->count());

        $line->delete();
        $matrix->bumpVersion();

        $result = WfpGenerator::generate($plan, $matrix);

        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, $plan->items()->count());
    }

    public function test_out_of_date_is_derived_from_the_source_version(): void
    {
        $this->school();
        $matrix = $this->matrix();
        $this->line($matrix);
        $plan = $this->plan();

        WfpGenerator::generate($plan, $matrix);
        $this->assertFalse($plan->isOutOfDate());

        $matrix->bumpVersion();

        $this->assertTrue($plan->fresh()->isOutOfDate());
        $this->assertCount(1, $plan->fresh()->staleSources());
        $this->assertSame($matrix->id, $plan->fresh()->staleSources()[0]['matrix']->id);
    }

    public function test_export_rows_group_by_key_for_an_annual_template(): void
    {
        $this->school();
        $january = $this->matrix(month: 1);
        $this->line($january, ['quantity' => 10, 'unit_cost' => 100]);
        $february = $this->matrix(month: 2);
        $this->line($february, ['quantity' => 5, 'unit_cost' => 100]);
        $plan = $this->plan();

        WfpGenerator::generate($plan);

        // Same grouping fields (the two lines differ only by month) → one row,
        // with the physical and financial targets summed — preceded by its
        // account-title section row.
        $rows = WfpGenerator::buildExportRows($plan, monthly: false);

        $this->assertCount(2, $rows);
        $this->assertSame(true, $rows[0]['__account_title__'] ?? false);
        $this->assertEquals(1500.0, (float) $rows[1]['financial_target']);
        $this->assertEquals(15.0, (float) $rows[1]['physical_target']);

        // Month-shaped keeps one row per line, each with its month — plus the
        // shared account-title section row ahead of them.
        $monthlyRows = WfpGenerator::buildExportRows($plan, monthly: true);
        $this->assertCount(3, $monthlyRows);
        $this->assertSame(true, $monthlyRows[0]['__account_title__'] ?? false);
        $this->assertSame(1, $monthlyRows[1]['month']);
        $this->assertSame(2, $monthlyRows[2]['month']);
    }
}
