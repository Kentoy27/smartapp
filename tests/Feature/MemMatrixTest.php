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
use App\Models\WfpPlan;
use App\Support\PurePhpZipReader;
use App\Support\WfpGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\BuildsMemWorkbooks;
use Tests\TestCase;

class MemMatrixTest extends TestCase
{
    use BuildsMemWorkbooks;
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

    public function test_clicking_a_row_opens_its_matrix(): void
    {
        // The row itself is clickable (not just the ⋯ menu's "Open"): the
        // component action the row fires redirects to the matrix editor.
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->call('open', $matrix->id)
            ->assertRedirect(route('wfp.matrices.edit', ['matrix' => $matrix->id]));
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

    // ----------------------------------------------------------- year init

    public function test_the_year_filter_defaults_to_the_current_calendar_year(): void
    {
        Livewire::actingAs($this->schoolHead())->test(MemMatrixList::class)
            ->assertSet('year', (string) now()->year);
    }

    public function test_the_selected_year_is_restored_from_the_query_string(): void
    {
        // The year lives in the URL, so a refresh keeps the SH where they
        // were rather than snapping back to the current year.
        Livewire::actingAs($this->schoolHead())
            ->withQueryParams(['year' => '2027'])
            ->test(MemMatrixList::class)
            ->assertSet('year', '2027');
    }

    public function test_the_page_defaults_to_the_latest_planning_year_the_school_has(): void
    {
        // With no year in the URL (e.g. arriving from a sidebar link), the page
        // opens on the newest year the school has — so a year the SH added is
        // not lost by simply switching tabs.
        $sh = $this->schoolHead();
        $this->matrixFor($sh, month: 1, year: 2026);
        $this->matrixFor($sh, month: 1, year: 2027);

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->assertSet('year', '2027');
    }

    public function test_the_default_year_is_never_older_than_the_current_calendar_year(): void
    {
        // A school that only has past data still lands on the current year.
        $sh = $this->schoolHead();
        $this->matrixFor($sh, month: 1, year: 2024);

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->assertSet('year', (string) now()->year);
    }

    public function test_initializing_a_year_creates_its_twelve_months(): void
    {
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('year', '2026')
            ->call('generateYear')
            ->assertHasNoErrors();

        $months = MonthlyExpenditureMatrix::where('school_id', $sh->school_id)
            ->where('year', 2026)
            ->orderBy('month')
            ->pluck('month')
            ->all();

        $this->assertSame(range(1, 12), $months);

        // New months start as blank Drafts with zero lines.
        $this->assertSame(12, MonthlyExpenditureMatrix::count());
        $this->assertSame(
            12,
            MonthlyExpenditureMatrix::where('status', 'draft')->count()
        );
        $this->assertSame(0, MonthlyExpenditureItem::count());
    }

    public function test_initializing_the_same_year_again_does_not_duplicate(): void
    {
        $sh = $this->schoolHead();

        $component = Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('year', '2026')
            ->call('generateYear');

        $ids = MonthlyExpenditureMatrix::orderBy('id')->pluck('id')->all();

        $component->call('generateYear')->call('generateYear');

        $this->assertSame(12, MonthlyExpenditureMatrix::count());
        // The same rows, untouched — not replaced.
        $this->assertSame($ids, MonthlyExpenditureMatrix::orderBy('id')->pluck('id')->all());
    }

    public function test_initializing_a_year_preserves_existing_months_and_creates_only_the_missing(): void
    {
        $sh = $this->schoolHead();

        // A finalized July with a line — the record already there.
        $july = $this->matrixFor($sh, month: 7, year: 2026);
        $july->update(['status' => MonthlyExpenditureMatrix::STATUS_FINALIZED]);
        $july->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Existing line',
        ]);
        $julyId = $july->id;

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('year', '2026')
            ->call('generateYear');

        $this->assertSame(12, MonthlyExpenditureMatrix::count());

        // July is the SAME row, still finalized, with its line intact.
        $july->refresh();
        $this->assertSame($julyId, $july->id);
        $this->assertSame('finalized', $july->status);
        $this->assertSame(1, $july->items()->count());

        // The other eleven are new Drafts with no lines.
        $this->assertSame(11, MonthlyExpenditureMatrix::where('status', 'draft')->count());
    }

    public function test_initializing_a_year_is_scoped_to_the_callers_school(): void
    {
        $owner = $this->schoolHead('School A');
        $other = $this->schoolHead('School B');

        Livewire::actingAs($owner)->test(MemMatrixList::class)
            ->set('year', '2026')
            ->call('generateYear');

        // Nobody else got a single month.
        $this->assertSame(12, MonthlyExpenditureMatrix::where('school_id', $owner->school_id)->count());
        $this->assertSame(0, MonthlyExpenditureMatrix::where('school_id', $other->school_id)->count());
    }

    public function test_changing_the_year_initializes_the_new_year_and_shows_its_months(): void
    {
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('year', '2025')
            ->call('generateYear')
            ->set('year', '2026')
            // updatedYear hooks in and ensures 2026 exists too.
            ->assertSet('year', '2026');

        $this->assertSame(12, MonthlyExpenditureMatrix::where('year', 2025)->count());
        $this->assertSame(12, MonthlyExpenditureMatrix::where('year', 2026)->count());
    }

    public function test_the_table_lists_the_months_in_calendar_order(): void
    {
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('year', '2026')
            ->call('generateYear')
            ->assertSeeInOrder([
                'January 2026',
                'February 2026',
                'March 2026',
                'April 2026',
                'May 2026',
                'June 2026',
                'July 2026',
                'August 2026',
                'September 2026',
                'October 2026',
                'November 2026',
                'December 2026',
            ]);
    }

    public function test_the_status_filter_narrows_the_years_months(): void
    {
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('year', '2026')
            ->call('generateYear');

        MonthlyExpenditureMatrix::where('year', 2026)->where('month', 5)
            ->update(['status' => MonthlyExpenditureMatrix::STATUS_FINALIZED]);

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('year', '2026')
            ->set('status', 'finalized')
            ->assertSee('May 2026')
            ->assertDontSee('January 2026');
    }

    public function test_a_school_head_with_no_school_cannot_initialize_a_year(): void
    {
        $sh = User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => null]);

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('year', '2026')
            ->call('generateYear')
            ->assertSet('errorMessage', fn ($value) => $value !== null);

        $this->assertSame(0, MonthlyExpenditureMatrix::count());
    }

    public function test_a_new_year_can_be_added_and_its_twelve_months_are_created(): void
    {
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('newYear', '2027')
            ->call('addYear')
            ->assertSet('errorMessage', null)
            // The filter switches to the year just added, so its months show.
            ->assertSet('year', '2027')
            ->assertSee('January 2027');

        $this->assertSame(
            12,
            MonthlyExpenditureMatrix::where('school_id', $sh->school_id)->where('year', 2027)->count()
        );
    }

    public function test_adding_an_out_of_range_year_is_rejected(): void
    {
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('newYear', '1999')
            ->call('addYear')
            ->assertSet('errorMessage', fn ($value) => $value !== null);

        $this->assertSame(0, MonthlyExpenditureMatrix::count());
    }

    public function test_a_school_head_with_no_school_cannot_add_a_year(): void
    {
        $sh = User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => null]);

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('newYear', '2027')
            ->call('addYear')
            ->assertSet('errorMessage', fn ($value) => $value !== null);

        $this->assertSame(0, MonthlyExpenditureMatrix::count());
    }

    // ------------------------------------------------------------------ edit

    public function test_the_grid_saves_lines_and_recomputes_the_total(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            // The blank row the first addRow appends is discarded on save;
            // the second addRow persists the filled line.
            ->call('addRow')
            ->set('items.0.activity_description', 'Procure supplies')
            ->set('items.0.section', 'operations')
            ->set('items.0.quantity', '10')
            ->set('items.0.unit_cost', '250')
            ->call('addRow')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('monthly_expenditure_items', [
            'matrix_id' => $matrix->id,
            'activity_description' => 'Procure supplies',
        ]);

        $this->assertEquals(2500.0, (float) MonthlyExpenditureItem::firstOrFail()->calculated_total);
        // Two writes advanced the version, so a generated WFP now reads out of date.
        $this->assertSame(3, (int) $matrix->fresh()->version);
    }

    public function test_saving_requires_an_activity_description(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        // The line is non-blank (it has a quantity), but has no activity.
        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            // The blank row the first addRow appends is discarded on save;
            // the second addRow persists and validates the filled line.
            ->call('addRow')
            ->set('items.0.quantity', '5')
            ->set('items.0.section', 'operations')
            ->set('items.0.activity_description', '')
            ->call('addRow')
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

        // The modal row joined the grid unsaved; a structural mutation writes it.
        $component->call('addRow')->assertHasNoErrors();

        $this->assertDatabaseHas('monthly_expenditure_items', [
            'matrix_id' => $matrix->id,
            'section' => 'project',
            'activity_description' => 'Procure supplies',
        ]);
        $this->assertEquals(2500.0, (float) MonthlyExpenditureItem::firstOrFail()->calculated_total);
    }

    public function test_the_line_total_recalculates_while_the_money_fields_are_typed(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            // Live, not deferred: the total follows the two inputs as they are
            // typed instead of waiting for a blur or a save.
            ->assertSeeHtml('wire:model.live.debounce.300ms="lineForm.quantity"')
            ->assertSeeHtml('wire:model.live.debounce.300ms="lineForm.unit_cost"')
            ->set('lineForm.quantity', '2')
            ->set('lineForm.unit_cost', '6000')
            ->assertSee('12,000.00');
    }

    public function test_the_line_modal_captures_the_output_and_movs_fields(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Conduct reading camp')
            ->set('lineForm.major_output', 'Reading camp conducted')
            ->set('lineForm.performance_indicator', '80% of learners attend')
            ->set('lineForm.activity_movs', 'Attendance sheet, photos')
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            // The modal row joined the grid unsaved; a structural mutation writes it.
            ->call('addRow')
            ->assertHasNoErrors();

        $item = MonthlyExpenditureItem::firstOrFail();

        $this->assertSame('Reading camp conducted', $item->major_output);
        $this->assertSame('80% of learners attend', $item->performance_indicator);
        $this->assertSame('Attendance sheet, photos', $item->activity_movs);

        // Reopening the line in the modal shows the saved wording.
        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix->fresh()])
            ->call('openEditModal', 'item-'.$item->id)
            ->assertSet('lineForm.major_output', 'Reading camp conducted')
            ->assertSet('lineForm.performance_indicator', '80% of learners attend')
            ->assertSet('lineForm.activity_movs', 'Attendance sheet, photos');
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
            // The blank row the first addRow appends is discarded on save;
            // the second addRow persists the filled line, which gives it a
            // stable key the modal can address.
            ->call('addRow')
            ->set('items.0.activity_description', 'Old description')
            ->set('items.0.section', 'operations')
            ->set('items.0.quantity', '1')
            ->set('items.0.unit_cost', '10')
            ->call('addRow');

        $key = $component->get('items.0._key');

        $component->call('openEditModal', $key)
            ->assertSet('showLineModal', true)
            ->assertSet('editingKey', $key)
            // The modal opens pre-filled with the line's current values.
            ->assertSet('lineForm.activity_description', 'Old description')
            ->set('lineForm.activity_description', 'New description')
            ->set('lineForm.quantity', '4')
            ->set('lineForm.unit_cost', '25')
            // Editing through the modal is a structural mutation: it
            // auto-saves the edit.
            ->call('saveLineFromModal')
            ->assertSet('showLineModal', false)
            ->assertSet('editingKey', null)
            // Edited in place: still one line, not a new one appended.
            ->assertCount('items', 1)
            ->assertSet('items.0.activity_description', 'New description');

        $this->assertSame(1, MonthlyExpenditureItem::count());

        $item = MonthlyExpenditureItem::firstOrFail();

        $this->assertSame('New description', $item->activity_description);
        $this->assertEquals(100.0, (float) $item->calculated_total);
    }

    public function test_choosing_an_account_fills_the_modal_form(): void
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
            ->call('chooseAccount', $classification->id)
            ->assertSet('lineForm.account_title', 'Office Supplies Expenses')
            ->assertSet('lineForm.account_code', '5020301000')
            ->assertSet('lineForm.account_classification_id', $classification->id)
            ->assertSet('accountSearch', 'Office Supplies Expenses');
    }

    public function test_the_account_combobox_filters_by_title_or_object_code(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        AccountClassification::create([
            'account_title' => 'Office Supplies Expenses',
            'rca_code' => '3020301',
            'uacs_sub_object_code' => '5020301',
            'uacs_object_code' => '5020301000',
            'is_active' => true,
        ]);
        AccountClassification::create([
            'account_title' => 'Traveling Expenses',
            'rca_code' => '3020101',
            'uacs_sub_object_code' => '5020101',
            'uacs_object_code' => '5020101000',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal');

        // By a title fragment.
        $component->set('accountSearch', 'office')
            ->assertSee('Office Supplies Expenses')
            ->assertDontSee('Traveling Expenses');

        // By object code.
        $component->set('accountSearch', '5020101')
            ->assertSee('Traveling Expenses')
            ->assertDontSee('Office Supplies Expenses');
    }

    public function test_typing_without_picking_saves_the_text_as_the_account_title(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('accountSearch', 'Miscellaneous Expenses')
            ->set('lineForm.activity_description', 'Buy sundries')
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('items.0.account_title', 'Miscellaneous Expenses')
            ->assertSet('items.0.account_classification_id', null);
    }

    public function test_clear_account_empties_the_line_account(): void
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
            ->call('chooseAccount', $classification->id)
            ->call('clearAccount')
            ->assertSet('lineForm.account_title', '')
            ->assertSet('lineForm.account_code', '')
            ->assertSet('lineForm.account_classification_id', null)
            ->assertSet('accountSearch', '');
    }

    public function test_duplicating_a_line_copies_it_as_a_fresh_unsaved_row(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            // The blank row the first addRow appends is discarded on save;
            // the second addRow persists the filled line.
            ->call('addRow')
            ->set('items.0.activity_description', 'Procure supplies')
            ->set('items.0.section', 'project')
            ->set('items.0.quantity', '2')
            ->set('items.0.unit_cost', '50')
            ->call('addRow');

        $key = $component->get('items.0._key');

        // Duplicating is a structural mutation: original and copy auto-save.
        $component->call('duplicateRow', $key)
            ->assertCount('items', 2)
            ->assertSet('items.1.section', 'project')
            ->assertSet('items.1.activity_description', 'Procure supplies')
            ->assertSet('items.1.id', fn ($value): bool => is_int($value));

        $this->assertSame(2, MonthlyExpenditureItem::count());
    }

    public function test_moving_a_line_flips_its_section(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            // Describe the line before moving it: moveRow's single auto-save
            // writes the section flip together with the activity.
            ->set('items.0.activity_description', 'Build a fence')
            ->set('items.0.section', 'operations')
            ->call('addRow');

        $key = $component->get('items.0._key');

        $component->call('moveRow', $key)
            ->assertSet('items.0.section', 'project');

        $item = MonthlyExpenditureItem::firstOrFail();

        $this->assertSame('project', $item->section);
        $this->assertSame('Build a fence', $item->activity_description);
    }

    public function test_a_line_can_be_dragged_to_a_new_position_within_its_section(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('addRow')
            ->call('addRow')
            ->call('addRow', 'project')
            ->call('addRow');

        $component->set('items.0.activity_description', 'Payment for electricity')
            ->set('items.1.activity_description', 'Payment of school clerk')
            ->set('items.2.activity_description', 'Build a fence')
            ->set('items.3.activity_description', 'Procure supplies');

        $keys = array_column($component->get('items'), '_key');

        // Drag the fourth line to the top of Operations. The project line
        // between them keeps its own place in the matrix.
        $component->call('reorderRow', $keys[3], 0)->assertHasNoErrors();

        $items = $component->get('items');

        $this->assertSame(
            ['Procure supplies', 'Payment for electricity', 'Build a fence', 'Payment of school clerk'],
            array_column($items, 'activity_description')
        );
        $this->assertSame(
            ['operations', 'operations', 'project', 'operations'],
            array_column($items, 'section')
        );

        // A drop writes the order immediately, without Save Draft — opening
        // another month must not lose it. Positions are 1..4 in the new order.
        $this->assertSame(
            ['Procure supplies', 'Payment for electricity', 'Build a fence', 'Payment of school clerk'],
            $matrix->items()->pluck('activity_description')->all()
        );
        $this->assertSame([1, 2, 3, 4], $matrix->items()->pluck('position')->all());
    }

    public function test_the_line_lists_are_wired_for_drag_reordering(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('addRow')
            ->assertSeeHtml('wire:sort="reorderRow"')
            ->assertSeeHtml('wire:sort:item="new-')
            // Only the grip starts a drag, so the row's buttons still click.
            ->assertSeeHtml('wire:sort:handle');
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

    public function test_the_line_list_uses_the_printed_forms_columns(): void
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

        // The table is the printed matrix: its section banner on top, then the
        // form's column headings, then each line's values under the heading.
        // assertSeeTextInOrder, not assertSeeInOrder: the Project heading carries
        // <wbr> break points so it wraps at the slashes instead of mid-word, and
        // the text assertion reads through that markup.
        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->assertSeeTextInOrder([
                'Operations-Related Expenditures',
                'Objectives',
                'Project/Activity/Expenditure',
                'Resources Needed',
                'Total Estimated Cost',
                'Account Title',
                'Account Code',
                'Kind',
                'Quantity',
                'Unit Cost',
                'Improved learner outcomes',
                'Procure office supplies',
                'MOOE',
                'ream',
                'Office Supplies Expenses',
                '5020301000',
                '2,500.00',
            ]);
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

    public function test_a_stale_grid_is_refused_by_the_optimistic_lock(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            // The first addRow auto-saves and bumps the matrix version.
            ->call('addRow')
            ->set('items.0.activity_description', 'Line one')
            ->set('items.0.section', 'operations');

        // Somebody else writes the matrix in the meantime.
        $matrix->bumpVersion();

        $component->call('addRow')
            // The lock check refuses the auto-save: the matrix changed elsewhere.
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

    // ------------------------------------------------------------ month tabs

    public function test_the_editor_shows_a_month_tab_for_every_month_of_the_year(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1, year: 2026);
        $july = $this->matrixFor($sh, month: 7, year: 2026);
        $december = $this->matrixFor($sh, month: 12, year: 2026);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $july])
            // All twelve abbreviation tabs are drawn, January first.
            ->assertSeeInOrder(['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'])
            // The months that exist link straight to their own matrix.
            ->assertSee(route('wfp.matrices.edit', ['matrix' => $january->id]), false)
            ->assertSee(route('wfp.matrices.edit', ['matrix' => $december->id]), false)
            // The month being edited is marked current (and is not a link).
            ->assertSeeHtml('mem-month-tab is-current')
            ->assertSeeHtml('aria-current="page"');
    }

    public function test_the_month_tabs_are_scoped_to_this_matrixs_school_and_year(): void
    {
        $owner = $this->schoolHead('School A');
        $other = $this->schoolHead('School B');

        $july = $this->matrixFor($owner, month: 7, year: 2026);
        // Same month, another school and another year — neither belongs here.
        $otherSchool = $this->matrixFor($other, month: 8, year: 2026);
        $otherYear = $this->matrixFor($owner, month: 8, year: 2027);

        Livewire::actingAs($owner)->test(MemEditor::class, ['matrix' => $july])
            ->assertDontSee(route('wfp.matrices.edit', ['matrix' => $otherSchool->id]), false)
            ->assertDontSee(route('wfp.matrices.edit', ['matrix' => $otherYear->id]), false);
    }

    public function test_a_month_with_no_matrix_is_shown_inactive(): void
    {
        $sh = $this->schoolHead();
        // Only July exists — the other eleven have no matrix to link to.
        $july = $this->matrixFor($sh, month: 7, year: 2026);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $july])
            ->assertSeeHtml('mem-month-tab is-empty');
    }

    public function test_a_finalized_month_carries_a_status_dot_on_its_tab(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1, year: 2026);
        $january->update(['status' => MonthlyExpenditureMatrix::STATUS_FINALIZED]);
        $july = $this->matrixFor($sh, month: 7, year: 2026);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $july])
            ->assertSeeHtml('mem-month-tab__dot--finalized');
    }

    public function test_each_month_tab_carries_that_months_total(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1, year: 2026);
        $july = $this->matrixFor($sh, month: 7, year: 2026);

        $january->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'quantity' => 2,
            'unit_cost' => 1000,
            'calculated_total' => 2000,
        ]);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $july]);

        // January's tab reads its persisted lines.
        $component->assertSee('2,000.00');

        $months = collect($component->instance()->months())->keyBy('month');

        $this->assertSame(2000.0, $months[1]['total']);
        // July is being edited and has no lines yet; February has no matrix.
        $this->assertNull($months[7]['total']);
        $this->assertNull($months[2]['total']);
    }

    public function test_the_current_months_tab_follows_the_unsaved_grid(): void
    {
        $sh = $this->schoolHead();
        $july = $this->matrixFor($sh, month: 7, year: 2026);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $july])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure supplies')
            ->set('lineForm.quantity', '3')
            ->set('lineForm.unit_cost', '500')
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $julyTab = collect($component->instance()->months())->firstWhere('month', 7);

        // Nothing is saved yet, but the tab already matches the total strip.
        $this->assertSame(1500.0, $julyTab['total']);
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

    // -------------------------------------------------- workbook import

    public function test_a_matrix_workbook_previews_then_commits_across_months(): void
    {
        $sh = $this->schoolHead();

        $bytes = $this->memWorkbookBytes([
            'JAN' => [
                ['__section__' => 'Operations-Related Expenses'],
                ['activity_description' => 'Procure supplies', 'quantity' => '10', 'unit_cost' => '250', 'account_code' => '5020301000'],
            ],
            'FEB' => [
                ['activity_description' => 'Build a fence', 'quantity' => '5', 'unit_cost' => '100'],
            ],
        ]);

        $component = Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('year', '2026')
            ->call('openWorkbookImport')
            ->assertSet('showWorkbookImport', true)
            ->set('workbookFile', UploadedFile::fake()->createWithContent('matrix.xlsx', $bytes))
            ->assertSet('workbookValidCount', 2)
            ->assertSet('workbookInvalidCount', 0);

        // Nothing is written until the import is confirmed.
        $this->assertSame(0, MonthlyExpenditureItem::count());

        $component->call('commitWorkbookImport')->assertSet('showWorkbookImport', false);

        $this->assertSame(2, MonthlyExpenditureItem::count());
        $this->assertSame(
            12,
            MonthlyExpenditureMatrix::where('school_id', $sh->school_id)->where('year', 2026)->count()
        );

        $january = MonthlyExpenditureMatrix::where('school_id', $sh->school_id)->where('year', 2026)->where('month', 1)->firstOrFail();
        $this->assertSame(1, $january->items()->count());
        $this->assertEquals(2500.0, (float) $january->items()->firstOrFail()->calculated_total);
    }

    public function test_a_matrix_workbook_import_refuses_another_role(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);

        $this->assertStringContainsString(
            'Page Not Found',
            Livewire::actingAs($viewer)->test(MemMatrixList::class)->html()
        );
    }

    // -------------------------------------------------- UACS ordering

    public function test_the_generator_orders_wfp_lines_by_uacs_sort_order(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh, month: 1);

        // Entered in the reverse of the order they should come out in.
        AccountClassification::create([
            'account_title' => 'Office Supplies Expenses',
            'rca_code' => '3020301',
            'uacs_sub_object_code' => '5020301',
            'uacs_object_code' => '5020301000',
            'sort_order' => 20,
            'is_active' => true,
        ]);
        AccountClassification::create([
            'account_title' => 'Traveling Expenses',
            'rca_code' => '3020101',
            'uacs_sub_object_code' => '5020101',
            'uacs_object_code' => '5020101000',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'quantity' => 1,
            'unit_cost' => 100,
            'account_code' => '5020301000',
        ]);
        $matrix->items()->create([
            'position' => 2,
            'section' => 'operations',
            'activity_description' => 'Travel to training',
            'quantity' => 1,
            'unit_cost' => 100,
            'account_code' => '5020101000',
        ]);

        $plan = WfpPlan::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'status' => WfpPlan::STATUS_DRAFT,
        ]);

        WfpGenerator::generate($plan, $matrix);

        // sort_order 10 (Traveling) before sort_order 20 (Office Supplies),
        // regardless of the order the lines were entered in.
        $this->assertSame(
            ['5020101000', '5020301000'],
            $plan->items()->orderBy('position')->pluck('account_code')->all()
        );
    }

    public function test_unknown_account_codes_sort_after_known_ones(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh, month: 1);

        AccountClassification::create([
            'account_title' => 'Office Supplies Expenses',
            'rca_code' => '3020301',
            'uacs_sub_object_code' => '5020301',
            'uacs_object_code' => '5099999999',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        // The known code is entered second.
        $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Unknown account line',
            'quantity' => 1,
            'unit_cost' => 100,
            'account_code' => '1111111111',
        ]);
        $matrix->items()->create([
            'position' => 2,
            'section' => 'operations',
            'activity_description' => 'Known account line',
            'quantity' => 1,
            'unit_cost' => 100,
            'account_code' => '5099999999',
        ]);

        $plan = WfpPlan::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'status' => WfpPlan::STATUS_DRAFT,
        ]);

        WfpGenerator::generate($plan, $matrix);

        $this->assertSame(
            ['5099999999', '1111111111'],
            $plan->items()->orderBy('position')->pluck('account_code')->all()
        );
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
