<?php

namespace Tests\Unit;

use App\Models\AccountClassification;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Models\WfpPlan;
use App\Support\WfpGenerator;
use App\Support\WfpValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WfpValidatorTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private function school(): School
    {
        $district = District::create(['name' => 'District 1']);

        return $this->school = School::create(['district_id' => $district->id, 'name' => 'Test School']);
    }

    private function matrix(int $month): MonthlyExpenditureMatrix
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
        ], $overrides));
        $item->syncTotal();
        $item->save();

        return $item;
    }

    private function knownAccount(string $code): void
    {
        AccountClassification::create([
            'account_title' => 'Office Supplies Expenses',
            'rca_code' => '3020301',
            'uacs_sub_object_code' => '5020301',
            'uacs_object_code' => $code,
            'is_active' => true,
        ]);
    }

    public function test_a_reconciled_plan_with_known_codes_passes(): void
    {
        $this->school();
        $this->knownAccount('5020301000');
        $matrix = $this->matrix(1);
        $this->line($matrix);

        $plan = WfpPlan::create(['school_id' => $this->school->id, 'year' => 2026, 'status' => WfpPlan::STATUS_DRAFT]);
        WfpGenerator::generate($plan);

        $report = WfpValidator::report($plan);

        $this->assertTrue($report['grand']['matches']);
        $this->assertSame([], $report['unknownCodes']);
        $this->assertTrue($report['passed']);
        $this->assertEquals(2500.0, $report['grand']['matrix']);
        $this->assertEquals(2500.0, $report['grand']['plan']);
    }

    public function test_it_names_account_codes_the_master_list_does_not_know(): void
    {
        $this->school();
        $matrix = $this->matrix(1);
        $this->line($matrix, ['account_code' => '9999999999']);

        $plan = WfpPlan::create(['school_id' => $this->school->id, 'year' => 2026, 'status' => WfpPlan::STATUS_DRAFT]);
        WfpGenerator::generate($plan);

        $report = WfpValidator::report($plan);

        $this->assertCount(1, $report['unknownCodes']);
        $this->assertSame('9999999999', $report['unknownCodes'][0]['code']);
        $this->assertSame(1, $report['unknownCodes'][0]['count']);
        $this->assertFalse($report['passed']);
    }

    public function test_a_plan_total_that_drifts_from_the_matrix_is_flagged(): void
    {
        $this->school();
        $this->knownAccount('5020301000');
        $matrix = $this->matrix(1);
        $this->line($matrix);

        $plan = WfpPlan::create(['school_id' => $this->school->id, 'year' => 2026, 'status' => WfpPlan::STATUS_DRAFT]);
        WfpGenerator::generate($plan);

        // A WFP-owned line the matrix does not carry — the plan now overshoots.
        $plan->items()->create([
            'wfp_plan_id' => $plan->id,
            'source_expenditure_item_id' => null,
            'group_key' => 'manual-group',
            'position' => 99,
            'month' => 1,
            'section' => 'operations',
            'program_activity' => 'Manually added',
            'financial_target' => 100,
            'account_code' => '5020301000',
        ]);

        $report = WfpValidator::report($plan);

        $this->assertFalse($report['grand']['matches']);
        $this->assertFalse($report['months'][0]['matches']); // January
        $this->assertFalse($report['passed']);
    }

    public function test_it_reports_missing_enrichment_and_near_duplicates(): void
    {
        $this->school();
        $this->knownAccount('5020301000');

        $january = $this->matrix(1);
        $this->line($january, ['activity_description' => 'Purchase computers']);
        $february = $this->matrix(2);
        $this->line($february, ['activity_description' => 'Purchase computer']);

        $plan = WfpPlan::create(['school_id' => $this->school->id, 'year' => 2026, 'status' => WfpPlan::STATUS_DRAFT]);
        WfpGenerator::generate($plan);

        $report = WfpValidator::report($plan);

        // Both rows are missing their Major Output; neither has an indicator.
        $this->assertSame(2, $report['missing']['major_output']);
        $this->assertSame(2, $report['missing']['performance_indicator']);

        // The two activities differ by one character — a near-duplicate.
        $this->assertNotEmpty($report['nearDuplicates']);
    }
}
