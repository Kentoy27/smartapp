<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Livewire\WfpLineTemplatesTable;
use App\Livewire\WfpLookupTable;
use App\Models\WfpFundingSource;
use App\Models\WfpImportLog;
use App\Models\WfpLineTemplate;
use App\Models\WfpLookupAudit;
use App\Models\WfpOrgClassification;
use App\Models\WfpResourceKind;
use App\Models\WfpUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The WFP Setup lookup library: administrator-managed master data and the
 * activity templates the Add a Line form draws from.
 */
class WfpSetupLookupTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        return User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);
    }

    private function superadmin(): User
    {
        return User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);
    }

    private function makeFundingSource(array $overrides = []): WfpFundingSource
    {
        return WfpFundingSource::create(array_merge([
            'code' => 'MOOE',
            'name' => 'Maintenance and Other Operating Expenses',
            'description' => 'Regular school fund',
            'is_active' => true,
        ], $overrides));
    }

    // ---------------------------------------------------------------- access

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('wfp.setup.index'))->assertRedirect(route('login'));
    }

    public function test_only_the_administrator_can_open_the_setup_pages(): void
    {
        $this->actingAs($this->administrator())
            ->get(route('wfp.setup.index'))
            ->assertOk();

        $this->actingAs($this->administrator())
            ->get(route('wfp.setup.lookups', ['category' => 'funding-sources']))
            ->assertOk()
            ->assertSeeLivewire('wfp-lookup-table');

        $this->actingAs($this->administrator())
            ->get(route('wfp.setup.templates'))
            ->assertOk()
            ->assertSeeLivewire('wfp-line-templates-table');

        // The module belongs to the Administrator side, so a superadmin is
        // refused exactly like any other non-administrator account.
        $this->actingAs($this->superadmin())
            ->get(route('wfp.setup.lookups', ['category' => 'funding-sources']))
            ->assertNotFound();
    }

    public function test_staff_and_viewer_cannot_open_the_pages(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_superadmin' => false]);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);

        $this->actingAs($staff)->get(route('wfp.setup.lookups', ['category' => 'funding-sources']))->assertNotFound();
        $this->actingAs($viewer)->get(route('wfp.setup.templates'))->assertNotFound();
    }

    public function test_the_component_rechecks_administrative_access_on_mount(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_superadmin' => false]);

        foreach ([$staff, $this->superadmin()] as $refused) {
            $html = Livewire::actingAs($refused)->test('wfp-lookup-table', ['category' => 'funding-sources'])->html();

            $this->assertStringContainsString('Page Not Found', $html);
        }
    }

    public function test_unknown_category_is_a_404(): void
    {
        $this->actingAs($this->administrator())
            ->get(route('wfp.setup.lookups', ['category' => 'nothing-such']))
            ->assertNotFound();
    }

    public function test_sidebar_shows_wfp_setup_for_the_administrator_only(): void
    {
        Livewire::actingAs($this->administrator())->test(Sidebar::class)->assertSee('WFP Setup');

        Livewire::actingAs($this->superadmin())->test(Sidebar::class)->assertDontSee('WFP Setup');

        Livewire::actingAs(User::factory()->create(['role' => 'user', 'is_superadmin' => false]))
            ->test(Sidebar::class)
            ->assertDontSee('WFP Setup');
    }

    public function test_template_download_returns_a_csv_for_admins(): void
    {
        $this->actingAs($this->administrator())
            ->get(route('wfp.setup.lookups.template', ['category' => 'funding-sources']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertSee('Code,Name,Description,Active', false)
            ->assertSee('MOOE,Maintenance and Other Operating Expenses,Regular school fund,Yes', false);

        $this->actingAs($this->superadmin())
            ->get(route('wfp.setup.lookups.template', ['category' => 'funding-sources']))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- read

    public function test_search_narrows_the_table(): void
    {
        $admin = $this->administrator();
        $this->makeFundingSource(['name' => 'Maintenance and Other Operating Expenses']);
        $this->makeFundingSource(['code' => 'SEF', 'name' => 'Special Education Fund']);

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->set('search', 'Special Education')
            ->assertSee('Special Education Fund')
            ->assertDontSee('Maintenance and Other Operating Expenses');
    }

    public function test_status_filter_separates_active_and_inactive(): void
    {
        $admin = $this->administrator();
        $this->makeFundingSource(['name' => 'Active One', 'is_active' => true]);
        $this->makeFundingSource(['code' => 'RET', 'name' => 'Retired One', 'is_active' => false]);

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->set('status', 'inactive')
            ->assertSee('Retired One')
            ->assertDontSee('Active One');

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->set('status', 'active')
            ->assertSee('Active One')
            ->assertDontSee('Retired One');
    }

    // ---------------------------------------------------------------- create

    public function test_create_validates_required_fields(): void
    {
        $admin = $this->administrator();

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openCreate')
            ->set('form.name', '')
            ->set('form.code', '')
            ->call('save')
            ->assertHasErrors(['form.name' => 'required'])
            ->assertSet('showForm', true);

        $this->assertSame(0, WfpFundingSource::count());
    }

    public function test_create_trims_whitespace_and_keeps_codes_as_strings(): void
    {
        $admin = $this->administrator();

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openCreate')
            ->set('form.name', '  Maintenance and Other Operating Expenses  ')
            ->set('form.code', '  MOOE  ')
            ->set('form.description', '  Regular school fund  ')
            ->call('save')
            ->assertSet('showForm', false)
            ->assertHasNoErrors();

        $record = WfpFundingSource::firstOrFail();

        $this->assertSame('Maintenance and Other Operating Expenses', $record->name);
        $this->assertSame('Regular school fund', $record->description);
        // Codes keep leading zeroes — stored exactly as typed.
        $this->assertSame('MOOE', $record->code);
        $this->assertTrue($record->is_active);
    }

    public function test_a_duplicate_is_prevented_by_name_or_code(): void
    {
        $admin = $this->administrator();
        $this->makeFundingSource();

        // Same name, different case → duplicate.
        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openCreate')
            ->set('form.name', 'maintenance and other operating expenses')
            ->set('form.code', 'OTHERCODE')
            ->call('save')
            ->assertHasErrors(['form.name']);

        $this->assertSame(1, WfpFundingSource::count());

        // Same code, different name → duplicate.
        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openCreate')
            ->set('form.name', 'Something Else')
            ->set('form.code', 'MOOE')
            ->call('save')
            ->assertHasErrors(['form.name']);

        $this->assertSame(1, WfpFundingSource::count());
    }

    // ---------------------------------------------------------------- update

    public function test_edit_prefills_and_updates_without_self_conflict(): void
    {
        $admin = $this->administrator();
        $record = $this->makeFundingSource();

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openEdit', $record->id)
            ->assertSet('form.name', 'Maintenance and Other Operating Expenses')
            ->set('form.name', 'Maintenance and Other Operating Expense')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertSame('Maintenance and Other Operating Expense', $record->fresh()->name);
        $this->assertSame(1, WfpFundingSource::count());
    }

    // ---------------------------------------------------------------- delete

    public function test_delete_deactivates_rather_than_removing_the_row(): void
    {
        $admin = $this->administrator();
        $record = $this->makeFundingSource();

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openConfirmDelete', $record->id)
            ->assertSet('showConfirm', true)
            ->call('confirmDelete')
            ->assertSet('showConfirm', false);

        $this->assertDatabaseHas('wfp_funding_sources', [
            'id' => $record->id,
            'is_active' => false,
        ]);
    }

    public function test_an_inactive_record_can_be_reactivated(): void
    {
        $admin = $this->administrator();
        $record = $this->makeFundingSource(['is_active' => false]);

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('activate', $record->id);

        $this->assertTrue($record->fresh()->is_active);
    }

    // ----------------------------------------------------------------- audit

    public function test_administrative_changes_are_audited(): void
    {
        $admin = $this->administrator();

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openCreate')
            ->set('form.name', 'Audited Source')
            ->set('form.code', 'AUDIT1')
            ->call('save');

        $record = WfpFundingSource::firstOrFail();

        $this->assertSame(1, WfpLookupAudit::where('action', WfpFundingSource::AUDIT_CREATE)->count());
        $this->assertSame($admin->id, WfpLookupAudit::first()->user_id);
        $this->assertSame('funding_source', WfpLookupAudit::first()->auditable_type);

        $component = Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources']);
        $component->call('openConfirmDelete', $record->id)->call('confirmDelete');

        $this->assertSame(1, WfpLookupAudit::where('action', WfpFundingSource::AUDIT_DEACTIVATE)->count());
    }

    // ------------------------------------------------------------------ import

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('funding-sources.csv', $content);
    }

    public function test_import_previews_then_commits_valid_rows(): void
    {
        $admin = $this->administrator();

        $csv = implode("\n", [
            'Code,Name,Description,Active',
            'SEF,Special Education Fund,Special education levy,Yes',
            'PTA,PTA Fund,Parent-teacher association funds,Yes',
        ]);

        $component = Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openImport')
            ->set('importFile', $this->csv($csv))
            ->assertSet('importValidCount', 2)
            ->assertSet('importInvalidCount', 0)
            ->assertSet('importError', null);

        // Nothing is written until the import is confirmed.
        $this->assertSame(0, WfpFundingSource::count());

        $component->call('commitImport')
            ->assertSet('showImport', false)
            ->assertSet('successMessage', 'Import complete — 2 inserted, 0 updated, 0 skipped, 0 failed.');

        $this->assertSame(2, WfpFundingSource::count());
        // Codes keep their leading zeroes.
        $this->assertDatabaseHas('wfp_funding_sources', [
            'code' => 'SEF',
            'name' => 'Special Education Fund',
        ]);
    }

    public function test_import_skips_invalid_rows_and_reports_them(): void
    {
        $admin = $this->administrator();

        $csv = implode("\n", [
            'Code,Name,Description,Active',
            'GF,General Fund,General fund,Yes',
            ',,,Yes', // missing name
        ]);

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openImport')
            ->set('importFile', $this->csv($csv))
            ->assertSet('importValidCount', 1)
            ->assertSet('importInvalidCount', 1)
            ->assertSee('Name is required')
            ->call('commitImport');

        $this->assertSame(1, WfpFundingSource::count());
        $this->assertDatabaseHas('wfp_funding_sources', ['name' => 'General Fund']);
    }

    public function test_import_flags_rows_that_already_exist_in_insert_mode(): void
    {
        $admin = $this->administrator();
        $this->makeFundingSource(); // MOOE / Maintenance and Other Operating Expenses

        $csv = implode("\n", [
            'Code,Name,Description,Active',
            'MOOE,Maintenance and Other Operating Expenses,Regular school fund,Yes',
        ]);

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openImport')
            ->set('importFile', $this->csv($csv))
            ->assertSet('importValidCount', 0)
            ->assertSet('importInvalidCount', 0)
            ->assertSet('importSkippedCount', 1)
            ->assertSee('Skip');

        $this->assertSame(1, WfpFundingSource::count());
    }

    public function test_import_updates_matching_records_in_update_mode(): void
    {
        $admin = $this->administrator();
        $this->makeFundingSource(['name' => 'Old Name']);

        $csv = implode("\n", [
            'Code,Name,Description,Active',
            'MOOE,New Name,Updated description,Yes',
        ]);

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openImport')
            ->set('importMode', 'update')
            ->set('importFile', $this->csv($csv))
            ->assertSet('importValidCount', 1)
            ->call('commitImport');

        $this->assertSame(1, WfpFundingSource::count());
        $this->assertDatabaseHas('wfp_funding_sources', ['name' => 'New Name']);
    }

    public function test_import_error_report_is_downloadable(): void
    {
        $admin = $this->administrator();

        $csv = implode("\n", [
            'Code,Name,Description,Active',
            ',Bad Row,,Yes', // missing name → fails
        ]);

        Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => 'funding-sources'])
            ->call('openImport')
            ->set('importFile', $this->csv($csv))
            ->call('commitImport');

        $log = WfpImportLog::first();
        $this->assertNotNull($log);
        $this->assertSame(1, $log->failed);

        $this->actingAs($admin)
            ->get(route('wfp.setup.imports.errors', ['log' => $log->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertSee('Bad Row', false);
    }

    public function test_each_lookup_category_renders_for_the_administrator(): void
    {
        $admin = $this->administrator();

        foreach (['funding-sources', 'units', 'resource-kinds', 'org-classifications'] as $category) {
            Livewire::actingAs($admin)->test('wfp-lookup-table', ['category' => $category])
                ->assertOk();
        }
    }

    public function test_lookup_models_share_the_audit_trait(): void
    {
        $this->assertSame('funding_source', WfpFundingSource::LOOKUP_KEY);
        $this->assertSame('unit', WfpUnit::LOOKUP_KEY);
        $this->assertSame('resource_kind', WfpResourceKind::LOOKUP_KEY);
        $this->assertSame('org_classification', WfpOrgClassification::LOOKUP_KEY);
    }
}
