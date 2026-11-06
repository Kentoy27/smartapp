<?php

namespace Tests\Feature;

use App\Livewire\MemEditor;
use App\Models\District;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Add-a-line duplicate checker: the endpoint the suggestion panel calls
 * and the save gate behind "Add separately anyway".
 *
 * The monthly matrix is the whole boundary. Every test that proves isolation
 * (other month, other school) is the point of the feature, not a corner.
 */
class MemDuplicateTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function lineFor(MonthlyExpenditureMatrix $matrix, string $description, array $overrides = []): MonthlyExpenditureItem
    {
        return $matrix->items()->create(array_merge([
            'position' => (int) ($matrix->items()->max('position') ?? 0) + 1,
            'section' => 'operations',
            'activity_description' => $description,
        ], $overrides));
    }

    /**
     * @param  array<string, string>  $params
     */
    private function checkUrl(MonthlyExpenditureMatrix $matrix, array $params = []): string
    {
        return route('wfp.matrices.duplicate-check', array_merge(['matrix' => $matrix->id], $params));
    }

    /* ------------------------------------------------------------ endpoint */

    public function test_the_check_returns_an_exact_match_with_its_context(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $line = $this->lineFor($matrix, 'Procure office supplies', [
            'objective' => 'Ensure availability of office supplies',
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
            'funding_source' => 'MOOE',
            'resource_kind' => 'MOOE',
            'quantity' => 10,
            'unit' => 'ream',
            'unit_cost' => 250,
        ]);

        // The same text with different case and spacing is still exact.
        $this->actingAs($sh)
            ->getJson($this->checkUrl($matrix, ['q' => '  PROCURE   office supplies ']))
            ->assertOk()
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.id', $line->id)
            ->assertJsonPath('matches.0.match_type', 'exact')
            ->assertJsonPath('matches.0.description', 'Procure office supplies')
            ->assertJsonPath('matches.0.objective', 'Ensure availability of office supplies')
            ->assertJsonPath('matches.0.account_title', 'Office Supplies Expenses')
            ->assertJsonPath('matches.0.account_code', '5020301000')
            ->assertJsonPath('matches.0.funding_source', 'MOOE')
            ->assertJsonPath('matches.0.resource_kind', 'MOOE')
            ->assertJsonPath('matches.0.quantity', '10')
            ->assertJsonPath('matches.0.unit', 'ream')
            ->assertJsonPath('matches.0.unit_cost', '250');
    }

    public function test_a_wording_variation_is_returned_as_a_similar_match(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $line = $this->lineFor($matrix, 'Procurement of office supplies');

        $this->actingAs($sh)
            ->getJson($this->checkUrl($matrix, ['q' => 'Procure office supplies']))
            ->assertOk()
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.id', $line->id)
            ->assertJsonPath('matches.0.match_type', 'similar');
    }

    public function test_exact_matches_rank_before_similar_ones(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $similar = $this->lineFor($matrix, 'Procurement of office supplies');
        $exact = $this->lineFor($matrix, 'Procure office supplies');

        $this->actingAs($sh)
            ->getJson($this->checkUrl($matrix, ['q' => 'Procure office supplies']))
            ->assertOk()
            ->assertJsonCount(2, 'matches')
            ->assertJsonPath('matches.0.id', $exact->id)
            ->assertJsonPath('matches.0.match_type', 'exact')
            ->assertJsonPath('matches.1.id', $similar->id)
            ->assertJsonPath('matches.1.match_type', 'similar');
    }

    public function test_an_unrelated_description_finds_nothing(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $this->lineFor($matrix, 'Repair the school roof');

        $this->actingAs($sh)
            ->getJson($this->checkUrl($matrix, ['q' => 'Procure office supplies']))
            ->assertOk()
            ->assertJsonCount(0, 'matches');
    }

    public function test_blank_and_too_short_queries_find_nothing(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $this->lineFor($matrix, 'Procure office supplies');

        foreach (['', '  ', 'pr', 'oc'] as $query) {
            $this->actingAs($sh)
                ->getJson($this->checkUrl($matrix, ['q' => $query]))
                ->assertOk()
                ->assertJsonCount(0, 'matches');
        }
    }

    public function test_an_overlong_query_is_capped_rather_than_refused(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $this->lineFor($matrix, 'Procure office supplies');

        $this->actingAs($sh)
            ->getJson($this->checkUrl($matrix, ['q' => str_repeat('office ', 500)]))
            ->assertOk()
            ->assertJsonCount(0, 'matches');
    }

    public function test_the_line_being_edited_is_excluded_from_its_own_check(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $line = $this->lineFor($matrix, 'Procure office supplies');

        $this->actingAs($sh)
            ->getJson($this->checkUrl($matrix, ['q' => 'Procure office supplies', 'exclude' => (string) $line->id]))
            ->assertOk()
            ->assertJsonCount(0, 'matches');

        // A non-numeric exclude is ignored, not an error.
        $this->actingAs($sh)
            ->getJson($this->checkUrl($matrix, ['q' => 'Procure office supplies', 'exclude' => 'abc']))
            ->assertOk()
            ->assertJsonCount(1, 'matches');
    }

    public function test_at_most_five_matches_are_returned(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        for ($i = 0; $i < 7; $i++) {
            $this->lineFor($matrix, 'Procure office supplies');
        }

        $this->actingAs($sh)
            ->getJson($this->checkUrl($matrix, ['q' => 'Procure office supplies']))
            ->assertOk()
            ->assertJsonCount(5, 'matches');
    }

    /* ------------------------------------------------------------- isolation */

    public function test_another_months_matrix_is_never_searched(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        $this->lineFor($february, 'Procure office supplies');

        // January does not see February's line…
        $this->actingAs($sh)
            ->getJson($this->checkUrl($january, ['q' => 'Procure office supplies']))
            ->assertOk()
            ->assertJsonCount(0, 'matches');

        // …and February sees its own.
        $this->actingAs($sh)
            ->getJson($this->checkUrl($february, ['q' => 'Procure office supplies']))
            ->assertOk()
            ->assertJsonCount(1, 'matches');
    }

    public function test_another_schools_matrix_is_refused_and_its_lines_never_leak(): void
    {
        $owner = $this->schoolHead('School A');
        $other = $this->schoolHead('School B');

        $theirMatrix = $this->matrixFor($other);
        $this->lineFor($theirMatrix, 'Procure office supplies');

        // The other school's matrix id is not even a door.
        $this->actingAs($owner)
            ->getJson($this->checkUrl($theirMatrix, ['q' => 'Procure office supplies']))
            ->assertNotFound();

        // The owner's own matrix never sees the other school's line.
        $ownMatrix = $this->matrixFor($owner);

        $this->actingAs($owner)
            ->getJson($this->checkUrl($ownMatrix, ['q' => 'Procure office supplies']))
            ->assertOk()
            ->assertJsonCount(0, 'matches');
    }

    public function test_the_check_needs_a_signed_in_school_head(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $this->getJson($this->checkUrl($matrix, ['q' => 'Procure office supplies']))
            ->assertUnauthorized();

        // A superadmin does not work the SH's matrix pages at all.
        $superadmin = User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);

        $this->actingAs($superadmin)
            ->getJson($this->checkUrl($matrix, ['q' => 'Procure office supplies']))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------- the gate */

    public function test_an_exact_duplicate_is_refused_once_with_the_match_shown(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $existing = $this->lineFor($matrix, 'Procure office supplies', [
            'objective' => 'Ensure availability of office supplies',
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'PROCURE  office supplies')
            ->call('saveLineFromModal')
            ->assertSet('showLineModal', true)
            ->assertSet('duplicateWarning.id', $existing->id)
            ->assertSet('duplicateWarning.match_type', 'exact')
            ->assertSee('An entry with this exact description already exists')
            ->assertSee('Add separately anyway');

        // Nothing was written; the line is not lost, only held back.
        $this->assertSame(1, MonthlyExpenditureItem::count());
    }

    public function test_the_warning_can_be_dismissed_to_keep_editing(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $this->lineFor($matrix, 'Procure office supplies');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->call('saveLineFromModal')
            ->assertSet('duplicateWarning.id', MonthlyExpenditureItem::firstOrFail()->id)
            ->call('dismissDuplicateWarning')
            ->assertSet('duplicateWarning', null)
            ->assertSet('showLineModal', true);
    }

    public function test_add_separately_anyway_saves_the_separate_entry(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $this->lineFor($matrix, 'Procure office supplies');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->set('lineForm.quantity', '5')
            ->call('saveLineFromModal')
            ->assertSet('duplicateWarning.id', MonthlyExpenditureItem::firstOrFail()->id)
            // The warning's own button confirms and saves in one step.
            ->call('confirmDuplicateAndSave')
            ->assertSet('showLineModal', false)
            ->assertSet('duplicateWarning', null)
            ->assertHasNoErrors();

        $this->assertSame(2, MonthlyExpenditureItem::count());
        $this->assertSame(
            2,
            MonthlyExpenditureItem::where('activity_description', 'Procure office supplies')->count()
        );
    }

    public function test_the_panels_add_separately_confirmation_lets_the_save_through(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $this->lineFor($matrix, 'Procure office supplies');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->call('saveLineFromModal')
            ->assertSet('duplicateWarning.id', MonthlyExpenditureItem::firstOrFail()->id)
            // The panel passes the text it was showing.
            ->call('confirmAddSeparate', 'Procure office supplies')
            ->assertSet('duplicateWarning', null)
            ->call('saveLineFromModal')
            ->assertSet('showLineModal', false)
            ->assertHasNoErrors();

        $this->assertSame(2, MonthlyExpenditureItem::count());
    }

    public function test_a_confirmation_never_carries_over_to_a_different_description(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $supplies = $this->lineFor($matrix, 'Procure office supplies');
        $roof = $this->lineFor($matrix, 'Repair the school roof');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->call('saveLineFromModal')
            ->assertSet('duplicateWarning.id', $supplies->id)
            ->call('confirmAddSeparate', 'Procure office supplies')
            // A different description with its own exact match: the earlier
            // confirmation must not cover it.
            ->set('lineForm.activity_description', 'Repair the school roof')
            ->call('saveLineFromModal')
            ->assertSet('duplicateWarning.id', $roof->id)
            ->assertSet('showLineModal', true);

        $this->assertSame(2, MonthlyExpenditureItem::count());
    }

    public function test_a_stale_confirmation_for_other_text_is_ignored(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $this->lineFor($matrix, 'Procure office supplies');

        // A crafted call confirming text the field does not hold must not
        // authorise anything.
        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->call('confirmAddSeparate', 'Something else entirely')
            ->call('saveLineFromModal')
            ->assertSet('duplicateWarning.id', MonthlyExpenditureItem::firstOrFail()->id)
            ->assertSet('showLineModal', true);

        $this->assertSame(1, MonthlyExpenditureItem::count());
    }

    public function test_a_similar_match_never_blocks_a_save(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $this->lineFor($matrix, 'Procurement of office supplies');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->call('saveLineFromModal')
            ->assertSet('showLineModal', false)
            ->assertSet('duplicateWarning', null)
            ->assertHasNoErrors();

        $this->assertSame(2, MonthlyExpenditureItem::count());
    }

    public function test_a_fresh_description_saves_normally(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $this->lineFor($matrix, 'Repair the school roof');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->call('saveLineFromModal')
            ->assertSet('showLineModal', false)
            ->assertSet('duplicateWarning', null)
            ->assertHasNoErrors();

        $this->assertSame(2, MonthlyExpenditureItem::count());
    }

    public function test_a_duplicate_in_another_month_does_not_block_the_save(): void
    {
        $sh = $this->schoolHead();
        $january = $this->matrixFor($sh, month: 1);
        $february = $this->matrixFor($sh, month: 2);

        $this->lineFor($february, 'Procure office supplies');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $january])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->call('saveLineFromModal')
            ->assertSet('showLineModal', false)
            ->assertSet('duplicateWarning', null)
            ->assertHasNoErrors();

        $this->assertSame(1, $january->items()->count());
        $this->assertSame(1, $february->items()->count());
    }

    public function test_the_save_gate_rechecks_at_submission_time_not_from_the_browser(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $component = Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies');

        // A second user (another tab, another account of the same school)
        // saves the same activity while this modal is open. The browser never
        // saw it — the submit-time check must.
        $theirs = $this->lineFor($matrix, 'Procure office supplies');

        $component->call('saveLineFromModal')
            ->assertSet('showLineModal', true)
            ->assertSet('duplicateWarning.id', $theirs->id)
            ->assertSet('duplicateWarning.match_type', 'exact');

        $this->assertSame(1, MonthlyExpenditureItem::count());
    }

    public function test_editing_a_line_never_warns_about_itself(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $line = $this->lineFor($matrix, 'Procure office supplies', ['quantity' => 10]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openEditModal', 'item-'.$line->id)
            ->set('lineForm.quantity', '12')
            ->call('saveLineFromModal')
            ->assertSet('showLineModal', false)
            ->assertSet('duplicateWarning', null)
            ->assertHasNoErrors();

        $this->assertSame(1, MonthlyExpenditureItem::count());
        $this->assertSame(12.0, (float) $line->fresh()->quantity);
    }

    public function test_closing_and_reopening_the_modal_forgets_the_warning(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $this->lineFor($matrix, 'Procure office supplies');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->set('lineForm.activity_description', 'Procure office supplies')
            ->call('saveLineFromModal')
            ->assertSet('duplicateWarning.id', MonthlyExpenditureItem::firstOrFail()->id)
            ->call('closeLineModal')
            ->assertSet('duplicateWarning', null)
            ->assertSet('duplicateConfirmedFor', null)
            ->assertSet('reusedFromItemId', null)
            ->call('openLineModal')
            ->assertSet('duplicateWarning', null)
            ->assertSet('duplicateConfirmedFor', null);
    }

    /* -------------------------------------------------------- reuse (action) */

    public function test_use_existing_activity_fills_the_activity_and_keeps_the_resource(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $existing = $this->lineFor($matrix, 'Procure office supplies', [
            'objective' => 'Ensure availability of office supplies',
            'account_title' => 'Office Supplies Expenses',
            'account_code' => '5020301000',
            'funding_source' => 'MOOE',
            'major_output' => 'Supplies procured',
            'activity_movs' => 'PO and RIS',
            'quantity' => 10,
            'unit' => 'ream',
            'unit_cost' => 250,
        ]);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            // The new resource being typed…
            ->set('lineForm.resource_kind', 'MOOE')
            ->set('lineForm.quantity', '4')
            ->set('lineForm.unit', 'box')
            ->set('lineForm.unit_cost', '120')
            ->call('useExistingActivity', $existing->id)
            // …stays exactly as typed; only the activity identity is copied.
            ->assertSet('lineForm.activity_description', 'Procure office supplies')
            ->assertSet('lineForm.objective', 'Ensure availability of office supplies')
            ->assertSet('lineForm.account_title', 'Office Supplies Expenses')
            ->assertSet('lineForm.account_code', '5020301000')
            ->assertSet('lineForm.funding_source', 'MOOE')
            ->assertSet('lineForm.major_output', 'Supplies procured')
            ->assertSet('lineForm.activity_movs', 'PO and RIS')
            ->assertSet('lineForm.resource_kind', 'MOOE')
            ->assertSet('lineForm.quantity', '4')
            ->assertSet('lineForm.unit', 'box')
            ->assertSet('lineForm.unit_cost', '120')
            ->assertSet('reusedFromItemId', $existing->id)
            ->assertSee('Reusing the existing activity’s details')
            // Reuse is the deliberate engagement the gate wants: the save
            // goes through without a second confirmation.
            ->call('saveLineFromModal')
            ->assertSet('showLineModal', false)
            ->assertSet('duplicateWarning', null)
            ->assertHasNoErrors();

        // The saved entry is untouched; the new line shares its activity.
        $existing->refresh();
        $this->assertSame('Ensure availability of office supplies', $existing->objective);
        $this->assertSame(10.0, (float) $existing->quantity);
        $this->assertSame(250.0, (float) $existing->unit_cost);

        $this->assertSame(2, MonthlyExpenditureItem::count());

        $added = MonthlyExpenditureItem::whereKeyNot($existing->id)->firstOrFail();
        $this->assertSame('Procure office supplies', $added->activity_description);
        $this->assertSame('Ensure availability of office supplies', $added->objective);
        $this->assertSame('5020301000', $added->account_code);
        $this->assertSame('box', $added->unit);
        $this->assertSame(4.0, (float) $added->quantity);
        $this->assertEquals(480.0, (float) $added->calculated_total);
    }

    public function test_use_existing_activity_switches_the_section_to_the_matched_line(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $existing = $this->lineFor($matrix, 'Conduct reading camp', ['section' => 'project']);

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal', 'operations')
            ->call('useExistingActivity', $existing->id)
            ->assertSet('lineSection', 'project')
            ->assertSet('lineForm.section', 'project')
            ->assertSet('lineForm.activity_description', 'Conduct reading camp');
    }

    public function test_a_line_of_another_matrix_can_never_be_reused(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh, month: 1);
        $other = $this->matrixFor($sh, month: 2);

        $theirs = $this->lineFor($other, 'Procure office supplies');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->call('useExistingActivity', $theirs->id)
            ->assertStatus(404);
    }

    /* -------------------------------------------------------------- markup */

    public function test_the_modal_renders_the_duplicate_panel_wired_to_this_matrix(): void
    {
        $sh = $this->schoolHead();
        $matrix = $this->matrixFor($sh);

        $line = $this->lineFor($matrix, 'Procure office supplies');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openLineModal')
            ->assertSee('duplicate-check', false)
            ->assertSee('Similar activity found in this monthly matrix', false)
            ->assertSee('mem-dup__panel', false)
            // A new line excludes nothing; the edited line excludes itself.
            ->assertSeeHtml('data-exclude="0"');

        Livewire::actingAs($sh)->test(MemEditor::class, ['matrix' => $matrix])
            ->call('openEditModal', 'item-'.$line->id)
            ->assertSeeHtml('data-exclude="'.$line->id.'"');
    }
}
