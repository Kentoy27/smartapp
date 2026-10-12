<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Models\AccountClassification;
use App\Models\AccountClassificationAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class AccountClassificationsTest extends TestCase
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

    private function make(array $overrides = []): AccountClassification
    {
        return AccountClassification::create(array_merge([
            'account_title' => 'Office Supplies Expenses',
            'rca_code' => '50203010',
            'uacs_sub_object_code' => '00',
            'uacs_object_code' => '5020301000',
            'covering_ppas' => 'PPAS 1',
            'is_active' => true,
        ], $overrides));
    }

    // ---------------------------------------------------------------- access

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('account-classifications.index'))->assertRedirect(route('login'));
    }

    public function test_administrator_and_superadmin_can_open_the_page(): void
    {
        foreach ([$this->administrator(), $this->superadmin()] as $admin) {
            $this->actingAs($admin)
                ->get(route('account-classifications.index'))
                ->assertOk()
                ->assertSeeLivewire('account-classifications-table');
        }
    }

    public function test_staff_and_viewer_cannot_open_the_page(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_superadmin' => false]);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);

        $this->actingAs($staff)->get(route('account-classifications.index'))->assertNotFound();
        $this->actingAs($viewer)->get(route('account-classifications.index'))->assertNotFound();
    }

    public function test_the_component_rechecks_administrative_access_on_mount(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_superadmin' => false]);

        // Livewire's test harness renders the framework's error page for a
        // mount guard rather than throwing (see ApplicationSmokeTest), so the
        // refusal is asserted on what came back.
        $html = Livewire::actingAs($staff)->test('account-classifications-table')->html();

        $this->assertStringContainsString('Page Not Found', $html);
    }

    public function test_sidebar_shows_the_item_for_admins_only(): void
    {
        Livewire::actingAs($this->administrator())->test(Sidebar::class)
            ->assertSee('Account Classifications');

        Livewire::actingAs(User::factory()->create(['role' => 'user', 'is_superadmin' => false]))
            ->test(Sidebar::class)
            ->assertDontSee('Account Classifications');
    }

    public function test_template_download_returns_a_csv_for_admins(): void
    {
        $this->actingAs($this->administrator())
            ->get(route('account-classifications.template'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertSee('Account Title,RCA Code,UACS Sub-Object Code,UACS Object Code,Covering PPAS', false)
            ->assertSee('Office Supplies Expenses,50203010,00,5020301000,PPAS 1', false);
    }

    public function test_template_download_is_admin_only(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_superadmin' => false]);

        $this->actingAs($staff)
            ->get(route('account-classifications.template'))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------ read

    public function test_search_narrows_the_table(): void
    {
        $admin = $this->administrator();
        $this->make(['account_title' => 'Office Supplies Expenses']);
        $this->make([
            'account_title' => 'Training Expenses',
            'rca_code' => '50202010',
            'uacs_sub_object_code' => '00',
            'uacs_object_code' => '5020201000',
        ]);

        Livewire::actingAs($admin)->test('account-classifications-table')
            ->set('search', 'Training')
            ->assertSee('Training Expenses')
            ->assertDontSee('Office Supplies Expenses');
    }

    public function test_status_filter_separates_active_and_inactive(): void
    {
        $admin = $this->administrator();
        $this->make(['account_title' => 'Active One', 'is_active' => true]);
        $this->make([
            'account_title' => 'Retired One',
            'rca_code' => '9999',
            'uacs_sub_object_code' => '00',
            'uacs_object_code' => '9999000',
            'is_active' => false,
        ]);

        Livewire::actingAs($admin)->test('account-classifications-table')
            ->set('status', 'inactive')
            ->assertSee('Retired One')
            ->assertDontSee('Active One');

        Livewire::actingAs($admin)->test('account-classifications-table')
            ->set('status', 'active')
            ->assertSee('Active One')
            ->assertDontSee('Retired One');
    }

    // ---------------------------------------------------------------- create

    public function test_create_validates_required_fields(): void
    {
        $admin = $this->administrator();

        Livewire::actingAs($admin)->test('account-classifications-table')
            ->call('openCreate')
            ->set('account_title', '')
            ->set('rca_code', '')
            ->set('uacs_sub_object_code', '')
            ->set('uacs_object_code', '')
            ->call('save')
            ->assertHasErrors([
                'account_title' => 'required',
                'rca_code' => 'required',
                'uacs_sub_object_code' => 'required',
                'uacs_object_code' => 'required',
            ])
            ->assertSet('showForm', true); // stays open on failure

        $this->assertSame(0, AccountClassification::count());
    }

    public function test_create_trims_whitespace_and_keeps_codes_as_strings(): void
    {
        $admin = $this->administrator();

        Livewire::actingAs($admin)->test('account-classifications-table')
            ->call('openCreate')
            ->set('account_title', '  Office Supplies Expenses  ')
            ->set('rca_code', '50203010')
            ->set('uacs_sub_object_code', '00')
            ->set('uacs_object_code', '5020301000')
            ->set('covering_ppas', '  PPAS 1  ')
            ->call('save')
            ->assertSet('showForm', false)
            ->assertHasNoErrors();

        $record = AccountClassification::firstOrFail();

        $this->assertSame('Office Supplies Expenses', $record->account_title);
        $this->assertSame('PPAS 1', $record->covering_ppas);
        // Codes keep leading zeroes — stored exactly as typed.
        $this->assertSame('00', $record->uacs_sub_object_code);
        $this->assertSame('5020301000', $record->uacs_object_code);
        $this->assertTrue($record->is_active);
    }

    public function test_a_duplicate_classification_is_prevented(): void
    {
        $admin = $this->administrator();
        $this->make();

        Livewire::actingAs($admin)->test('account-classifications-table')
            ->call('openCreate')
            ->set('account_title', 'office supplies expenses') // same identity, different case
            ->set('rca_code', '50203010')
            ->set('uacs_sub_object_code', '00')
            ->set('uacs_object_code', '5020301000')
            ->call('save')
            ->assertHasErrors(['account_title']);

        $this->assertSame(1, AccountClassification::count());
    }

    // ---------------------------------------------------------------- update

    public function test_edit_prefills_and_updates_without_self_conflict(): void
    {
        $admin = $this->administrator();
        $record = $this->make();

        Livewire::actingAs($admin)->test('account-classifications-table')
            ->call('openEdit', $record->id)
            ->assertSet('account_title', 'Office Supplies Expenses')
            ->set('account_title', 'Office Supplies Expense')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertSame('Office Supplies Expense', $record->fresh()->account_title);
        $this->assertSame(1, AccountClassification::count());
    }

    // ---------------------------------------------------------------- delete

    public function test_delete_deactivates_rather_than_removing_the_row(): void
    {
        $admin = $this->administrator();
        $record = $this->make();

        Livewire::actingAs($admin)->test('account-classifications-table')
            ->call('openConfirmDelete', $record->id)
            ->assertSet('showConfirm', true)
            ->call('confirmDelete')
            ->assertSet('showConfirm', false);

        // The row is still there — only its status changed.
        $this->assertDatabaseHas('account_classifications', [
            'id' => $record->id,
            'is_active' => false,
        ]);
    }

    public function test_an_inactive_classification_can_be_reactivated(): void
    {
        $admin = $this->administrator();
        $record = $this->make(['is_active' => false]);

        Livewire::actingAs($admin)->test('account-classifications-table')
            ->call('activate', $record->id);

        $this->assertTrue($record->fresh()->is_active);
    }

    // ----------------------------------------------------------------- audit

    public function test_administrative_changes_are_audited(): void
    {
        $admin = $this->administrator();

        $component = Livewire::actingAs($admin)->test('account-classifications-table')
            ->call('openCreate')
            ->set('account_title', 'Audited Item')
            ->set('rca_code', '1111')
            ->set('uacs_sub_object_code', '00')
            ->set('uacs_object_code', '1111000')
            ->call('save');

        $record = AccountClassification::firstOrFail();

        $this->assertSame(1, AccountClassificationAudit::where('action', AccountClassification::AUDIT_CREATE)->count());
        $this->assertSame($admin->id, AccountClassificationAudit::first()->user_id);

        $component->call('openConfirmDelete', $record->id)->call('confirmDelete');

        $this->assertSame(1, AccountClassificationAudit::where('action', AccountClassification::AUDIT_DEACTIVATE)->count());
    }

    // ------------------------------------------------------------------- api

    public function test_api_rejects_guests_with_unauthorized(): void
    {
        $this->getJson('/api/admin/account-classifications')->assertUnauthorized();
    }

    public function test_api_rejects_non_administrators_with_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_superadmin' => false]);

        $this->actingAs($staff)
            ->getJson('/api/admin/account-classifications')
            ->assertForbidden();
    }

    public function test_api_supports_the_full_crud_flow(): void
    {
        $admin = $this->administrator();

        // List — empty at first.
        $this->actingAs($admin)
            ->getJson('/api/admin/account-classifications?page=1&pageSize=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // Create.
        $created = $this->actingAs($admin)
            ->postJson('/api/admin/account-classifications', [
                'account_title' => 'Office Supplies Expenses',
                'rca_code' => '50203010',
                'uacs_sub_object_code' => '00',
                'uacs_object_code' => '5020301000',
                'covering_ppas' => 'PPAS 1',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('00', $created['uacs_sub_object_code']);

        // Duplicate is rejected.
        $this->actingAs($admin)
            ->postJson('/api/admin/account-classifications', [
                'account_title' => 'Office Supplies Expenses',
                'rca_code' => '50203010',
                'uacs_sub_object_code' => '00',
                'uacs_object_code' => '5020301000',
            ])
            ->assertStatus(422);

        // Show.
        $this->actingAs($admin)
            ->getJson('/api/admin/account-classifications/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('data.account_title', 'Office Supplies Expenses');

        // Update.
        $this->actingAs($admin)
            ->putJson('/api/admin/account-classifications/'.$created['id'], [
                'account_title' => 'Office Supplies Expense',
                'rca_code' => '50203010',
                'uacs_sub_object_code' => '00',
                'uacs_object_code' => '5020301000',
            ])
            ->assertOk()
            ->assertJsonPath('data.account_title', 'Office Supplies Expense');

        // Delete = soft delete.
        $this->actingAs($admin)
            ->deleteJson('/api/admin/account-classifications/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('account_classifications', [
            'id' => $created['id'],
            'is_active' => false,
        ]);

        // Reactivate.
        $this->actingAs($admin)
            ->postJson('/api/admin/account-classifications/'.$created['id'].'/activate')
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_api_search_filters_the_list(): void
    {
        $admin = $this->administrator();
        $this->make(['account_title' => 'Office Supplies Expenses']);
        $this->make([
            'account_title' => 'Training Expenses',
            'rca_code' => '50202010',
            'uacs_sub_object_code' => '00',
            'uacs_object_code' => '5020201000',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/account-classifications?search=Training')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.account_title', 'Training Expenses');
    }

    // ---------------------------------------------------------------- import

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('accounts.csv', $content);
    }

    public function test_import_previews_then_commits_valid_rows(): void
    {
        $admin = $this->administrator();

        $csv = implode("\n", [
            'Account Title,RCA Code,UACS Sub-Object Code,UACS Object Code,Covering PPAS',
            'Office Supplies Expenses,50203010,00,5020301000,PPAS 1',
            'Training Expenses,50202010,00,5020201000,PPAS 2',
        ]);

        $component = Livewire::actingAs($admin)->test('account-classifications-table')
            ->call('openImport')
            ->set('importFile', $this->csv($csv))
            ->assertSet('importValidCount', 2)
            ->assertSet('importInvalidCount', 0)
            ->assertSet('importError', null);

        // Nothing is written until the import is confirmed.
        $this->assertSame(0, AccountClassification::count());

        $component->call('commitImport')
            ->assertSet('showImport', false)
            ->assertSet('successMessage', 'Import complete — 2 classification(s) added.');

        $this->assertSame(2, AccountClassification::count());
        // Codes keep their leading zeroes.
        $this->assertDatabaseHas('account_classifications', [
            'account_title' => 'Office Supplies Expenses',
            'uacs_sub_object_code' => '00',
        ]);
    }

    public function test_import_skips_invalid_rows_and_reports_them(): void
    {
        $admin = $this->administrator();

        $csv = implode("\n", [
            'Account Title,RCA Code,UACS Sub-Object Code,UACS Object Code,Covering PPAS',
            'Good Row,11111111,00,1111111100,PPAS 1',
            ',22222222,00,2222222200,', // missing title
        ]);

        Livewire::actingAs($admin)->test('account-classifications-table')
            ->call('openImport')
            ->set('importFile', $this->csv($csv))
            ->assertSet('importValidCount', 1)
            ->assertSet('importInvalidCount', 1)
            ->assertSee('Account Title is required')
            ->call('commitImport');

        $this->assertSame(1, AccountClassification::count());
        $this->assertDatabaseHas('account_classifications', ['account_title' => 'Good Row']);
    }

    public function test_import_flags_rows_that_already_exist(): void
    {
        $admin = $this->administrator();
        $this->make(); // Office Supplies Expenses / 50203010 / 00 / 5020301000

        $csv = implode("\n", [
            'Account Title,RCA Code,UACS Sub-Object Code,UACS Object Code,Covering PPAS',
            'Office Supplies Expenses,50203010,00,5020301000,PPAS 1',
        ]);

        Livewire::actingAs($admin)->test('account-classifications-table')
            ->call('openImport')
            ->set('importFile', $this->csv($csv))
            ->assertSet('importValidCount', 0)
            ->assertSet('importInvalidCount', 1)
            ->assertSee('Already exists as an active classification');
    }

    public function test_api_import_accepts_a_rows_array(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->postJson('/api/admin/account-classifications/import', [
                'rows' => [
                    [
                        'account_title' => 'Imported A',
                        'rca_code' => '11111111',
                        'uacs_sub_object_code' => '00',
                        'uacs_object_code' => '1111111100',
                        'covering_ppas' => 'PPAS 1',
                    ],
                    [
                        'account_title' => '', // invalid
                        'rca_code' => '22222222',
                        'uacs_sub_object_code' => '00',
                        'uacs_object_code' => '2222222200',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('validCount', 1)
            ->assertJsonPath('invalidCount', 1);

        $this->assertDatabaseHas('account_classifications', ['account_title' => 'Imported A']);
    }

    public function test_api_import_rejects_non_administrators(): void
    {
        $staff = User::factory()->create(['role' => 'user', 'is_superadmin' => false]);

        $this->actingAs($staff)
            ->postJson('/api/admin/account-classifications/import', [
                'rows' => [[
                    'account_title' => 'Nope',
                    'rca_code' => '1',
                    'uacs_sub_object_code' => '0',
                    'uacs_object_code' => '10',
                ]],
            ])
            ->assertForbidden();

        $this->assertSame(0, AccountClassification::count());
    }
}
