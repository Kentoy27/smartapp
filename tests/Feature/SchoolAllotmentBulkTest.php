<?php

namespace Tests\Feature;

use App\Livewire\SchoolAllotmentManager;
use App\Models\District;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\SchoolAllotmentAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolAllotmentBulkTest extends TestCase
{
    use RefreshDatabase;

    private function school(string $name, string $schoolId): School
    {
        $district = District::create(['name' => $name.' District']);

        return School::create([
            'district_id' => $district->id,
            'name' => $name,
            'school_id' => $schoolId,
        ]);
    }

    private function budgetOfficer(): User
    {
        return User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);
    }

    /* ---------------------------------------------------------------- access */

    public function test_the_budget_office_sees_the_bulk_action_on_the_page(): void
    {
        $this->school('Bino Elementary School', '1234561');

        $this->actingAs($this->budgetOfficer())
            ->get(route('budget.allotments.index'))
            ->assertOk()
            ->assertSee('Bulk Set Allotments');
    }

    public function test_the_bulk_actions_refuse_a_guest_and_a_school_head(): void
    {
        $head = $this->school('Bino Elementary School', '1234561');
        $schoolHead = User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $head->id,
        ]);

        // The gate is the page's own: a guest and a School Head never reach
        // the component, so there is no bulk action for them to call.
        $this->assertStringContainsString(
            'Page Not Found',
            Livewire::test(SchoolAllotmentManager::class)->html()
        );

        $this->assertStringContainsString(
            'Page Not Found',
            Livewire::actingAs($schoolHead)->test(SchoolAllotmentManager::class)->html()
        );
    }

    /* --------------------------------------------------------------- preview */

    public function test_the_budget_office_reviews_a_pasted_list_before_anything_is_written(): void
    {
        $this->school('school1', '12345');
        $this->school('Bugtong Elementary School', '117833');

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->assertSet('showBulk', true)
            ->set('bulkText', "12345, 2000000.00\n117833, 1500000")
            ->call('reviewBulk');

        $component
            ->assertSet('bulkReadyCount', 2)
            ->assertSet('bulkInvalidCount', 0)
            ->assertSee('school1')
            ->assertSee('Bugtong Elementary School')
            ->assertSee('New allotment')
            ->assertSee('2,000,000.00');

        // Nothing is written until the apply is confirmed.
        $this->assertSame(0, SchoolAllotment::query()->count());
        $this->assertSame(0, SchoolAllotmentAudit::query()->count());
    }

    public function test_the_preview_flags_an_amount_change_that_ends_a_certification(): void
    {
        $school = $this->school('school1', '12345');
        $budget = $this->budgetOfficer();

        SchoolAllotment::create([
            'school_id' => $school->id,
            'year' => 2026,
            'amount' => 1000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        Livewire::actingAs($budget)
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->set('bulkText', '12345, 2000')
            ->call('reviewBulk')
            ->assertSee('Will update')
            ->assertSee('Clears its certification')
            ->assertSee('1 will lose certification')
            // The confirmation restates the consequence before it is applied.
            ->call('confirmBulk')
            ->assertSee('You are about to set or clear allotments for')
            ->assertSee('will lose the current certification and must');
    }

    public function test_the_preview_reports_a_school_that_does_not_exist(): void
    {
        $this->school('school1', '12345');

        Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->set('bulkText', "99999, 100\n12345, 500")
            ->call('reviewBulk')
            ->assertSet('bulkReadyCount', 1)
            ->assertSet('bulkInvalidCount', 1)
            ->assertSee('School not found.');
    }

    public function test_a_duplicate_school_id_keeps_the_last_occurrence_and_says_so(): void
    {
        $school = $this->school('school1', '12345');
        $budget = $this->budgetOfficer();

        Livewire::actingAs($budget)
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->set('bulkText', "12345, 100\n12345, 250")
            ->call('reviewBulk')
            ->assertSet('bulkReadyCount', 1)
            ->assertSet('bulkSupersededCount', 1)
            ->assertSee('Superseded by row 2')
            ->call('confirmBulk')
            ->call('commitBulk');

        $this->assertSame(1, SchoolAllotment::query()->count());
        $this->assertSame(250.0, (float) SchoolAllotment::query()->value('amount'));
        $this->assertSame(1, SchoolAllotmentAudit::query()->count());
    }

    /* ----------------------------------------------------------------- apply */

    public function test_the_apply_writes_every_confirmed_row_and_refreshes_the_page(): void
    {
        $school = $this->school('school1', '12345');
        $other = $this->school('Bugtong Elementary School', '117833');
        $budget = $this->budgetOfficer();

        $component = Livewire::actingAs($budget)
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->set('bulkText', "12345, 2000000.00\n117833, 1500000")
            ->call('reviewBulk')
            ->call('confirmBulk')
            ->assertSet('bulkApplyCount', 2)
            ->assertSee('You are about to set or clear allotments for')
            ->call('commitBulk')
            ->assertSet('showBulk', false)
            ->assertSet('amounts.'.$school->id, '2000000.00')
            ->assertSet('amounts.'.$other->id, '1500000.00');

        $this->assertSame(2, SchoolAllotment::query()->count());

        $audit = SchoolAllotmentAudit::query()->orderBy('id')->get();
        $this->assertCount(2, $audit);
        $this->assertSame(SchoolAllotmentAudit::SOURCE_BULK, $audit[0]->source);
        $this->assertSame($budget->id, $audit[0]->user_id);

        // The toast the page pops after the apply.
        $this->assertStringContainsString('Applied', (string) $component->get('successMessage'));
        $this->assertStringContainsString('2 set', (string) $component->get('successMessage'));
    }

    public function test_unchecking_a_row_leaves_that_school_untouched(): void
    {
        $kept = $this->school('school1', '12345');
        $skipped = $this->school('Bugtong Elementary School', '117833');

        Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->set('bulkText', "12345, 2000000.00\n117833, 1500000")
            ->call('reviewBulk')
            ->set('bulkIncluded.2', false)
            // The apply button counts what is left checked.
            ->assertSee('Apply 1 row(s)')
            ->call('confirmBulk')
            ->assertSet('bulkApplyCount', 1)
            ->call('commitBulk');

        $this->assertSame(2000000.0, (float) SchoolAllotment::query()->where('school_id', $kept->id)->value('amount'));
        $this->assertSame(0, SchoolAllotment::query()->where('school_id', $skipped->id)->count());
    }

    public function test_an_apply_is_refused_until_it_is_confirmed(): void
    {
        $school = $this->school('school1', '12345');

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->set('bulkText', '12345, 2000000.00')
            ->call('reviewBulk')
            ->call('commitBulk');

        $this->assertSame(0, SchoolAllotment::query()->count());
        $this->assertStringContainsString(
            'Confirm the bulk change',
            (string) $component->get('bulkError')
        );
    }

    public function test_a_blank_amount_clears_the_allotment_when_the_toggle_is_on(): void
    {
        $school = $this->school('school1', '12345');

        SchoolAllotment::create(['school_id' => $school->id, 'year' => 2026, 'amount' => 1000]);

        Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->set('bulkBlankClears', true)
            ->set('bulkText', '12345,')
            ->call('reviewBulk')
            ->assertSee('Will clear')
            ->call('confirmBulk')
            ->call('commitBulk');

        $this->assertSame(0, SchoolAllotment::query()->count());

        $audit = SchoolAllotmentAudit::query()->firstOrFail();

        $this->assertSame(SchoolAllotmentAudit::ACTION_CLEAR, $audit->action);
        $this->assertSame(SchoolAllotmentAudit::SOURCE_BULK, $audit->source);
    }

    public function test_a_blank_amount_is_refused_while_the_toggle_is_off(): void
    {
        $school = $this->school('school1', '12345');

        SchoolAllotment::create(['school_id' => $school->id, 'year' => 2026, 'amount' => 1000]);

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->set('bulkText', '12345,')
            ->call('reviewBulk')
            ->assertSet('bulkInvalidCount', 1)
            ->assertSee('Amount is required.')
            ->call('confirmBulk');

        $this->assertSame(1000.0, (float) SchoolAllotment::query()->value('amount'));
        $this->assertStringContainsString('nothing to apply', (string) $component->get('bulkError'));
    }

    public function test_a_csv_file_can_be_uploaded_and_applied(): void
    {
        $school = $this->school('school1', '12345');

        Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->set('bulkMode', 'csv')
            ->set('bulkFile', UploadedFile::fake()->createWithContent(
                'allotments.csv',
                "school_id,allotted_budget,notes\n12345,2000000.00,first tranche\n",
            ))
            ->call('reviewBulk')
            ->assertSet('bulkReadyCount', 1)
            ->call('confirmBulk')
            ->call('commitBulk');

        $this->assertSame(2000000.0, (float) SchoolAllotment::query()->where('school_id', $school->id)->value('amount'));
    }

    public function test_a_partial_apply_shows_the_report_and_offers_it_as_a_download(): void
    {
        $this->school('school1', '12345');

        $component = Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->set('bulkText', "99999, 100\n12345, 500")
            ->call('reviewBulk')
            ->call('confirmBulk')
            ->call('commitBulk');

        // The successful row landed; the report stays open for the failed one.
        $this->assertSame(1, SchoolAllotment::query()->count());

        $component
            ->assertSet('showBulk', true)
            ->assertSee('School not found.')
            ->assertSee('1 not applied')
            ->call('downloadBulkErrorReport')
            ->assertFileDownloaded('school-allotments-bulk-errors-cy2026.csv');

        $this->assertStringContainsString(
            '1 row(s) could not be applied',
            (string) $component->get('successMessage')
        );
    }

    /* ------------------------------------------------------------ the year */

    public function test_the_apply_writes_against_the_selected_year_only(): void
    {
        $school = $this->school('school1', '12345');

        SchoolAllotment::create(['school_id' => $school->id, 'year' => 2026, 'amount' => 100]);

        Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2027')
            ->call('openBulk')
            ->set('bulkText', '12345, 900')
            ->call('reviewBulk')
            ->call('confirmBulk')
            ->call('commitBulk');

        $this->assertSame(100.0, (float) SchoolAllotment::query()->where('year', 2026)->value('amount'));
        $this->assertSame(900.0, (float) SchoolAllotment::query()->where('year', 2027)->value('amount'));
    }

    public function test_changing_the_year_discards_an_open_bulk_preview(): void
    {
        $this->school('school1', '12345');

        Livewire::actingAs($this->budgetOfficer())
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->call('openBulk')
            ->set('bulkText', '12345, 500')
            ->call('reviewBulk')
            ->assertSet('bulkReadyCount', 1)
            ->set('year', '2027')
            ->assertSet('showBulk', false)
            ->assertSet('bulkRows', [])
            ->assertSet('bulkText', '');
    }

    public function test_the_single_row_save_still_writes_its_own_audit_entry(): void
    {
        $school = $this->school('school1', '12345');
        $budget = $this->budgetOfficer();

        $component = Livewire::actingAs($budget)
            ->test(SchoolAllotmentManager::class)
            ->set('year', '2026')
            ->set('amounts.'.$school->id, '1500')
            ->call('save', $school->id);

        $audit = SchoolAllotmentAudit::query()->firstOrFail();

        $this->assertSame(SchoolAllotmentAudit::ACTION_SET, $audit->action);
        $this->assertSame(SchoolAllotmentAudit::SOURCE_SINGLE, $audit->source);
        $this->assertNull($audit->previous_amount);
        $this->assertSame(1500.0, (float) $audit->amount);

        // Re-stating the same figure adds nothing to the trail.
        $component
            ->set('amounts.'.$school->id, '1500.00')
            ->call('save', $school->id);

        $this->assertSame(1, SchoolAllotmentAudit::query()->count());

        // Clearing it does.
        $component
            ->set('amounts.'.$school->id, '')
            ->call('save', $school->id);

        $this->assertSame(2, SchoolAllotmentAudit::query()->count());
        $this->assertSame(
            SchoolAllotmentAudit::ACTION_CLEAR,
            SchoolAllotmentAudit::query()->orderByDesc('id')->value('action')
        );
    }
}
