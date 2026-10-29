<?php

namespace Tests\Feature;

use App\Livewire\MemEditor;
use App\Livewire\WfpLineTemplatesTable;
use App\Models\AccountClassification;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Models\WfpFundingSource;
use App\Models\WfpLineTemplate;
use App\Models\WfpOrgClassification;
use App\Models\WfpResourceKind;
use App\Models\WfpUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Activity Template workflow: the administrator's reusable combinations
 * and the Add a Line auto-fill they drive.
 */
class WfpActivityTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function schoolHead(): User
    {
        $district = District::create(['name' => 'Template District']);
        $school = School::create(['district_id' => $district->id, 'name' => 'Template School']);

        return User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);
    }

    private function administrator(): User
    {
        return User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
    }

    private function matrixFor(User $sh): MonthlyExpenditureMatrix
    {
        return MonthlyExpenditureMatrix::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'month' => 7,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $sh->id,
        ]);
    }

    private function account(string $title = 'Office Supplies Expenses', string $code = '5020301000'): AccountClassification
    {
        return AccountClassification::create([
            'account_title' => $title,
            'rca_code' => 'RCA-'.$code,
            'uacs_sub_object_code' => '5'.$code,
            'uacs_object_code' => $code,
            'is_active' => true,
        ]);
    }

    /**
     * A fully loaded Operations template: every standardized field plus the
     * optional lookup defaults.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function template(array $overrides = []): WfpLineTemplate
    {
        return WfpLineTemplate::create(array_merge([
            'name' => 'Procure office supplies',
            'line_type' => WfpLineTemplate::LINE_TYPE_OPERATIONS,
            'objective' => 'Support school operations',
            'activity_expenditure' => 'Procure office supplies',
            'account_classification_id' => $this->account()->id,
            'funding_source_id' => WfpFundingSource::create(['code' => 'MOOE', 'name' => 'MOOE Fund'])->id,
            'org_classification_id' => WfpOrgClassification::create(['code' => 'SCH', 'name' => 'School'])->id,
            'resource_kind_id' => WfpResourceKind::create(['code' => 'SUP', 'name' => 'School Supplies'])->id,
            'unit_id' => WfpUnit::create(['code' => 'REAM', 'name' => 'Ream'])->id,
            'suggested_quantity' => 5,
            'suggested_unit_cost' => 250,
            'major_output' => 'Supplies delivered',
            'performance_indicator' => '100% of requests served',
            'activity_movs' => 'PO, RIS',
            'is_active' => true,
        ], $overrides));
    }

    // ------------------------------------------------------------- auto-fill

    public function test_selecting_a_template_fills_every_standardized_field(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);
        $template = $this->template();
        $account = $template->accountClassification;
        $kind = $template->resourceKind;
        $unit = $template->unit;
        $funding = $template->fundingSource;
        $org = $template->orgClassification;

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'operations')
            ->call('chooseTemplate', $template->id)
            ->assertSet('appliedTemplateId', $template->id)
            ->assertSet('lineForm.objective', 'Support school operations')
            ->assertSet('lineForm.activity_description', 'Procure office supplies')
            ->assertSet('lineForm.account_title', $account->account_title)
            ->assertSet('lineForm.account_code', $account->uacs_object_code)
            ->assertSet('lineForm.major_output', 'Supplies delivered')
            ->assertSet('lineForm.performance_indicator', '100% of requests served')
            ->assertSet('lineForm.activity_movs', 'PO, RIS')
            ->assertSet('lineForm.resource_kind', $kind->name)
            ->assertSet('lineForm.unit', $unit->name)
            ->assertSet('lineForm.funding_source', $funding->name)
            ->assertSet('lineForm.organizational_classification', $org->name);

        // The variable values are suggestions the School Head confirms:
        // quantity and unit cost stay editable and the total follows.
        $component->set('lineForm.quantity', '2')
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('showLineModal', false);

        $item = MonthlyExpenditureItem::firstOrFail();

        $this->assertSame($template->id, $item->wfp_line_template_id);
        $this->assertSame('Procure office supplies', $item->activity_description);
        $this->assertSame('Support school operations', $item->objective);
        $this->assertSame($account->account_title, $item->account_title);
        $this->assertSame($account->uacs_object_code, $item->account_code);
        $this->assertSame($account->id, $item->account_classification_id);
        $this->assertSame('Supplies delivered', $item->major_output);
        $this->assertSame('100% of requests served', $item->performance_indicator);
        $this->assertSame('PO, RIS', $item->activity_movs);
        $this->assertSame($kind->id, $item->resource_kind_id);
        $this->assertSame($unit->id, $item->unit_id);
        $this->assertSame($funding->id, $item->funding_source_id);
        $this->assertSame($org->id, $item->org_classification_id);
        $this->assertEquals(2.0, (float) $item->quantity);
        $this->assertEquals(250.0, (float) $item->unit_cost);
        $this->assertEquals(500.0, (float) $item->calculated_total);
    }

    public function test_the_picker_offers_only_the_selected_expenditure_types_templates(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);
        $operations = $this->template(['name' => 'Ops Template Alpha']);
        $project = $this->template(['name' => 'Project Template Beta', 'line_type' => WfpLineTemplate::LINE_TYPE_PROJECT]);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'operations')
            ->assertSee('Ops Template Alpha')
            ->assertDontSee('Project Template Beta');

        // A crafted call for the other type is refused, not silently applied.
        $component->call('chooseTemplate', $project->id)
            ->assertHasErrors(['templateSearch'])
            ->assertSet('appliedTemplateId', null);

        $component->set('lineSection', 'project')
            ->assertSee('Project Template Beta')
            ->assertDontSee('Ops Template Alpha')
            ->call('chooseTemplate', $project->id)
            ->assertSet('appliedTemplateId', $project->id);
    }

    public function test_the_template_search_matches_name_objective_and_account_code(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);
        $template = $this->template([
            'name' => 'Reading camp materials',
            'objective' => 'Improve reading proficiency',
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'operations')
            ->set('templateSearch', 'proficiency')
            ->assertSee('Reading camp materials')
            ->set('templateSearch', $template->accountClassification->uacs_object_code)
            ->assertSee('Reading camp materials');
    }

    public function test_an_inactive_template_is_not_offered_or_applied(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);
        $retired = $this->template(['name' => 'Retired Template', 'is_active' => false]);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'operations')
            ->assertDontSee('Retired Template');

        // Whether the server answers the crafted id with a 404 or simply
        // refuses, the retired template must never be applied.
        try {
            $component->call('chooseTemplate', $retired->id);
        } catch (\Throwable) {
            // Refused outright — equally acceptable.
        }

        $component->assertSet('appliedTemplateId', null)
            ->assertSet('appliedTemplateName', '');
    }

    public function test_switching_templates_warns_before_replacing_edited_fields(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);
        $first = $this->template(['name' => 'First Template', 'objective' => 'First objective', 'activity_expenditure' => 'First activity']);
        $second = $this->template(['name' => 'Second Template', 'objective' => 'Second objective', 'activity_expenditure' => 'Second activity']);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'operations')
            ->call('chooseTemplate', $first->id)
            ->assertSet('appliedTemplateId', $first->id)
            // A template-owned field is edited by hand…
            ->set('lineForm.major_output', 'Manually written output')
            // …so the next template is not applied without asking.
            ->call('chooseTemplate', $second->id)
            ->assertSet('pendingTemplateId', $second->id)
            ->assertSet('appliedTemplateId', $first->id);

        // "Keep edits": untouched template fields follow the new template,
        // the hand-written field survives.
        $component->call('keepTemplateEdits')
            ->assertSet('pendingTemplateId', null)
            ->assertSet('appliedTemplateId', $second->id)
            ->assertSet('lineForm.objective', 'Second objective')
            ->assertSet('lineForm.activity_description', 'Second activity')
            ->assertSet('lineForm.major_output', 'Manually written output');
    }

    public function test_confirming_replace_overwrites_the_edited_fields(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);
        $first = $this->template(['name' => 'First Template', 'major_output' => 'First output']);
        $second = $this->template(['name' => 'Second Template', 'major_output' => 'Second output']);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'operations')
            ->call('chooseTemplate', $first->id)
            ->set('lineForm.major_output', 'Manually written output')
            ->call('chooseTemplate', $second->id)
            ->assertSet('pendingTemplateId', $second->id)
            ->call('confirmTemplateReplace')
            ->assertSet('pendingTemplateId', null)
            ->assertSet('lineForm.major_output', 'Second output');
    }

    // ----------------------------------------------------- integrity checks

    public function test_switching_sections_drops_the_template_link_but_keeps_its_values(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);
        $template = $this->template();

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'operations')
            ->call('chooseTemplate', $template->id)
            ->set('lineSection', 'project')
            ->assertSet('appliedTemplateId', null)
            ->assertSet('appliedTemplateName', '')
            ->assertSet('appliedTemplateSnapshot', [])
            // The wording the user already reviewed is not thrown away.
            ->assertSet('lineForm.objective', 'Support school operations')
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $item = MonthlyExpenditureItem::firstOrFail();

        $this->assertSame('project', $item->section);
        $this->assertNull($item->wfp_line_template_id);
    }

    public function test_a_crafted_account_title_and_code_cannot_be_mismatched(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);
        $real = $this->account('Office Supplies Expenses', '5020301000');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'operations')
            ->set('lineForm.activity_description', 'Procure supplies')
            // The request claims a different title and code than the chosen
            // classification; the server must re-derive both from the record.
            ->set('lineForm.account_classification_id', $real->id)
            ->set('lineForm.account_title', 'Totally Different Title')
            ->set('lineForm.account_code', '9999999999')
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $item = MonthlyExpenditureItem::firstOrFail();

        $this->assertSame($real->id, $item->account_classification_id);
        $this->assertSame($real->account_title, $item->account_title);
        $this->assertSame($real->uacs_object_code, $item->account_code);
    }

    public function test_a_typed_lookup_name_links_to_the_active_lookup_only(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);
        $kind = WfpResourceKind::create(['code' => 'SUP', 'name' => 'School Supplies']);
        $retired = WfpResourceKind::create(['code' => 'RET', 'name' => 'Retired Kind', 'is_active' => false]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'operations')
            ->set('lineForm.activity_description', 'Procure supplies')
            ->set('lineForm.resource_kind', 'school supplies')
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $item = MonthlyExpenditureItem::firstOrFail();

        // Matched case-insensitively to the active record and stored with the
        // master's exact wording.
        $this->assertSame($kind->id, $item->resource_kind_id);
        $this->assertSame('School Supplies', $item->resource_kind);

        // A retired record can never be newly attached: the text survives,
        // the link does not. (Refresh the instance: the first save advanced
        // the matrix version and the editor's optimistic lock reads the DB.)
        $matrix->refresh();

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'operations')
            ->set('lineForm.activity_description', 'Procure other things')
            ->set('lineForm.resource_kind', 'Retired Kind')
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $second = MonthlyExpenditureItem::query()->orderByDesc('id')->firstOrFail();

        $this->assertNull($second->resource_kind_id);
        $this->assertSame('Retired Kind', $second->resource_kind);
        $this->assertFalse($retired->is_active);
    }

    public function test_editing_a_line_keeps_its_inactive_template_provenance(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);
        $template = $this->template(['is_active' => false]);

        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
            'wfp_line_template_id' => $template->id,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openEditModal', 'item-'.$item->id)
            ->assertSet('appliedTemplateId', $template->id)
            ->assertSet('appliedTemplateName', $template->name)
            ->set('lineForm.major_output', 'Supplies delivered')
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $fresh = $item->fresh();

        $this->assertSame($template->id, $fresh->wfp_line_template_id);
        $this->assertSame('Supplies delivered', $fresh->major_output);
    }

    // ------------------------------------------------------------- administrator

    public function test_the_administrator_can_duplicate_a_template(): void
    {
        $admin = $this->administrator();
        $template = $this->template(['name' => 'Procure office supplies']);

        Livewire::actingAs($admin)->test(WfpLineTemplatesTable::class)
            ->call('duplicate', $template->id)
            ->assertHasNoErrors();

        $copy = WfpLineTemplate::query()->where('name', 'Procure office supplies (Copy)')->firstOrFail();

        $this->assertSame($template->line_type, $copy->line_type);
        $this->assertSame($template->account_classification_id, $copy->account_classification_id);
        $this->assertSame($template->activity_expenditure, $copy->activity_expenditure);
        $this->assertSame($template->funding_source_id, $copy->funding_source_id);
        $this->assertEquals($template->suggested_unit_cost, $copy->suggested_unit_cost);
        $this->assertSame(2, WfpLineTemplate::count());

        // Duplicating again never collides with the first copy's name.
        Livewire::actingAs($admin)->test(WfpLineTemplatesTable::class)->call('duplicate', $template->id);

        $this->assertDatabaseHas('wfp_line_templates', ['name' => 'Procure office supplies (Copy 2)']);
    }

    public function test_the_administrator_can_save_a_template_that_references_lookup_records(): void
    {
        $admin = $this->administrator();
        $kind = WfpResourceKind::create(['code' => 'SUP', 'name' => 'School Supplies']);
        $unit = WfpUnit::create(['code' => 'REAM', 'name' => 'Ream']);

        Livewire::actingAs($admin)->test(WfpLineTemplatesTable::class)
            ->call('openCreate')
            ->set('name', 'Procure supplies')
            ->set('activityExpenditure', 'Procure supplies')
            ->set('resourceKindId', $kind->id)
            ->set('unitId', $unit->id)
            ->call('save')
            ->assertHasNoErrors();

        $template = WfpLineTemplate::query()->where('name', 'Procure supplies')->firstOrFail();

        $this->assertSame($kind->id, $template->resource_kind_id);
        $this->assertSame($unit->id, $template->unit_id);
    }

    public function test_the_template_list_shows_the_linked_account_code(): void
    {
        $admin = $this->administrator();
        $this->template();

        Livewire::actingAs($admin)->test(WfpLineTemplatesTable::class)
            ->assertSee('Office Supplies Expenses')
            ->assertSee('5020301000');
    }

    public function test_the_template_list_renders_a_template_without_a_resource_kind_or_unit(): void
    {
        $admin = $this->administrator();
        $this->template([
            'name' => 'Template with no resource details',
            'resource_kind_id' => null,
            'unit_id' => null,
        ]);

        Livewire::actingAs($admin)->test(WfpLineTemplatesTable::class)
            ->assertOk()
            ->assertSee('Template with no resource details');
    }

    public function test_only_the_administrator_can_duplicate_a_template(): void
    {
        $template = $this->template();

        $html = Livewire::actingAs($this->schoolHead())
            ->test(WfpLineTemplatesTable::class)
            ->html();

        $this->assertStringContainsString('Page Not Found', $html);
        $this->assertSame(1, WfpLineTemplate::count());
    }
}
