<?php

namespace Tests\Feature;

use App\Livewire\MemEditor;
use App\Livewire\MemMatrixList;
use App\Models\AccountClassification;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Models\WfpPlan;
use App\Models\WfpReportCheck;
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

    // ---------------------------------------------------------------- account code correction stamp

    private function matrixItem(MonthlyExpenditureMatrix $matrix): MonthlyExpenditureItem
    {
        return $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
        ]);
    }

    public function test_a_bookkeeper_matrix_edit_stamps_the_account_code_correction(): void
    {
        $head = $this->schoolHead('Stamped School');
        $matrix = $this->matrixFor($head);
        $item = $this->matrixItem($matrix);

        $bk = User::factory()->create(['role' => 'bookkeeper', 'is_superadmin' => false]);
        $bk->syncAssignedSchools([$head->school_id]);

        Livewire::actingAs($bk)
            ->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openAccountCodeEdit', 'item-'.$item->id)
            ->set('accountCodeForm', '5020301001')
            ->call('saveAccountCodeEdit');

        $this->assertTrue($item->fresh()->wasCorrectedByBookkeeper());
        $this->assertSame($bk->id, $item->fresh()->corrected_by);
        $this->assertNotNull($item->fresh()->corrected_at);
    }

    public function test_a_school_head_matrix_edit_is_not_stamped_as_bookkeeper_correction(): void
    {
        $head = $this->schoolHead('SH Stamp School');
        $matrix = $this->matrixFor($head);
        $item = $this->matrixItem($matrix);

        Livewire::actingAs($head)
            ->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openAccountCodeEdit', 'item-'.$item->id)
            ->set('accountCodeForm', '5020301001')
            ->call('saveAccountCodeEdit');

        $this->assertNull($item->fresh()->corrected_by);
        $this->assertFalse($item->fresh()->wasCorrectedByBookkeeper());
    }

    public function test_matrix_open_links_navigate_directly_to_the_editor(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);
        $url = route('wfp.matrices.edit', ['matrix' => $matrix->id]);

        Livewire::actingAs($sh)
            ->test(MemMatrixList::class)
            ->assertSee('href="'.$url.'"', false)
            ->assertSee('wire:navigate.hover', false);

        $this->actingAs($sh)->get($url)->assertOk();
    }

    public function test_the_component_refuses_a_guest_and_a_non_school_head(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);

        $this->assertStringContainsString('Page Not Found', Livewire::test(MemMatrixList::class)->html());
        $this->assertStringContainsString('Page Not Found', Livewire::actingAs($viewer)->test(MemMatrixList::class)->html());
    }

    public function test_template_download_returns_an_xlsx_for_the_school_head(): void
    {
        $response = $this->actingAs($this->schoolHead())
            ->get(route('wfp.matrices.template'));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="mem-template.xlsx"');

        $this->assertStringStartsWith('PK', $response->getContent());
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

    public function test_opening_the_page_does_not_create_the_years_months(): void
    {
        // Months exist only because the SH added the year: merely opening
        // the page must never write.
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->assertSet('year', (string) now()->year);

        $this->assertSame(0, MonthlyExpenditureMatrix::count());
    }

    public function test_the_list_offers_add_year_as_the_only_way_to_create_months(): void
    {
        Livewire::actingAs($this->schoolHead())->test(MemMatrixList::class)
            ->assertSee('Add Year')
            ->assertSee('No matrices for CY '.now()->year.' yet', false)
            ->assertDontSee('Initialize Year')
            // The manual add is covered by a spinner while it runs.
            ->assertSeeHtml('class="mem-table-loading"')
            ->assertSeeHtml('wire:loading.flex');
    }

    public function test_adding_the_same_year_again_does_not_duplicate(): void
    {
        $sh = $this->schoolHead();

        $component = Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('newYear', '2026')
            ->call('addYear');

        $ids = MonthlyExpenditureMatrix::orderBy('id')->pluck('id')->all();

        $component->set('newYear', '2026')->call('addYear');

        $this->assertSame(12, MonthlyExpenditureMatrix::count());
        // The same rows, untouched — not replaced.
        $this->assertSame($ids, MonthlyExpenditureMatrix::orderBy('id')->pluck('id')->all());
    }

    public function test_adding_a_year_preserves_existing_months_and_creates_only_the_missing(): void
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
            ->set('newYear', '2026')
            ->call('addYear');

        $this->assertSame(12, MonthlyExpenditureMatrix::count());

        // July is the SAME row, still finalized, with its line intact.
        $july->refresh();
        $this->assertSame($julyId, $july->id);
        $this->assertSame('finalized', $july->status);
        $this->assertSame(1, $july->items()->count());

        // The other eleven are new Drafts with no lines.
        $this->assertSame(11, MonthlyExpenditureMatrix::where('status', 'draft')->count());
    }

    public function test_adding_a_year_is_scoped_to_the_callers_school(): void
    {
        $owner = $this->schoolHead('School A');
        $other = $this->schoolHead('School B');

        Livewire::actingAs($owner)->test(MemMatrixList::class)
            ->set('newYear', '2026')
            ->call('addYear');

        // Nobody else got a single month.
        $this->assertSame(12, MonthlyExpenditureMatrix::where('school_id', $owner->school_id)->count());
        $this->assertSame(0, MonthlyExpenditureMatrix::where('school_id', $other->school_id)->count());
    }

    public function test_changing_the_year_does_not_create_its_months(): void
    {
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('newYear', '2025')
            ->call('addYear')
            ->set('year', '2026')
            ->assertSet('year', '2026');

        $this->assertSame(12, MonthlyExpenditureMatrix::where('year', 2025)->count());
        // Filtering only narrows the list; the months of the chosen year are
        // created by "Add Year", never by browsing to it.
        $this->assertSame(0, MonthlyExpenditureMatrix::where('year', 2026)->count());
    }

    public function test_the_table_lists_the_months_in_calendar_order(): void
    {
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('newYear', '2026')
            ->call('addYear')
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

    public function test_adding_a_year_creates_its_twelve_blank_drafts(): void
    {
        $sh = $this->schoolHead();

        Livewire::actingAs($sh)->test(MemMatrixList::class)
            ->set('newYear', '2027')
            ->call('addYear')
            ->assertHasNoErrors()
            ->assertSet('errorMessage', null)
            // The filter switches to the year just added, so its months show.
            ->assertSet('year', '2027')
            ->assertSee('January 2027');

        $months = MonthlyExpenditureMatrix::where('school_id', $sh->school_id)
            ->where('year', 2027)
            ->orderBy('month')
            ->pluck('month')
            ->all();

        $this->assertSame(range(1, 12), $months);

        // New months start as blank Drafts with zero lines.
        $this->assertSame(12, MonthlyExpenditureMatrix::where('status', 'draft')->count());
        $this->assertSame(0, MonthlyExpenditureItem::count());
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
            ->set('lineForm.objective', 'Office supplies for learners')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '10')
            ->set('lineForm.unit', 'piece')
            ->set('lineForm.unit_cost', '250')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
            ->call('saveLineFromModal')
            ->assertSet('showLineModal', false)
            ->assertHasNoErrors();

        // The modal closed and the filled row joined the grid, in the section
        // the modal was opened for. Saving from the modal is a write: the
        // line is persisted straight away.
        $items = $component->get('items');

        $this->assertCount(1, $items);
        $this->assertSame('project', $items[0]['section']);
        $this->assertSame('Procure supplies', $items[0]['activity_description']);
        $this->assertSame(1, MonthlyExpenditureItem::count());

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
            ->set('lineForm.objective', 'Better reading')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '1')
            ->set('lineForm.unit_cost', '100')
            ->set('lineForm.account_title', 'Other Maintenance and Operating Expenses')
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

    public function test_the_add_line_modal_requires_an_objective(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure supplies')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '5')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
            ->call('saveLineFromModal')
            ->assertHasErrors(['lineForm.objective'])
            ->assertSet('showLineModal', true);

        $this->assertSame(0, MonthlyExpenditureItem::count());
    }

    public function test_the_add_line_modal_requires_a_resource_needed_row(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure supplies')
            ->set('lineForm.objective', 'Stocked office')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
            ->call('saveLineFromModal')
            ->assertHasErrors(['lineForm.resource_kind'])
            ->assertSet('showLineModal', true);

        $this->assertSame(0, MonthlyExpenditureItem::count());
    }

    public function test_the_add_line_modal_requires_an_account_title_or_code(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure supplies')
            ->set('lineForm.objective', 'Stocked office')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '5')
            ->call('saveLineFromModal')
            ->assertHasErrors(['lineForm.account_title'])
            ->assertSet('showLineModal', true);

        $this->assertSame(0, MonthlyExpenditureItem::count());
    }

    public function test_the_add_line_modal_accepts_an_account_code_without_a_title(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure supplies')
            ->set('lineForm.objective', 'Stocked office')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '5')
            ->set('lineForm.account_code', '5020301000')
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('showLineModal', false);

        $item = MonthlyExpenditureItem::firstOrFail();
        $this->assertSame('5020301000', $item->account_code);
        $this->assertNull($item->account_title);
    }

    public function test_the_add_line_modal_saves_when_objective_resource_and_account_are_filled(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure supplies')
            ->set('lineForm.objective', 'Stocked office')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '5')
            ->set('lineForm.unit_cost', '100')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('showLineModal', false);

        $this->assertSame(1, MonthlyExpenditureItem::count());
    }

    public function test_a_closed_line_modal_keeps_its_draft_until_it_is_saved(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'project')
            ->set('lineForm.activity_description', 'Draft line')
            ->set('lineForm.objective', 'Draft objective')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
            ->call('addLineResource')
            ->set('lineResources.0.resource_kind', 'Bond Paper A4 70 gsm')
            ->set('lineResources.0.quantity', '10')
            ->call('closeLineModal')
            ->assertSet('showLineModal', false)
            // An accidental close keeps the draft: reopening the modal shows
            // it again, even when the button that reopened it names another
            // section.
            ->call('openLineModal', 'operations')
            ->assertSet('lineSection', 'project')
            ->assertSet('lineForm.activity_description', 'Draft line')
            ->assertCount('lineResources', 1)
            ->assertSet('lineResources.0.resource_kind', 'Bond Paper A4 70 gsm');

        // Saving commits the draft and only then does the form start clean.
        $component->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('showLineModal', false)
            ->assertSet('lineForm', [])
            ->assertCount('lineResources', 0);

        $this->assertSame(1, MonthlyExpenditureItem::count());
        $this->assertSame('Draft line', MonthlyExpenditureItem::firstOrFail()->activity_description);
        $this->assertSame('project', MonthlyExpenditureItem::firstOrFail()->section);

        // A brand-new editor session opens a blank form.
        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix->fresh()])
            ->call('openLineModal', 'operations')
            ->assertSet('lineSection', 'operations')
            ->assertSet('lineForm.activity_description', '')
            ->assertCount('lineResources', 0);
    }

    public function test_closing_the_edit_modal_without_saving_keeps_the_line_being_edited(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Original',
            'quantity' => 1,
            'unit_cost' => 10,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openEditModal', 'item-1')
            ->assertSet('editingKey', 'item-1')
            ->assertSet('lineForm.activity_description', 'Original')
            ->set('lineForm.activity_description', 'Revised')
            ->call('closeLineModal')
            ->assertSet('showLineModal', false)
            ->assertSet('editingKey', 'item-1')
            ->call('openLineModal')
            ->assertSet('showLineModal', true)
            ->assertSet('editingKey', 'item-1')
            ->assertSet('lineForm.activity_description', 'Revised');

        // Nothing was committed.
        $this->assertSame('Original', $item->fresh()->activity_description);
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
            ->set('lineForm.objective', 'Revised objective')
            ->set('lineForm.quantity', '4')
            ->set('lineForm.unit_cost', '25')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
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

    // ---------------------------------------------------- account code only edit

    public function test_the_account_code_edit_modal_opens_with_the_current_code(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'account_code' => '5020301000',
            'account_title' => 'Office Supplies Expenses',
            'quantity' => 1,
            'unit_cost' => 10,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openAccountCodeEdit', 'item-'.$item->id)
            ->assertSet('accountCodeEditingKey', 'item-'.$item->id)
            ->assertSet('accountCodeForm', '5020301000');
    }

    public function test_saving_the_account_code_changes_only_the_code(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'account_code' => '5020301000',
            'account_title' => 'Office Supplies Expenses',
            'quantity' => 1,
            'unit_cost' => 10,
        ]);

        $versionBefore = $matrix->version;

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openAccountCodeEdit', 'item-'.$item->id)
            ->set('accountCodeForm', '5020302000')
            ->call('saveAccountCodeEdit')
            ->assertHasNoErrors()
            ->assertSet('accountCodeEditingKey', null)
            ->assertSet('accountCodeForm', '');

        $this->assertSame('5020302000', $item->fresh()->account_code);
        // Everything else is untouched.
        $this->assertSame('Office Supplies Expenses', $item->fresh()->account_title);
        $this->assertSame(1, (int) $item->fresh()->quantity);
        $this->assertEquals(10, (float) $item->fresh()->unit_cost);
        // The matrix version advances.
        $this->assertSame($versionBefore + 1, $matrix->fresh()->version);
    }

    public function test_clearing_the_account_code_stores_null(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'account_code' => '5020301000',
            'quantity' => 1,
            'unit_cost' => 10,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openAccountCodeEdit', 'item-'.$item->id)
            ->set('accountCodeForm', '')
            ->call('saveAccountCodeEdit')
            ->assertHasNoErrors();

        $this->assertNull($item->fresh()->account_code);
    }

    public function test_only_the_owning_school_head_can_edit_the_account_code(): void
    {
        $owner = $this->schoolHead('School A');
        $matrix = $this->matrixFor($owner);

        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'account_code' => '5020301000',
            'quantity' => 1,
            'unit_cost' => 10,
        ]);

        Livewire::actingAs($owner)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openAccountCodeEdit', 'item-'.$item->id)
            ->set('accountCodeForm', '5020302000')
            ->call('saveAccountCodeEdit')
            ->assertHasNoErrors()
            ->assertSet('accountCodeEditingKey', null)
            ->assertSet('accountCodeForm', '');

        $this->assertSame('5020302000', $item->fresh()->account_code);
    }

    public function test_a_viewer_cannot_open_the_account_code_edit_modal(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $sh = $this->schoolHead('School A');
        $matrix = $this->matrixFor($sh);

        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'account_code' => '5020301000',
            'quantity' => 1,
            'unit_cost' => 10,
        ]);

        $html = Livewire::actingAs($viewer)->test(MemEditor::class, ['matrix' => $matrix])
            ->html();

        $this->assertStringContainsString('Page Not Found', $html);
        $this->assertSame('5020301000', $item->fresh()->account_code);
    }

    public function test_closing_the_account_code_modal_without_saving_keeps_the_code(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'account_code' => '5020301000',
            'quantity' => 1,
            'unit_cost' => 10,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openAccountCodeEdit', 'item-'.$item->id)
            ->set('accountCodeForm', '9999999999')
            ->call('closeAccountCodeEdit')
            ->assertSet('accountCodeEditingKey', null)
            ->assertSet('accountCodeForm', '');

        // Nothing was committed.
        $this->assertSame('5020301000', $item->fresh()->account_code);
    }

    public function test_a_bookkeeper_can_edit_account_code_for_rostered_school(): void
    {
        $school = School::create([
            'district_id' => District::create(['name' => 'Bookkeeper District'])->id,
            'name' => 'Bookkeeper School',
            'school_id' => 'BK-001',
        ]);

        $bookkeeper = User::factory()->create([
            'role' => 'bookkeeper',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);
        $bookkeeper->syncAssignedSchools([$school->id]);

        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $school->id,
            'year' => 2026,
            'month' => 7,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $bookkeeper->id,
        ]);

        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'account_code' => '5020301000',
            'quantity' => 1,
            'unit_cost' => 10,
        ]);

        Livewire::actingAs($bookkeeper)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openAccountCodeEdit', 'item-'.$item->id)
            ->assertSet('accountCodeEditingKey', 'item-'.$item->id)
            ->set('accountCodeForm', '5020302000')
            ->call('saveAccountCodeEdit')
            ->assertHasNoErrors();

        $this->assertSame('5020302000', $item->fresh()->account_code);
    }

    public function test_a_bookkeeper_cannot_edit_account_code_for_unrostered_school(): void
    {
        $rosteredSchool = School::create([
            'district_id' => District::create(['name' => 'Bookkeeper District'])->id,
            'name' => 'Bookkeeper School',
            'school_id' => 'BK-001',
        ]);
        $otherSchool = School::create([
            'district_id' => District::create(['name' => 'Other District'])->id,
            'name' => 'Other School',
            'school_id' => 'BK-002',
        ]);

        $bookkeeper = User::factory()->create([
            'role' => 'bookkeeper',
            'is_superadmin' => false,
            'school_id' => $rosteredSchool->id,
        ]);
        $bookkeeper->syncAssignedSchools([$rosteredSchool->id]);

        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $otherSchool->id,
            'year' => 2026,
            'month' => 7,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $bookkeeper->id,
        ]);

        $item = $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
            'account_code' => '5020301000',
            'quantity' => 1,
            'unit_cost' => 10,
        ]);

        $html = Livewire::actingAs($bookkeeper)->test(MemEditor::class, ['matrix' => $matrix])
            ->html();

        $this->assertStringContainsString('Page Not Found', $html);
        $this->assertSame('5020301000', $item->fresh()->account_code);
    }

    public function test_the_add_line_modal_can_encode_several_resources_at_once(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->assertSee('Add resource')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->set('lineForm.resource_kind', 'Bond Paper A4 70 gsm')
            ->set('lineForm.quantity', '85')
            ->set('lineForm.unit', 'reams')
            ->set('lineForm.unit_cost', '250')
            ->set('lineForm.objective', 'Stocked office')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
            ->call('addLineResource')
            ->assertCount('lineResources', 1)
            // The extra row's money fields are live, so its own total follows.
            ->assertSeeHtml('wire:model.live.debounce.300ms="lineResources.0.quantity"')
            ->set('lineResources.0.resource_kind', 'Ballpen 0.75 Black')
            ->set('lineResources.0.quantity', '68')
            ->set('lineResources.0.unit', 'pcs')
            ->set('lineResources.0.unit_cost', '10')
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('showLineModal', false);

        // One activity, two Resource Needed rows — exactly the printed form's
        // shape: every filled row is its own line sharing the activity.
        $items = MonthlyExpenditureItem::query()->orderBy('position')->get();

        $this->assertCount(2, $items);

        foreach ($items as $item) {
            $this->assertSame('Procure office supplies', $item->activity_description);
            $this->assertSame('operations', $item->section);
        }

        $this->assertSame('Bond Paper A4 70 gsm', $items[0]->resource_kind);
        $this->assertSame('reams', $items[0]->unit);
        $this->assertEquals(85.0, (float) $items[0]->quantity);
        $this->assertEquals(21250.0, (float) $items[0]->calculated_total);

        $this->assertSame('Ballpen 0.75 Black', $items[1]->resource_kind);
        $this->assertSame('pcs', $items[1]->unit);
        $this->assertEquals(68.0, (float) $items[1]->quantity);
        $this->assertEquals(680.0, (float) $items[1]->calculated_total);
    }

    public function test_a_blank_resource_row_is_ignored_on_save(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->set('lineForm.objective', 'Stocked office')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
            // The primary row is left empty; the first extra row carries the
            // resource, and a second extra row is never filled in.
            ->call('addLineResource')
            ->set('lineResources.0.resource_kind', 'Bond Paper A4 70 gsm')
            ->set('lineResources.0.quantity', '85')
            ->set('lineResources.0.unit', 'reams')
            ->set('lineResources.0.unit_cost', '250')
            ->call('addLineResource')
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $item = MonthlyExpenditureItem::firstOrFail();

        $this->assertSame('Procure office supplies', $item->activity_description);
        $this->assertSame('Bond Paper A4 70 gsm', $item->resource_kind);
        $this->assertEquals(21250.0, (float) $item->calculated_total);
        $this->assertSame(1, MonthlyExpenditureItem::count());
    }

    public function test_several_resources_are_copied_into_every_checked_month(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->set('lineForm.resource_kind', 'Bond Paper A4 70 gsm')
            ->set('lineForm.quantity', '85')
            ->set('lineForm.unit', 'reams')
            ->set('lineForm.unit_cost', '250')
            ->set('lineForm.objective', 'Stocked office')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
            ->call('addLineResource')
            ->set('lineResources.0.resource_kind', 'Ballpen 0.75 Black')
            ->set('lineResources.0.quantity', '68')
            ->set('lineResources.0.unit', 'pcs')
            ->set('lineResources.0.unit_cost', '10')
            ->set('lineRepeated', 'yes')
            ->set('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('successMessage', '2 lines added to Jan and Feb 2026.');

        foreach ([$january, $february] as $matrix) {
            $items = $matrix->items()->get();

            $this->assertCount(2, $items);
            $this->assertSame(
                ['Bond Paper A4 70 gsm', 'Ballpen 0.75 Black'],
                $items->pluck('resource_kind')->all(),
            );
            $this->assertEquals(21930.0, (float) $items->sum('calculated_total'));
        }
    }

    public function test_editing_a_line_can_add_more_resources_to_the_checked_months(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        // The original line is applied to both months first.
        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->set('lineForm.resource_kind', 'Bond Paper A4 70 gsm')
            ->set('lineForm.quantity', '85')
            ->set('lineForm.unit_cost', '250')
            ->set('lineForm.objective', 'Stocked office')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
            ->set('lineRepeated', 'yes')
            ->set('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $original = $january->items()->firstOrFail();

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january->fresh()])
            ->call('openEditModal', 'item-'.$original->id)
            ->call('addLineResource')
            ->set('lineResources.0.resource_kind', 'Ballpen 0.75 Black')
            ->set('lineResources.0.quantity', '68')
            ->set('lineResources.0.unit', 'pcs')
            ->set('lineResources.0.unit_cost', '10')
            ->set('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        foreach ([$january, $february] as $matrix) {
            $this->assertSame(2, $matrix->items()->count());
            $this->assertSame(1, $matrix->items()->where('resource_kind', 'Ballpen 0.75 Black')->count());
        }
    }

    public function test_an_extra_resource_quantity_must_be_numeric(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->call('addLineResource')
            ->set('lineResources.0.quantity', 'ten')
            ->call('saveLineFromModal')
            ->assertHasErrors(['lineResources.0.quantity'])
            ->assertSet('showLineModal', true);

        $this->assertSame(0, MonthlyExpenditureItem::count());
    }

    public function test_an_extra_resource_row_can_be_removed_before_saving(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->call('addLineResource')
            ->call('addLineResource')
            ->assertCount('lineResources', 2)
            ->call('removeLineResource', 0)
            ->assertCount('lineResources', 1);
    }

    // ------------------------------------------------------- apply to months

    public function test_the_add_line_modal_checks_the_current_month_by_default(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh, month: 3);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            // A new line is not repeated until the user says so: the month
            // picker stays hidden, with only the current month selected.
            ->assertSet('lineRepeated', 'no')
            ->assertSet('lineMonths', [3])
            ->assertSee('Is this repeated item?')
            ->assertDontSee('Apply to months');
    }

    public function test_choosing_yes_reveals_the_months_and_apply_to_all_ticks_every_existing_month(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $this->matrixFor($sh, month: 2);
        $this->matrixFor($sh, month: 3);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openLineModal')
            ->set('lineRepeated', 'yes')
            ->assertSee('Apply to months')
            ->assertSeeHtml('wire:model="lineMonths"')
            // Months without a matrix stay visible but unselectable.
            ->assertSee('No matrix yet — add the year first')
            ->call('applyLineToAllMonths')
            ->assertSet('lineMonths', [1, 2, 3]);
    }

    public function test_a_line_that_is_not_repeated_writes_only_the_current_month(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->set('lineForm.objective', 'Office supplies for learners')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '1')
            ->set('lineForm.unit_cost', '100')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
            // Even a stale month selection cannot make a "No" line write
            // beyond the month being edited.
            ->set('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $this->assertSame(1, $january->items()->count());
        $this->assertSame(0, $february->items()->count());
    }

    public function test_switching_back_to_no_resets_the_months_to_the_current_month(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh, month: 3);
        $this->matrixFor($sh, month: 2);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineRepeated', 'yes')
            ->set('lineMonths', [2, 3])
            ->set('lineRepeated', 'no')
            ->assertSet('lineMonths', [3]);
    }

    public function test_adding_a_line_copies_it_into_every_checked_month(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);
        $march = $this->matrixFor($sh, month: 3);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Monthly internet')
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '1')
            ->set('lineForm.unit_cost', '1500')
            ->set('lineRepeated', 'yes')
            ->set('lineMonths', [1, 2, 3])
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Line added to Jan, Feb and Mar 2026.');

        foreach ([$january, $february, $march] as $matrix) {
            $this->assertSame(1, $matrix->items()->count());

            $item = $matrix->items()->firstOrFail();

            $this->assertSame('Monthly internet', $item->activity_description);
            $this->assertSame('MOOE', $item->resource_kind);
            $this->assertEquals(1500.0, (float) $item->calculated_total);
        }

        // Every month written is bumped, so a WFP mapped from the old version
        // reads as out of date.
        $this->assertSame(2, $january->fresh()->version);
        $this->assertSame(2, $february->fresh()->version);
        $this->assertSame(2, $march->fresh()->version);
    }

    public function test_a_month_without_a_matrix_cannot_receive_the_line(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openLineModal')
            ->set('lineRepeated', 'yes')
            // The missing month is shown, but not selectable: adding the year
            // is still the only path that creates months.
            ->assertSee('No matrix yet — add the year first')
            ->set('lineForm.activity_description', 'Monthly internet')
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->set('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasErrors(['lineMonths'])
            ->assertSet('showLineModal', true);

        // Refused before anything is written.
        $this->assertSame(1, MonthlyExpenditureMatrix::count());
        $this->assertSame(0, MonthlyExpenditureItem::count());
    }

    public function test_unchecking_the_current_month_leaves_it_untouched(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        WfpReportCheck::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'checked_by' => $sh->id,
            'checked_at' => now(),
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Monthly internet')
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.unit_cost', '1500')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->set('lineRepeated', 'yes')
            ->set('lineMonths', [2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $this->assertSame(0, $january->items()->count());
        $this->assertSame(1, $february->items()->count());
        $this->assertSame(1, $january->fresh()->version);
        $this->assertSame(2, $february->fresh()->version);

        // Writing another month of the year still invalidates the check.
        $this->assertNull(WfpReportCheck::first());
    }

    public function test_the_edit_modal_offers_apply_to_months_with_the_edited_month_checked(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh, month: 3);

        $item = MonthlyExpenditureItem::create([
            'matrix_id' => $matrix->id,
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Old line',
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openEditModal', 'item-'.$item->id)
            // A line held by one month only is not repeated: the picker is
            // hidden until the user says Yes.
            ->assertSet('lineRepeated', 'no')
            ->assertSet('lineMonths', [3])
            ->assertDontSee('Apply to months')
            ->set('lineRepeated', 'yes')
            ->assertSee('Apply to months')
            ->assertSee('Only this month is edited — unchecking a month removes the line there.')
            // The month holding the line opens checked, like any other month
            // that holds it.
            ->assertSet('lineMonths', [3]);
    }

    public function test_editing_a_line_leaves_the_copy_in_a_checked_month_untouched(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        $jan = $january->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'resource_kind' => 'MOOE',
            'quantity' => 1,
            'unit_cost' => 1500,
            'calculated_total' => 1500,
        ]);
        $feb = $february->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'resource_kind' => 'MOOE',
            'quantity' => 1,
            'unit_cost' => 1500,
            'calculated_total' => 1500,
        ]);

        WfpReportCheck::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'checked_by' => $sh->id,
            'checked_at' => now(),
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openEditModal', 'item-'.$jan->id)
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->set('lineForm.unit_cost', '2000')
            ->set('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            // Nothing outside this month was written, so there is nothing to
            // announce.
            ->assertSet('successMessage', null);

        // The edit belongs to January: February keeps the copy it already has
        // rather than receiving this month's values.
        $this->assertSame(1, $february->items()->count());
        $this->assertEquals(1500.0, (float) $feb->fresh()->unit_cost);
        $this->assertEquals(1500.0, (float) $feb->fresh()->calculated_total);
        $this->assertEquals(2000.0, (float) $jan->fresh()->unit_cost);
        $this->assertEquals(2000.0, (float) $jan->fresh()->calculated_total);

        // Only the month actually written is bumped, so a WFP mapped from the
        // other month's version still reads as current.
        $this->assertSame(2, $january->fresh()->version);
        $this->assertSame(1, $february->fresh()->version);

        // Writing another month of the year still invalidates the check.
        $this->assertNull(WfpReportCheck::first());
    }

    public function test_editing_a_line_adds_a_copy_to_a_checked_month_without_one(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        $jan = $january->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openEditModal', 'item-'.$jan->id)
            ->set('lineForm.activity_description', 'Monthly internet (revised)')
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->set('lineRepeated', 'yes')
            ->set('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $this->assertSame(1, $february->items()->count());

        $copy = $february->items()->firstOrFail();

        $this->assertSame('Monthly internet (revised)', $copy->activity_description);
        $this->assertEquals(1500.0, (float) $copy->unit_cost);
        $this->assertEquals(1500.0, (float) $copy->calculated_total);
        $this->assertSame(2, $february->fresh()->version);
    }

    public function test_editing_a_line_never_overwrites_a_copy_changed_differently(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        $jan = $january->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
        ]);
        // February's line started as a copy but was already changed there.
        $feb = $february->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 999,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openEditModal', 'item-'.$jan->id)
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->set('lineForm.unit_cost', '2000')
            ->set('lineRepeated', 'yes')
            ->set('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        // The diverged February line keeps its own value; the edited line is
        // appended as a fresh copy instead.
        $this->assertSame(2, $february->items()->count());
        $this->assertEquals(999.0, (float) $feb->fresh()->unit_cost);

        $appended = $february->items()->where('id', '!=', $feb->id)->firstOrFail();

        $this->assertEquals(2000.0, (float) $appended->unit_cost);
    }

    public function test_editing_refuses_a_checked_month_without_a_matrix(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);

        $jan = $january->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openEditModal', 'item-'.$jan->id)
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->set('lineForm.unit_cost', '2000')
            ->set('lineRepeated', 'yes')
            // February has no matrix: adding the year is still the only path
            // that creates months.
            ->set('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasErrors(['lineMonths'])
            ->assertSet('showLineModal', true);

        // Refused before anything is written — the edit included.
        $this->assertSame(1, MonthlyExpenditureMatrix::count());
        $this->assertEquals(1500.0, (float) $jan->fresh()->unit_cost);
    }

    public function test_unchecking_the_edited_month_moves_the_line_out_of_it(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        $jan = $january->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
            'calculated_total' => 1500,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openEditModal', 'item-'.$jan->id)
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->set('lineRepeated', 'yes')
            // January is just a month like the rest: clearing it removes the
            // line from this month and leaves it only where still checked.
            ->set('lineMonths', [2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Line saved to Feb 2026. Removed from Jan 2026.');

        $this->assertSame(0, $january->items()->count());
        $this->assertSame(1, $february->items()->count());
        $this->assertEquals(1500.0, (float) $february->items()->firstOrFail()->unit_cost);
    }

    public function test_unchecking_a_month_removes_the_lines_copy_there(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);
        $march = $this->matrixFor($sh, month: 3);

        $jan = $january->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
            'calculated_total' => 1500,
        ]);
        $february->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
            'calculated_total' => 1500,
        ]);
        $march->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
            'calculated_total' => 1500,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openEditModal', 'item-'.$jan->id)
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->assertSet('lineMonths', [1, 2, 3])
            // Clearing March takes the line out of that month on save.
            ->set('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            // February keeps its own copy; only March loses the line.
            ->assertSet('successMessage', 'Line saved to Jan 2026. Removed from Mar 2026.');

        $this->assertSame(0, $march->items()->count());
        $this->assertSame(1, $february->items()->count());
        $this->assertSame(2, $march->fresh()->version);
    }

    public function test_unchecking_a_month_leaves_a_copy_changed_differently(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);
        $march = $this->matrixFor($sh, month: 3);

        $jan = $january->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
            'calculated_total' => 1500,
        ]);
        $february->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
            'calculated_total' => 1500,
        ]);
        // March's line started as a copy but was already changed there.
        $march->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 999,
            'calculated_total' => 999,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openEditModal', 'item-'.$jan->id)
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.account_title', 'Internet Expenses')
            // March is not a copy of this line any more, so it is not claimed
            // by the picker...
            ->assertSet('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            // ...and saving never deletes a line it does not claim. Nothing
            // outside this month was written, so nothing is announced.
            ->assertSet('successMessage', null);

        $this->assertSame(1, $march->items()->count());
        $this->assertEquals(999.0, (float) $march->items()->firstOrFail()->unit_cost);
        $this->assertSame(1, $march->fresh()->version);
    }

    public function test_editing_one_month_with_apply_to_all_leaves_every_other_month_untouched(): void
    {
        $sh = $this->schoolHead();
        $matrices = [];

        for ($month = 1; $month <= 12; $month++) {
            $matrices[$month] = $this->matrixFor($sh, month: $month);

            $matrices[$month]->items()->create([
                'position' => 1,
                'section' => 'operations',
                'activity_description' => 'Monthly internet',
                'quantity' => 1,
                'unit_cost' => 1500,
                'calculated_total' => 1500,
            ]);
        }

        $jan = $matrices[1]->items()->firstOrFail();

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrices[1]])
            ->call('openEditModal', 'item-'.$jan->id)
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.account_title', 'Internet Expenses')
            // The line lives in every month, so the picker opens with them
            // all checked...
            ->assertSet('lineMonths', [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12])
            ->set('lineForm.unit_cost', '2000')
            ->call('applyLineToAllMonths')
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            // ...but only January was written, so nothing is announced.
            ->assertSet('successMessage', null);

        $this->assertEquals(2000.0, (float) $matrices[1]->items()->firstOrFail()->unit_cost);
        $this->assertSame(2, $matrices[1]->fresh()->version);

        foreach ($matrices as $month => $matrix) {
            if ($month === 1) {
                continue;
            }

            // Every other month keeps its own copy of the line, untouched.
            $this->assertEquals(1500.0, (float) $matrix->items()->firstOrFail()->unit_cost);
            $this->assertSame(1, $matrix->fresh()->version);
        }
    }

    public function test_reopening_the_edit_modal_does_not_check_a_month_whose_copy_diverged(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        $jan = $january->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
            'calculated_total' => 1500,
        ]);
        $february->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 999,
            'calculated_total' => 999,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openEditModal', 'item-'.$jan->id)
            // February's line is no longer a copy of this one, so the edit
            // does not claim it — one month only, so not a repeated item.
            ->assertSet('lineRepeated', 'no')
            ->assertSet('lineMonths', [1]);
    }

    public function test_saving_an_unchanged_line_does_not_bump_the_other_months(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        $jan = $january->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
            'calculated_total' => 1500,
        ]);
        $february->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Monthly internet',
            'quantity' => 1,
            'unit_cost' => 1500,
            'calculated_total' => 1500,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openEditModal', 'item-'.$jan->id)
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->assertSet('lineMonths', [1, 2])
            // Save without touching a field: the reopened months are checked,
            // but February already matches, so it must not be rewritten.
            ->call('saveLineFromModal')
            ->assertHasNoErrors();

        $this->assertSame(2, $january->fresh()->version);
        $this->assertSame(1, $february->fresh()->version);
    }

    public function test_the_add_line_modal_requires_at_least_one_month(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh, month: 1);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Monthly internet')
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '1')
            ->set('lineForm.unit_cost', '1500')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->set('lineMonths', [])
            ->call('saveLineFromModal')
            ->assertHasErrors(['lineMonths'])
            ->assertSet('showLineModal', true);

        $this->assertSame(0, MonthlyExpenditureItem::count());
    }

    public function test_a_month_outside_the_calendar_is_rejected(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh, month: 1);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Monthly internet')
            ->set('lineForm.objective', 'Monthly connectivity')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '1')
            ->set('lineForm.unit_cost', '1500')
            ->set('lineForm.account_title', 'Internet Expenses')
            ->set('lineMonths', [13])
            ->call('saveLineFromModal')
            ->assertHasErrors(['lineMonths.0']);

        $this->assertSame(0, MonthlyExpenditureItem::count());
        $this->assertSame(1, MonthlyExpenditureMatrix::count());
    }


    public function test_each_resource_row_carries_its_own_account(): void
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

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Quarterly supplies')
            ->set('lineForm.objective', 'Stocked office')
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '10')
            ->set('lineForm.unit', 'piece')
            ->set('lineForm.unit_cost', '250')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
            ->call('addLineResource')
            ->set('lineResources.0.resource_kind', 'MOOE')
            ->set('lineResources.0.quantity', '4')
            ->set('lineResources.0.unit', 'piece')
            ->set('lineResources.0.unit_cost', '300')
            ->set('lineResources.0.account_title', 'Traveling Expenses')
            ->set('lineResources.0.account_code', '5020101000')
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('showLineModal', false);

        $items = MonthlyExpenditureItem::orderBy('id')->get();

        $this->assertCount(2, $items);
        $this->assertSame('Quarterly supplies', $items[0]->activity_description);
        $this->assertSame('Office Supplies Expenses', $items[0]->account_title);
        $this->assertSame('5020301000', $items[0]->account_code);
        $this->assertSame('Quarterly supplies', $items[1]->activity_description);
        $this->assertSame('Traveling Expenses', $items[1]->account_title);
        $this->assertSame('5020101000', $items[1]->account_code);
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
            ->set('items.0.section', 'operations')
            ->set('items.1.activity_description', 'Payment of school clerk')
            ->set('items.1.section', 'operations')
            ->set('items.2.activity_description', 'Build a fence')
            ->set('items.2.section', 'project')
            ->set('items.3.activity_description', 'Procure supplies')
            ->set('items.3.section', 'operations')
            // Persist the described rows and discard the blank one: only then
            // does each row have the stable key a drag addresses.
            ->call('addRow');

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

        $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->assertSeeHtml('wire:sort="reorderRow"')
            ->assertSeeHtml('wire:sort:item="item-')
            // Only the grip starts a drag, so the row's buttons still click.
            ->assertSeeHtml('wire:sort:handle');
    }

    public function test_the_grid_renders_one_grouped_block_per_section(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure supplies',
        ]);
        $matrix->items()->create([
            'position' => 2,
            'section' => 'project',
            'activity_description' => 'Build a fence',
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
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
            ->set('lineForm.objective', 'Office supplies for learners')
            ->set('lineForm.account_title', 'Office Supplies Expenses')
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
        $this->assertStringContainsString('Operations-Related Expenditures', $sheet);
        $this->assertStringContainsString('Project-Related Expenditures', $sheet);

        // The line itself, and the account it is charged to.
        $this->assertStringContainsString('Procure office supplies', $sheet);
        $this->assertStringContainsString('Office Supplies Expenses', $sheet);

        // 10 × 250, written as a real NUMBER (not text) the reviewer can sum.
        $this->assertStringContainsString('<v>2500</v>', $sheet);
        $this->assertStringContainsString('Subtotal', $sheet);
        $this->assertStringContainsString('TOTAL:', $sheet);

        // The form's four signatory blocks, in its own words.
        $this->assertStringContainsString('Prepared by:', $sheet);
        $this->assertStringContainsString('Checked as the Correctness of', $sheet);
        $this->assertStringContainsString('Account Titles and Codes:', $sheet);
        $this->assertStringContainsString('Certifying Availability of', $sheet);
        $this->assertStringContainsString('Allotment:', $sheet);
        $this->assertStringContainsString('Approved:', $sheet);
        $this->assertStringNotContainsString('Checked by the Completeness', $sheet);

        // The printed roles under each block. No check or certification is on
        // record for this school and year, so those two keep their blank line.
        $this->assertStringContainsString('School Principal I', $sheet);
        $this->assertStringContainsString('Senior Bookkeeper', $sheet);
        $this->assertStringContainsString('Budget Officer III', $sheet);
        $this->assertStringContainsString('Schools Division Superintendent', $sheet);

        // The signature block names the exporter as the prepared-by.
        $this->assertStringContainsString($sh->name, $sheet);

        unlink($path);
    }

    public function test_the_export_prints_the_bookkeeper_and_budget_officer_who_signed_off(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        // The line is written BEFORE the sign-offs: any MEM write clears the
        // report check, because the check no longer describes the lines.
        $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Procure office supplies',
            'quantity' => 1,
            'unit_cost' => 100,
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
        ]);

        $bookkeeper = User::factory()->create([
            'role' => 'bookkeeper',
            'is_superadmin' => false,
            'name' => 'Edilyn Lirazan',
            'position' => 'Senior Bookkeeper',
        ]);

        $budgetOfficer = User::factory()->create([
            'role' => 'budget',
            'is_superadmin' => false,
            'name' => 'Lyn Rose E. Otea',
            'position' => 'Budget Officer III',
        ]);

        WfpReportCheck::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'checked_by' => $bookkeeper->id,
            'checked_at' => now(),
        ]);

        SchoolAllotment::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'amount' => 50000,
            'certified_by' => $budgetOfficer->id,
            'certified_at' => now(),
        ]);

        $response = $this->actingAs($sh)
            ->get(route('wfp.matrices.export', ['matrix' => $matrix->id]));

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'mem-export-').'.xlsx';
        file_put_contents($path, $response->getContent());

        $sheet = (new PurePhpZipReader($path))->part('xl/worksheets/sheet1.xml');

        $this->assertNotNull($sheet);
        $this->assertStringContainsString('Edilyn Lirazan', $sheet);
        $this->assertStringContainsString('Lyn Rose E. Otea', $sheet);

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

    // ----------------------------------------------------------- over allotment

    public function test_a_save_that_crosses_the_allotment_pops_a_warning(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        SchoolAllotment::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'amount' => 5000,
        ]);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Payment for Electricity')
            ->set('lineForm.objective', 'Kept the lights on')
            ->set('lineForm.account_title', 'Electricity Expenses')
            ->set('lineForm.quantity', '1')
            ->set('lineForm.unit_cost', '6000')
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('warningMessage', fn ($value) => is_string($value) && $value !== '');

        // The toast names the figure it now stands at and the overshoot.
        $warning = (string) $component->get('warningMessage');

        $this->assertStringContainsString('6,000.00', $warning);
        $this->assertStringContainsString('1,000.00 over the 5,000.00 allotted budget', $warning);
    }

    public function test_a_save_within_the_allotment_pops_no_warning(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        SchoolAllotment::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'amount' => 50000,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Payment for Electricity')
            ->set('lineForm.objective', 'Kept the lights on')
            ->set('lineForm.account_title', 'Electricity Expenses')
            ->set('lineForm.quantity', '1')
            ->set('lineForm.unit_cost', '6000')
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('warningMessage', null);
    }

    public function test_a_save_pops_no_warning_when_no_allotment_is_set(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Payment for Electricity')
            ->set('lineForm.objective', 'Kept the lights on')
            ->set('lineForm.account_title', 'Electricity Expenses')
            ->set('lineForm.quantity', '1')
            ->set('lineForm.unit_cost', '6000')
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            // No ceiling set is not an overspend: the write records silently.
            ->assertSet('warningMessage', null);
    }

    public function test_copies_into_sibling_months_are_counted_before_the_warning(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $this->matrixFor($sh, month: 2);

        SchoolAllotment::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'amount' => 50000,
        ]);

        // 30,000 copied into each of two months: the first copy alone stays
        // under the ceiling, so only a warning reading the WHOLE write can
        // see the 60,000 the year now carries.
        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Payment for Electricity')
            ->set('lineForm.objective', 'Kept the lights on')
            ->set('lineForm.account_title', 'Electricity Expenses')
            ->set('lineForm.quantity', '1')
            ->set('lineForm.unit_cost', '30000')
            ->set('lineRepeated', 'yes')
            ->set('lineMonths', [1, 2])
            ->call('saveLineFromModal')
            ->assertHasNoErrors()
            ->assertSet('warningMessage', fn ($value) => is_string($value) && $value !== '');

        $warning = (string) $component->get('warningMessage');

        $this->assertStringContainsString('60,000.00', $warning);
        $this->assertStringContainsString('10,000.00 over the 50,000.00 allotted budget', $warning);
    }

    public function test_a_write_that_adds_no_spending_stays_silent_while_over(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $matrix->items()->create([
            'position' => 1,
            'section' => 'operations',
            'activity_description' => 'Payment for Electricity',
            'quantity' => 1,
            'unit_cost' => 6000,
        ]);

        SchoolAllotment::create([
            'school_id' => $sh->school_id,
            'year' => 2026,
            'amount' => 5000,
        ]);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix]);

        $key = $component->get('items.0._key');

        // The year is already over before this write; moving a line between
        // sections changes no money, so no fresh warning is warranted — the
        // strip at the top still reports the over state.
        $component->call('moveRow', $key)
            ->assertHasNoErrors()
            ->assertSet('warningMessage', null);
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
