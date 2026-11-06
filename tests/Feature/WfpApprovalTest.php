<?php

namespace Tests\Feature;

use App\Livewire\MemEditor;
use App\Livewire\Sidebar;
use App\Livewire\WfpApprovals;
use App\Livewire\WfpPlanEditor;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Models\WfpApproval;
use App\Models\WfpPlan;
use App\Models\WfpReportCheck;
use App\Support\SchoolDivision;
use App\Support\WfpGenerator;
use App\Support\WfpTemplateFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\BuildsWfpTemplates;
use Tests\TestCase;

/**
 * The Superadmin's WFP final-approval workflow: Budget-certified plans are
 * submitted by the School Head, decided by the Superadmin, and the printed
 * sign-off comes from the recorded decision — never from a placeholder.
 */
class WfpApprovalTest extends TestCase
{
    use BuildsWfpTemplates;
    use RefreshDatabase;

    /* ------------------------------------------------------------- helpers */

    private function schoolHead(string $name = 'Test School'): User
    {
        $district = District::create(['name' => 'District '.$name]);
        $school = School::create(['district_id' => $district->id, 'name' => $name]);

        return User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => $school->id]);
    }

    private function superadmin(string $name = 'SDS Grace Villanueva'): User
    {
        return User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true, 'name' => $name]);
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

    private function generatedPlan(User $sh): WfpPlan
    {
        $matrix = $this->matrixWithLine($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])->call('generateWfp');

        return WfpPlan::query()->where('school_id', $sh->school_id)->firstOrFail();
    }

    /** Budget's two sign-offs: the Bookkeeper's check and the Budget certification. */
    private function budgetReview(WfpPlan $plan): void
    {
        $bookkeeper = User::factory()->create(['role' => 'bookkeeper', 'is_superadmin' => false, 'name' => 'Bella Bookkeeper']);
        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false, 'name' => 'Lyn Budget']);

        WfpReportCheck::updateOrCreate(
            [
                'school_id' => $plan->school_id,
                'school_division' => $plan->schoolDivision(),
                'year' => $plan->year,
            ],
            ['checked_by' => $bookkeeper->id, 'checked_at' => now()],
        );

        SchoolAllotment::updateOrCreate(
            [
                'school_id' => $plan->school_id,
                'school_division' => $plan->schoolDivision(),
                'year' => $plan->year,
            ],
            ['amount' => 50000, 'certified_by' => $budget->id, 'certified_at' => now()],
        );
    }

    private function pendingPlan(User $sh): WfpPlan
    {
        $plan = $this->generatedPlan($sh);
        $this->budgetReview($plan);

        $plan->refresh()->submitForApproval($sh);

        return $plan->fresh();
    }

    /** Point the export at a synthetic template; returns the temp file name. */
    private function useSyntheticTemplate(bool $withApprovalBlock = false): string
    {
        $name = 'test-wfp-'.uniqid().'.xlsx';
        file_put_contents(storage_path('forms/'.$name), $this->wfpTemplateBytes(3, null, $withApprovalBlock));
        config(['wfp.template_file' => $name, 'wfp.template.school_name_cell' => 'B5']);

        return $name;
    }

    /** The row the "Approved:" label currently sits on, in a filled workbook. */
    private function approvalLabelRow(string $sheetXml): int
    {
        $this->assertSame(
            1,
            preg_match('/<c\b[^>]*\br="A(\d+)"[^>]*t="inlineStr"[^>]*><is><t xml:space="preserve">Approved:<\/t><\/is><\/c>/', $sheetXml, $m),
            'The synthetic template should carry an "Approved:" label.'
        );

        return (int) $m[1];
    }

    /* ---------------------------------------------------- authorization */

    public function test_only_the_superadmin_reaches_the_approval_queue(): void
    {
        $sh = $this->schoolHead();
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);
        $superadmin = $this->superadmin();

        $this->get(route('wfp.approvals.index'))->assertRedirect(route('login'));

        $this->actingAs($sh)->get(route('wfp.approvals.index'))->assertNotFound();
        $this->actingAs($admin)->get(route('wfp.approvals.index'))->assertNotFound();
        $this->actingAs($budget)->get(route('wfp.approvals.index'))->assertNotFound();
        $this->actingAs($superadmin)->get(route('wfp.approvals.index'))->assertOk();

        $this->assertStringContainsString('Page Not Found', Livewire::actingAs($sh)->test(WfpApprovals::class)->html());
        $this->assertStringContainsString('Page Not Found', Livewire::actingAs($admin)->test(WfpApprovals::class)->html());

        Livewire::actingAs($superadmin)->test(WfpApprovals::class)->assertOk();
    }

    public function test_the_sidebar_offers_the_queue_to_the_superadmin_only(): void
    {
        $sh = $this->schoolHead();
        $admin = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $superadmin = $this->superadmin();

        $labels = fn (User $user): array => collect(Livewire::actingAs($user)->test(Sidebar::class)->instance()->items())
            ->pluck('label')
            ->all();

        $this->assertContains('WFP Final Approvals', $labels($superadmin));
        $this->assertNotContains('WFP Final Approvals', $labels($admin));
        $this->assertNotContains('WFP Final Approvals', $labels($sh));
    }

    /* ------------------------------------------------------ Budget gating */

    public function test_a_plan_cannot_be_submitted_until_budget_certifies_and_checks_it(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->generatedPlan($sh);

        $component = Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan]);
        $this->assertFalse($component->instance()->canSubmitForApproval);

        $component->call('submitForApproval')->assertSet('errorMessage', fn ($value) => $value !== null);
        $this->assertSame(WfpPlan::STATUS_GENERATED, $plan->fresh()->status);

        // The Bookkeeper's check alone is not enough.
        $bookkeeper = User::factory()->create(['role' => 'bookkeeper', 'is_superadmin' => false]);
        WfpReportCheck::create([
            'school_id' => $plan->school_id,
            'year' => $plan->year,
            'checked_by' => $bookkeeper->id,
            'checked_at' => now(),
        ]);

        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan->fresh()])
            ->call('submitForApproval')
            ->assertSet('errorMessage', fn ($value) => $value !== null);
        $this->assertSame(WfpPlan::STATUS_GENERATED, $plan->fresh()->status);

        // With both sign-offs the same action goes through.
        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);
        SchoolAllotment::create([
            'school_id' => $plan->school_id,
            'year' => $plan->year,
            'amount' => 50000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan->fresh()])
            ->call('submitForApproval')
            ->assertSet('errorMessage', null)
            ->assertSet('successMessage', fn ($value) => $value !== null);

        $this->assertSame(WfpPlan::STATUS_PENDING_APPROVAL, $plan->fresh()->status);
        $this->assertSame($sh->id, $plan->fresh()->submitted_by);
        $this->assertNotNull($plan->fresh()->submitted_at);
    }

    /* -------------------------------------------------- the decision flow */

    public function test_the_superadmin_approves_a_pending_plan_from_the_queue(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);
        $superadmin = $this->superadmin();

        Livewire::actingAs($superadmin)
            ->test(WfpApprovals::class)
            ->assertSee('Test School')
            ->assertSee('Pending Approval')
            ->call('openReview', $plan->id)
            ->assertSet('showReview', true)
            ->assertSee('Approve WFP')
            ->assertSee('wire:confirm', false)
            ->set('remarks', 'Certified and approved.')
            ->call('approve')
            ->assertSet('showReview', false)
            ->assertSet('successMessage', 'WFP approved and signed off.');

        $plan->refresh();

        $this->assertSame(WfpPlan::STATUS_FINALIZED, $plan->status);
        $this->assertSame($superadmin->id, $plan->approved_by);
        $this->assertNotNull($plan->approved_at);
        $this->assertSame($superadmin->name, $plan->finalApproverName());

        $approval = $plan->finalApproval;
        $this->assertNotNull($approval);
        $this->assertSame(WfpApproval::DECISION_APPROVED, $approval->decision);
        $this->assertSame('Certified and approved.', $approval->remarks);
        $this->assertSame($superadmin->id, $approval->decided_by);
    }

    public function test_a_second_decision_on_the_same_submission_is_refused(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);
        $superadmin = $this->superadmin();

        $plan->markApproved($superadmin, 'First decision.');

        $this->assertSame(1, WfpApproval::count());

        try {
            $plan->fresh()->markApproved($superadmin, 'Second decision.');
            $this->fail('A second approval should be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no longer pending', $e->getMessage());
        }

        // A manually crafted queue call is refused the same way.
        Livewire::actingAs($superadmin)->test(WfpApprovals::class)
            ->call('openReview', $plan->id)
            ->call('approve')
            ->assertSet('errorMessage', fn ($value) => $value !== null);

        $this->assertSame(1, WfpApproval::count());
        $this->assertSame(WfpPlan::STATUS_FINALIZED, $plan->fresh()->status);
    }

    public function test_only_a_superadmin_can_decide_and_only_on_a_pending_plan(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);
        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);

        // The School Head and the Budget reviewer hold no final authority.
        try {
            $plan->markApproved($sh);
            $this->fail('The School Head should not be able to approve the WFP.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Superadmin', $e->getMessage());
        }

        try {
            $plan->returnForRevision($budget, 'Not mine to decide.');
            $this->fail('The Budget reviewer should not be able to return the WFP.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Superadmin', $e->getMessage());
        }

        $this->assertSame(WfpPlan::STATUS_PENDING_APPROVAL, $plan->fresh()->status);
        $this->assertSame(0, WfpApproval::count());
    }

    public function test_a_plan_still_with_the_school_or_budget_cannot_be_decided(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->generatedPlan($sh);
        $superadmin = $this->superadmin();

        // A generated plan has not been submitted: it is not in the queue.
        try {
            $plan->markApproved($superadmin);
            $this->fail('A generated plan should not be approvable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no longer pending', $e->getMessage());
        }

        try {
            $plan->returnForRevision($superadmin, 'Nope.');
            $this->fail('A generated plan should not be returnable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no longer pending', $e->getMessage());
        }

        $this->assertSame(WfpPlan::STATUS_GENERATED, $plan->fresh()->status);
        $this->assertSame(0, WfpApproval::count());
    }

    public function test_a_deleted_approver_leaves_the_decision_history_but_no_signature(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);
        $superadmin = $this->superadmin();

        $plan->markApproved($superadmin);
        $superadmin->delete();

        $plan->refresh();

        // The approval stands and the audit row survives for the record —
        // but no name can be produced, so nothing may be printed.
        $this->assertSame(WfpPlan::STATUS_FINALIZED, $plan->status);
        $this->assertSame(1, $plan->approvals()->count());

        $approval = $plan->approvals()->firstOrFail();
        $this->assertSame(WfpApproval::DECISION_APPROVED, $approval->decision);
        $this->assertNull($approval->decided_by);
        $this->assertNull($plan->finalApproverName());
    }

    public function test_a_decision_is_refused_when_the_plan_is_no_longer_current(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);
        $superadmin = $this->superadmin();

        // The MEM moves on after the plan was submitted: the reviewed numbers
        // are stale, so the Superadmin may only return it.
        $matrix = MonthlyExpenditureMatrix::query()->firstOrFail();
        $matrix->items()->firstOrFail()->update(['quantity' => 20]);
        $matrix->bumpVersion();

        $component = Livewire::actingAs($superadmin)->test(WfpApprovals::class)
            ->call('openReview', $plan->id);

        $this->assertFalse($component->instance()->canApproveReview);
        $this->assertTrue($component->instance()->canDecideReview);

        $component->set('remarks', 'Stale numbers.')->call('approve')
            ->assertSet('errorMessage', fn ($value) => $value !== null);

        $this->assertSame(0, WfpApproval::count());
        $this->assertSame(WfpPlan::STATUS_PENDING_APPROVAL, $plan->fresh()->status);
    }

    /* ------------------------------------------------- returning for revision */

    public function test_returning_for_revision_requires_remarks_and_clears_budget_review(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);
        $superadmin = $this->superadmin();

        $component = Livewire::actingAs($superadmin)->test(WfpApprovals::class)
            ->call('openReview', $plan->id)
            ->call('returnForRevision')
            ->assertHasErrors('remarks');

        $this->assertSame(WfpPlan::STATUS_PENDING_APPROVAL, $plan->fresh()->status);

        $component
            ->set('remarks', 'Fix the October lines.')
            ->call('returnForRevision')
            ->assertHasNoErrors()
            ->assertSet('showReview', false);

        $plan->refresh();

        $this->assertSame(WfpPlan::STATUS_RETURNED, $plan->status);
        $this->assertNotNull($plan->latestReturn);
        $this->assertSame('Fix the October lines.', $plan->latestReturn->remarks);
        $this->assertSame($superadmin->id, $plan->latestReturn->decided_by);

        // Both Budget sign-offs are cleared: the lines are about to change.
        $this->assertSame(0, WfpReportCheck::count());
        $allotment = SchoolAllotment::query()->firstOrFail();
        $this->assertNull($allotment->certified_by);
        $this->assertNull($allotment->certified_at);

        // The SH cannot resubmit until Budget signs off again.
        try {
            $plan->fresh()->submitForApproval($sh);
            $this->fail('A returned plan should not resubmit without a fresh Budget review.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Budget', $e->getMessage());
        }

        $this->assertSame(WfpPlan::STATUS_RETURNED, $plan->fresh()->status);

        // Budget re-certifies and the SH resubmits; the history keeps both.
        $this->budgetReview($plan);
        $plan->fresh()->submitForApproval($sh);

        $this->assertSame(WfpPlan::STATUS_PENDING_APPROVAL, $plan->fresh()->status);
        $this->assertSame(1, $plan->approvals()->where('decision', WfpApproval::DECISION_RETURNED)->count());

        $plan->fresh()->markApproved($superadmin);
        $this->assertSame(2, $plan->approvals()->count());
    }

    /* ------------------------------------------------------------- read-only */

    public function test_pending_and_approved_plans_are_read_only(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);

        // The SH can open it, but save/regenerate are refused.
        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan])->call('save')->assertForbidden();
        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan])->call('regenerate')->assertForbidden();

        try {
            WfpGenerator::generate($plan);
            $this->fail('A pending plan must not be regenerated.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no longer editable', $e->getMessage());
        }

        $superadmin = $this->superadmin();
        $plan->fresh()->markApproved($superadmin);

        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan->fresh()])->call('save')->assertForbidden();
        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan->fresh()])->call('regenerate')->assertForbidden();
    }

    public function test_a_returned_plan_is_editable_again(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);
        $superadmin = $this->superadmin();

        $plan->returnForRevision($superadmin, 'Revise.');

        $this->assertTrue($plan->fresh()->isEditable());

        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan->fresh()])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Plan updated.');
    }

    /* ------------------------------------------------- approvals in the view */

    public function test_pending_and_returned_plans_show_no_approval_but_approved_plans_do(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);
        $superadmin = $this->superadmin();

        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan])
            ->assertSee('Pending Approval')
            ->assertSee('waiting for the Schools Division Superintendent')
            ->assertDontSee('Approved by');

        $plan->fresh()->returnForRevision($superadmin, 'Revise the supplies.');

        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan->fresh()])
            ->assertSee('Returned for revision')
            ->assertSee('Revise the supplies.')
            ->assertDontSee('Approved by');

        $this->budgetReview($plan);
        $plan->fresh()->submitForApproval($sh);
        $plan->fresh()->markApproved($superadmin, 'All good.');

        Livewire::actingAs($sh)->test(WfpPlanEditor::class, ['plan' => $plan->fresh()])
            ->assertSee('Approved by')
            ->assertSee($superadmin->name)
            ->assertSee('All good.')
            ->assertSee('no longer be edited');
    }

    /* ---------------------------------------------------------- the export */

    public function test_the_export_prints_the_recorded_approver_on_the_shifted_signature_block(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);
        $superadmin = $this->superadmin('SDS Grace Villanueva');

        $name = $this->useSyntheticTemplate(withApprovalBlock: true);

        try {
            // Before the decision the signature cell stays blank.
            $pending = $this->actingAs($sh)->get(route('wfp.plans.export', ['plan' => $plan->id]));
            $pending->assertOk();

            $pendingSheet = $this->wfpSheetXml((string) $pending->getContent());
            $labelRow = $this->approvalLabelRow($pendingSheet);

            $this->assertSame('', $this->wfpCellText($pendingSheet, 'A'.($labelRow + 3)));
            $this->assertStringNotContainsString($superadmin->name, $pendingSheet);

            $plan->fresh()->markApproved($superadmin);

            $response = $this->actingAs($sh)->get(route('wfp.plans.export', ['plan' => $plan->id]));
            $response->assertOk();

            $sheet = $this->wfpSheetXml((string) $response->getContent());
            $labelRow = $this->approvalLabelRow($sheet);

            // The filler trimmed two blank data rows, so the block moved up.
            // The stamp must follow it, not the template's original row.
            $this->assertSame($superadmin->name, $this->wfpCellText($sheet, 'A'.($labelRow + 3)));
        } finally {
            @unlink(storage_path('forms/'.$name));
        }
    }

    public function test_the_export_prints_no_signature_without_a_recorded_approval(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);

        $name = $this->useSyntheticTemplate(withApprovalBlock: true);

        try {
            // A legacy finalization — finalized before approval records were
            // kept — must not print the School Head as the SDS.
            $plan->forceFill([
                'status' => WfpPlan::STATUS_FINALIZED,
                'approved_by' => $sh->id,
                'approved_at' => now(),
            ])->save();

            $this->assertNull($plan->fresh()->finalApproverName());

            $response = $this->actingAs($sh)->get(route('wfp.plans.export', ['plan' => $plan->id]));
            $response->assertOk();

            $sheet = $this->wfpSheetXml((string) $response->getContent());
            $labelRow = $this->approvalLabelRow($sheet);

            $this->assertSame('', $this->wfpCellText($sheet, 'A'.($labelRow + 3)));
            $this->assertStringNotContainsString($sh->name, $sheet);
        } finally {
            @unlink(storage_path('forms/'.$name));
        }
    }

    public function test_approval_cells_need_both_the_name_and_the_signature_block(): void
    {
        $withBlock = $this->wfpTemplateBytes(3, null, true);
        $withoutBlock = $this->wfpTemplateBytes(3);

        $this->assertSame(['A12' => 'SDS Grace'], WfpTemplateFiller::approvalCells($withBlock, 'SDS Grace'));
        $this->assertSame([], WfpTemplateFiller::approvalCells($withBlock, null));
        $this->assertSame([], WfpTemplateFiller::approvalCells($withBlock, '   '));
        $this->assertSame([], WfpTemplateFiller::approvalCells($withoutBlock, 'SDS Grace'));
    }

    /* ------------------------------------------------------ Superadmin view */

    public function test_the_superadmin_can_preview_a_plan_but_never_write_it(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->pendingPlan($sh);
        $superadmin = $this->superadmin();

        $name = $this->useSyntheticTemplate();

        try {
            $this->actingAs($superadmin)
                ->get(route('wfp.plans.edit', ['plan' => $plan->id]))
                ->assertOk();

            $component = Livewire::actingAs($superadmin)->test(WfpPlanEditor::class, ['plan' => $plan]);
            $component->assertOk();
            $this->assertFalse($component->instance()->canEdit());

            // Every write action keeps the SH-only gate.
            Livewire::actingAs($superadmin)->test(WfpPlanEditor::class, ['plan' => $plan])
                ->call('save')->assertNotFound();
            Livewire::actingAs($superadmin)->test(WfpPlanEditor::class, ['plan' => $plan])
                ->call('regenerate')->assertNotFound();
            Livewire::actingAs($superadmin)->test(WfpPlanEditor::class, ['plan' => $plan])
                ->call('submitForApproval')->assertNotFound();

            $this->actingAs($superadmin)
                ->get(route('wfp.plans.export', ['plan' => $plan->id]))
                ->assertOk();
        } finally {
            @unlink(storage_path('forms/'.$name));
        }
    }

    /* ------------------------------------------------------------ filtering */

    public function test_the_queue_filters_by_school_level_year_status_and_search(): void
    {
        $alpha = $this->schoolHead('Alpha Elementary School');
        $beta = $this->schoolHead('Beta High School');
        $superadmin = $this->superadmin();

        $pending = $this->pendingPlan($alpha);
        $approved = $this->pendingPlan($beta);
        $approved->markApproved($superadmin);

        $component = Livewire::actingAs($superadmin)->test(WfpApprovals::class);

        // By default only pending work is listed.
        $this->assertSame([$pending->school_id], $component->instance()->plans->pluck('school_id')->all());

        $component->set('statusFilter', WfpPlan::STATUS_FINALIZED);
        $this->assertSame([$approved->school_id], $component->instance()->plans->pluck('school_id')->all());

        $component->set('search', 'Alpha');
        $this->assertSame([], $component->instance()->plans->pluck('school_id')->all());

        $component->set('statusFilter', 'all')->set('search', 'Alpha');
        $this->assertSame([$pending->school_id], $component->instance()->plans->pluck('school_id')->all());

        $component->set('search', '')->set('divisionFilter', SchoolDivision::ELEMENTARY)->set('yearFilter', '2026');
        $this->assertSame(
            [$pending->school_id, $approved->school_id],
            $component->instance()->plans->pluck('school_id')->all()
        );

        $component->set('schoolFilter', (string) $beta->school_id);
        $this->assertSame([$approved->school_id], $component->instance()->plans->pluck('school_id')->all());
    }
}
