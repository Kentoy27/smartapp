<?php

namespace Tests\Feature;

use App\Livewire\MemEditor;
use App\Livewire\MemMatrixList;
use App\Models\AccountClassification;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Support\PurePhpZipReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class MemMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function schoolHead(string $name = 'Test School'): User
    {
        $district = District::create(['name' => 'District '.$name]);
        $school = School::create(['district_id' => $district->id, 'name' => $name]);

        return User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => $school->id]);
    }

    private function matrixFor(User $sh, int $month = 7, int $year = 2026): MonthlyExpenditureMatrix
    {
        return MonthlyExpenditureMatrix::create([
            'school_id' => $sh->school_id,
            'year' => $year,
            'month' => $month,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $sh->id,
        ]);
    }

    // ---------------------------------------------------------------- access

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('wfp.matrices.index'))->assertRedirect(route('login'));
    }

    public function test_the_school_head_can_open_the_page(): void
    {
        $this->actingAs($this->schoolHead())
            ->get(route('wfp.matrices.index'))
            ->assertOk()
            ->assertSeeLivewire('mem-matrix-list');
    }

    public function test_non_school_head_roles_get_a_404(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $administrator = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
        $superadmin = User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);

        foreach ([$viewer, $administrator, $superadmin] as $user) {
            $this->actingAs($user)->get(route('wfp.matrices.index'))->assertNotFound();
        }
    }

    public function test_an_school_head_of_another_school_cannot_open_a_matrix(): void
    {
        $owner = $this->schoolHead('School A');
        $other = $this->schoolHead('School B');
        $matrix = $this->matrixFor($owner);

        $this->actingAs($other)
            ->get(route('wfp.matrices.edit', ['matrix' => $matrix->id]))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('wfp.matrices.edit', ['matrix' => $matrix->id]))
            ->assertOk();
    }

    public function test_the_component_refuses_a_guest_and_a_non_school_head(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);

        $this->assertStringContainsString('Page Not Found', Livewire::test(MemMatrixList::class)->html());
        $this->assertStringContainsString('Page Not Found', Livewire::actingAs($viewer)->test(MemMatrixList::class)->html());
    }

    public function test_template_download_returns_a_csv_for_the_school_head(): void
    {
        $this->actingAs($this->schoolHead())
            ->get(route('wfp.matrices.template'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertSee('Section,Objective,Activity Description', false);
    }

    // ---------------------------------------------------------------- create

    public function test_the_school_head_can_create_a_matrix(): void
    {
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->call('openCreate')
            ->set('createYear', '2026')
            ->set('createMonth', '7')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('monthly_expenditure_matrices', [
            'school_id' => $sh->school_id,
            'year' => 2026,
            'month' => 7,
            'status' => 'draft',
        ]);
    }

    public function test_a_duplicate_month_is_refused(): void
    {
        $sh = $this->schoolHead();
        $this->matrixFor($sh, month: 7, year: 2026);

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->call('openCreate')
            ->set('createYear', '2026')
            ->set('createMonth', '7')
            ->call('create')
            ->assertHasErrors('createMonth');

        $this->assertSame(1, MonthlyExpenditureMatrix::count());
    }

    public function test_a_school_head_with_no_school_cannot_create(): void
    {
        $sh = User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => null]);

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->call('openCreate')
            ->assertSet('createError', fn ($value) => $value !== null)
            ->call('create');

        $this->assertSame(0, MonthlyExpenditureMatrix::count());
    }

    // ------------------------------------------------------------------ edit

    public function test_the_grid_saves_lines_and_recomputes_the_total(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('addRow')
            ->set('items.0.activity_description', 'Procure supplies')
            ->set('items.0.quantity', '10')
            ->set('items.0.unit_cost', '250')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('monthly_expenditure_items', [
            'matrix_id' => $matrix->id,
            'activity_description' => 'Procure supplies',
        ]);

        $this->assertEquals(2500.0, (float) MonthlyExpenditureItem::firstOrFail()->calculated_total);
        // The version advanced so a generated WFP now reads out of date.
        $this->assertSame(2, (int) $matrix->fresh()->version);
    }

    public function test_saving_requires_an_activity_description(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        // The line is non-blank (it has a quantity), but has no activity.
        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('addRow')
            ->set('items.0.quantity', '5')
            ->set('items.0.activity_description', '')
            ->call('saveDraft')
            ->assertHasErrors(['items.0.activity_description']);

        $this->assertSame(0, MonthlyExpenditureItem::count());
    }

    public function test_adding_a_line_through_the_modal_encodes_it_in_one_place(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'project')
            ->assertSet('showLineModal', true)
            ->assertSet('lineSection', 'project')
            ->set('lineForm.activity_description', 'Procure supplies')
            ->set('lineForm.quantity', '10')
            ->set('lineForm.unit_cost', '250')
            ->call('saveLineFromModal')
            ->assertSet('showLineModal', false)
            ->assertHasNoErrors();

        // The modal closed and the filled row joined the grid, in the section
        // the modal was opened for — still unsaved until Save Draft.
        $items = $component->get('items');

        $this->assertCount(1, $items);
        $this->assertSame('project', $items[0]['section']);
        $this->assertSame('Procure supplies', $items[0]['activity_description']);
        $this->assertSame(0, MonthlyExpenditureItem::count());

        $component->call('saveDraft')->assertHasNoErrors();

        $this->assertDatabaseHas('monthly_expenditure_items', [
            'matrix_id' => $matrix->id,
            'section' => 'project',
            'activity_description' => 'Procure supplies',
        ]);
        $this->assertEquals(2500.0, (float) MonthlyExpenditureItem::firstOrFail()->calculated_total);
    }

    public function test_the_add_line_modal_requires_an_activity_description(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.quantity', '5')
            ->call('saveLineFromModal')
            ->assertHasErrors(['lineForm.activity_description'])
            ->assertSet('showLineModal', true);
    }

    public function test_the_add_line_modal_starts_clean_for_each_section(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'project')
            ->set('lineForm.activity_description', 'Draft line')
            ->call('closeLineModal')
            ->assertSet('showLineModal', false)
            // Reopening forgets the closed draft rather than resurrecting it.
            ->call('openLineModal', 'operations')
            ->assertSet('lineSection', 'operations')
            ->assertSet('lineForm.activity_description', '');
    }

    public function test_an_existing_line_is_edited_through_the_same_modal(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('addRow')
            ->set('items.0.activity_description', 'Old description')
            ->set('items.0.quantity', '1')
            ->set('items.0.unit_cost', '10');

        $key = $component->get('items.0._key');

        $component->call('openEditModal', $key)
            ->assertSet('showLineModal', true)
            ->assertSet('editingKey', $key)
            // The modal opens pre-filled with the line's current values.
            ->assertSet('lineForm.activity_description', 'Old description')
            ->set('lineForm.activity_description', 'New description')
            ->set('lineForm.quantity', '4')
            ->set('lineForm.unit_cost', '25')
            ->call('saveLineFromModal')
            ->assertSet('showLineModal', false)
            ->assertSet('editingKey', null)
            // Edited in place: still one line, not a new one appended.
            ->assertCount('items', 1)
            ->assertSet('items.0.activity_description', 'New description')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame(1, MonthlyExpenditureItem::count());

        $item = MonthlyExpenditureItem::firstOrFail();

        $this->assertSame('New description', $item->activity_description);
        $this->assertEquals(100.0, (float) $item->calculated_total);
    }

    public function test_the_account_picker_fills_the_modal_form(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $classification = AccountClassification::create([
            'account_title' => 'Office Supplies Expenses',
            'rca_code' => '3020301',
            'uacs_sub_object_code' => '5020301',
            'uacs_object_code' => '5020301000',
            'is_active' => true,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->call('openAccountPicker', 'form')
            ->call('chooseAccount', $classification->id)
            ->assertSet('lineForm.account_title', 'Office Supplies Expenses')
            ->assertSet('lineForm.account_code', '5020301000')
            ->assertSet('lineForm.account_classification_id', $classification->id);
    }

    public function test_duplicating_a_line_copies_it_as_a_fresh_unsaved_row(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('addRow')
            ->set('items.0.activity_description', 'Procure supplies')
            ->set('items.0.section', 'project')
            ->set('items.0.quantity', '2')
            ->set('items.0.unit_cost', '50');

        $key = $component->get('items.0._key');

        $component->call('duplicateRow', $key)
            ->assertCount('items', 2)
            ->assertSet('items.1.section', 'project')
            ->assertSet('items.1.activity_description', 'Procure supplies')
            ->assertSet('items.1.id', null);

        $component->call('saveDraft')->assertHasNoErrors();

        $this->assertSame(2, MonthlyExpenditureItem::count());
    }

    public function test_moving_a_line_flips_its_section(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('addRow');

        $key = $component->get('items.0._key');

        $component->call('moveRow', $key)
            ->assertSet('items.0.section', 'project');

        // The grid groups by section but binds to the original index, so a
        // moved line still writes to the same field.
        $component->set('items.0.activity_description', 'Build a fence')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $item = MonthlyExpenditureItem::firstOrFail();

        $this->assertSame('project', $item->section);
        $this->assertSame('Build a fence', $item->activity_description);
    }

    public function test_the_grid_renders_one_grouped_block_per_section(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('addRow')
            ->call('addRow', 'project')
            ->call('addRow')
            ->assertSee('Operations')
            ->assertSee('Project')
            ->assertSeeInOrder(['Operations', 'Project'])
            // Each block carries its own add button, so the section a line
            // lands in is chosen where it is added, not by a dropdown.
            ->assertSee('Add operations line')
            ->assertSee('Add project line');
    }

    public function test_an_empty_matrix_shows_the_starting_state_not_an_empty_table(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->assertSee('No expenditure lines yet')
            ->assertSee('Add the first line')
            ->assertDontSee('Operations subtotal');
    }

    public function test_validate_and_finalize_set_the_status(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('addRow')
            ->set('items.0.activity_description', 'Line one')
            ->call('validateMatrix');

        $this->assertSame('validated', $matrix->fresh()->status);

        $component->call('finalizeMatrix');
        $this->assertSame('finalized', $matrix->fresh()->status);
    }

    public function test_a_stale_grid_is_refused_by_the_optimistic_lock(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('addRow')
            ->set('items.0.activity_description', 'Line one');

        // Somebody else writes the matrix in the meantime.
        $matrix->bumpVersion();

        $component->call('saveDraft')
            ->assertSet('errorMessage', fn ($value) => $value !== null);

        $this->assertSame(0, MonthlyExpenditureItem::count());
    }

    public function test_the_editor_refuses_another_schools_matrix(): void
    {
        $owner = $this->schoolHead('School A');
        $other = $this->schoolHead('School B');
        $matrix = $this->matrixFor($owner);

        $this->assertStringContainsString(
            'Page Not Found',
            Livewire::actingAs($other)->test(MemEditor::class, ['matrix' => $matrix])->html()
        );
    }

    // ---------------------------------------------------------------- import

    public function test_import_previews_then_commits_valid_rows(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh, month: 5);

        $csv = implode("\n", [
            'Section,Activity Description,Quantity,Unit,Unit Cost',
            'operations,Procure supplies,10,ream,250',
            'project,Build a fence,5,metre,100',
        ]);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openImport')
            ->set('importFile', UploadedFile::fake()->createWithContent('mem.csv', $csv))
            ->assertSet('importValidCount', 2)
            ->assertSet('importInvalidCount', 0);

        $this->assertSame(0, MonthlyExpenditureItem::count());

        $component->call('commitImport')->assertSet('showImport', false);

        $this->assertSame(2, MonthlyExpenditureItem::count());
        $this->assertEquals(2500.0, (float) MonthlyExpenditureItem::orderBy('id')->first()->calculated_total);
    }

    // ---------------------------------------------------------------- export

    public function test_the_matrix_exports_as_the_printed_dep_ed_form(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'objective' => 'Improved learner outcomes',
            'activity_description' => 'Procure office supplies',
            'resource_kind' => 'MOOE',
            'quantity' => 10,
            'unit' => 'ream',
            'unit_cost' => 250,
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
        ]);

        $response = $this->actingAs($sh)
            ->get(route('wfp.matrices.export', ['matrix' => $matrix->id]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('monthly-expenditure-matrix-2026-07.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'mem-export-').'.xlsx';
        file_put_contents($path, $response->getContent());

        $zip = new PurePhpZipReader($path);
        $sheet = $zip->part('xl/worksheets/sheet1.xml');

        $this->assertNotNull($sheet);
        $this->assertNotNull($zip->part('xl/styles.xml'));

        // Every part must be WELL-FORMED XML: Excel repairs (and mangles)
        // a file whose styles part is malformed, which a zip-level check
        // alone would never catch.
        foreach ($zip->readAll() as $name => $contents) {
            $this->assertNotFalse(
                simplexml_load_string($contents),
                $name.' is not well-formed XML'
            );
        }

        // The form's own headings and both section banners.
        $this->assertStringContainsString('2026 MONTHLY EXPENDITURE MATRIX', $sheet);
        $this->assertStringContainsString('MONTH: JULY 2026', $sheet);
        $this->assertStringContainsString('Operations-Related Expenses', $sheet);
        $this->assertStringContainsString('Project-Related Expenses', $sheet);

        // The line itself, and the account it is charged to.
        $this->assertStringContainsString('Procure office supplies', $sheet);
        $this->assertStringContainsString('Office Supplies Expenses', $sheet);

        // 10 × 250, written as a real NUMBER (not text) the reviewer can sum.
        $this->assertStringContainsString('<v>2500</v>', $sheet);
        $this->assertStringContainsString('Subtotal', $sheet);
        $this->assertStringContainsString('TOTAL', $sheet);

        // The signature block names the exporter as the prepared-by.
        $this->assertStringContainsString($sh->name, $sheet);

        unlink($path);
    }

    public function test_another_school_cannot_export_the_matrix(): void
    {
        $owner = $this->schoolHead('School A');
        $other = $this->schoolHead('School B');
        $matrix = $this->matrixFor($owner);

        $this->actingAs($other)
            ->get(route('wfp.matrices.export', ['matrix' => $matrix->id]))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('wfp.matrices.export', ['matrix' => $matrix->id]))
            ->assertOk();
    }

    public function test_the_list_can_export_without_opening_the_matrix(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->call('export', $matrix->id)
            ->assertRedirect(route('wfp.matrices.export', ['matrix' => $matrix->id]));
    }

    // ---------------------------------------------------------------- delete

    public function test_deleting_a_matrix_removes_it_and_its_lines(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Line',
        ]);

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->call('openConfirmDelete', $matrix->id)
            ->call('confirmDelete');

        $this->assertDatabaseMissing('monthly_expenditure_matrices', ['id' => $matrix->id]);
        $this->assertSame(0, MonthlyExpenditureItem::count());
    }
}
