<?php

namespace Tests\Feature;

use App\Livewire\MemEditor;
use App\Livewire\WfpPlanEditor;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Models\WfpPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsWfpTemplates;
use Tests\TestCase;

class WfpGenerationTest extends TestCase
{
    use BuildsWfpTemplates;
    use RefreshDatabase;

    private function schoolHead(string $name = 'Test School'): User
    {
        $district = District::create(['name' => 'District '.$name]);
        $school = School::create(['district_id' => $district->id, 'name' => $name]);

        return User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => $school->id]);
    }

    private function matrixWithLine(User $sh, int $month = 1): MonthlyExpenditureMatrix
    {
        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'month' => $month,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
        ]);

        $item = new MonthlyExpenditureItem([
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
        ]);
        $item->syncTotal();
        $item->save();

        return $matrix;
    }

    private function generatedPlan(User $sh): array
    {
        $matrix = $this->matrixWithLine($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])->call('generateWfp');

        return [$matrix, WfpPlan::firstOrFail()];
    }

    public function test_generating_from_the_editor_creates_a_plan_and_redirects(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixWithLine($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('generateWfp');

        $plan = WfpPlan::firstOrFail();

        $component->assertRedirect(route('wfp.plans.edit', ['plan' => $plan->id]));

        $this->assertSame($sh->school_id, $plan->school_id);
        $this->assertSame(2026, $plan->year);
        $this->assertSame(1, $plan->items()->count());

        $wfp = $plan->items()->firstOrFail();
        $this->assertSame(1, (int) $wfp->month);
        $this->assertEquals(2500.0, (float) $wfp->financial_target);
        $this->assertTrue($wfp->needs_review);
        $this->assertContains('major_output', $wfp->review_fields);
    }

    public function test_an_empty_matrix_refuses_to_generate(): void
    {
        $sh = $this->schoolHead();
        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'month' => 1,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('generateWfp')
            ->assertSet('errorMessage', fn ($value) => $value !== null);

        $this->assertSame(0, WfpPlan::count());
    }

    public function test_the_editor_overrides_flags_and_survives_regeneration(): void
    {
        $sh = $this->schoolHead();
        [$matrix, $plan] = $this->generatedPlan($sh);

        $wfp = $plan->items()->firstOrFail();

        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan])
            ->set("items.{$wfp->id}.major_output", 'Published report')
            ->set("items.{$wfp->id}.performance_indicator", 'One report')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Published report', $wfp->fresh()->major_output);
        $this->assertFalse($wfp->fresh()->needs_review);

        // The MEM changes and its version advances.
        $line = $matrix->items()->firstOrFail();
        $line->quantity = 20;
        $line->syncTotal();
        $line->save();
        $matrix->bumpVersion();

        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan])
            ->assertSee('Out of date')
            ->call('regenerate');

        $fresh = $wfp->fresh();
        $this->assertSame('Published report', $fresh->major_output);
        $this->assertEquals(5000.0, (float) $fresh->financial_target);
        $this->assertFalse($plan->fresh()->isOutOfDate());
    }

    public function test_a_plan_can_be_approved(): void
    {
        $sh = $this->schoolHead();
        [, $plan] = $this->generatedPlan($sh);

        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan])
            ->call('approve');

        $this->assertSame(WfpPlan::STATUS_FINALIZED, $plan->fresh()->status);
        $this->assertSame($sh->id, $plan->fresh()->approved_by);
        $this->assertNotNull($plan->fresh()->approved_at);
    }

    public function test_the_export_returns_a_patched_workbook(): void
    {
        $sh = $this->schoolHead();
        [, $plan] = $this->generatedPlan($sh);

        // Give the export a synthetic template to patch, so the test does not
        // depend on the shipped file's exact layout.
        $name = 'test-wfp-'.uniqid().'.xlsx';
        file_put_contents(storage_path('forms/'.$name), $this->wfpTemplateBytes(3));
        config(['wfp.template_file' => $name]);

        try {
            $this->actingAs($sh)
                ->get(route('wfp.plans.export', ['plan' => $plan->id]))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        } finally {
            @unlink(storage_path('forms/'.$name));
        }
    }

    public function test_another_school_head_cannot_reach_the_plan_or_its_export(): void
    {
        $owner = $this->schoolHead('School A');
        $other = $this->schoolHead('School B');
        [, $plan] = $this->generatedPlan($owner);

        $this->actingAs($other)->get(route('wfp.plans.edit', ['plan' => $plan->id]))->assertNotFound();
        $this->actingAs($other)->get(route('wfp.plans.export', ['plan' => $plan->id]))->assertNotFound();

        $this->assertStringContainsString(
            'Page Not Found',
            Livewire::actingAs($other)->test(WfpPlanEditor::class, ['plan' => $plan])->html()
        );
    }

    public function test_the_plan_editor_refuses_a_guest(): void
    {
        $owner = $this->schoolHead();
        [, $plan] = $this->generatedPlan($owner);

        $this->assertStringContainsString('Page Not Found', Livewire::test(WfpPlanEditor::class)->html());
    }
}
