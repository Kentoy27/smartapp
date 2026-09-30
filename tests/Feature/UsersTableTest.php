<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UsersTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_open_the_users_page(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    public function test_users_page_lists_accounts_and_is_reachable_for_superadmins(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create([
            'is_superadmin' => true,
            'role' => 'superadmin',
        ]);
        $other = User::factory()->create(['username' => 'otheruser']);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSeeLivewire('users-table');

        Livewire::actingAs($admin)->test('users-table')
            ->assertSee('otheruser')
            ->assertSee($other->email);
    }

    public function test_search_narrows_the_live_table(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
        User::factory()->create(['username' => 'alpha']);
        User::factory()->create(['username' => 'beta']);

        Livewire::actingAs($admin)->withQueryParams(['page' => 1])
            ->test('users-table')
            ->set('search', 'alpha')
            ->assertSee('alpha')
            ->assertDontSee('beta');
    }

    public function test_superadmin_can_change_an_account_role_from_the_role_modal(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
        $target = User::factory()->create(['is_superadmin' => false, 'role' => 'user']);

        Livewire::actingAs($admin)
            ->test('users-table')
            ->call('openRoleModal', $target->id)
            ->assertSet('showRoleModal', true)
            ->assertSet('selectedRole', 'user')
            ->set('selectedRole', 'superadmin')
            ->call('updateRole')
            ->assertSet('showRoleModal', false);

        $target->refresh();
        $this->assertTrue($target->is_superadmin);
        $this->assertSame('superadmin', $target->role);

        Livewire::actingAs($admin)
            ->test('users-table')
            ->call('openRoleModal', $target->id)
            ->set('selectedRole', 'viewer')
            ->call('updateRole');

        $target->refresh();
        $this->assertFalse($target->is_superadmin);
        $this->assertSame('viewer', $target->role);
    }

    public function test_users_can_be_deleted_but_not_yourself(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
        $other = User::factory()->create();

        Livewire::actingAs($admin)
            ->test('users-table')
            ->call('deleteUser', $admin->id)
            ->call('deleteUser', $other->id)
            ->assertSet('successMessage', "Account deleted — {$other->username} was removed.");

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_delete_modal_requires_confirmation(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
        $other = User::factory()->create();

        Livewire::actingAs($admin)
            ->test('users-table')
            ->call('openDeleteModal', $other->id)
            ->assertSet('showDeleteModal', true)
            ->assertSet('deleteUsername', $other->username)
            ->call('closeDeleteModal')
            ->assertSet('showDeleteModal', false);

        $this->assertDatabaseHas('users', ['id' => $other->id]);

        Livewire::actingAs($admin)
            ->test('users-table')
            ->call('openDeleteModal', $other->id)
            ->call('confirmDelete');

        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_deleting_yourself_never_sets_a_success_message(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);

        Livewire::actingAs($admin)
            ->test('users-table')
            ->call('deleteUser', $admin->id)
            ->assertSet('successMessage', null);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_sidebar_shows_only_dashboard_and_users(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
        User::factory()->count(3)->create();

        Livewire::actingAs($admin)->test('sidebar')
            ->assertSee('Dashboard')
            ->assertSee('Users')
            ->assertSee('3') // live badge count
            ->assertDontSee('Help Center')
            ->assertDontSee('Reports')
            ->assertDontSee('Settings');
    }

    public function test_sidebar_items_are_listed_alphabetically(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);

        $html = Livewire::actingAs($admin)->test('sidebar')->html();

        // Each label renders inside its own plain <span>, so the offset of the
        // label marks its position in the menu.
        $positions = array_map(
            fn (string $label) => strpos($html, ">{$label}</span>"),
            ['Dashboard', 'Review Opcrf', 'Users']
        );

        $this->assertNotContains(false, $positions, 'All three items are rendered.');

        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions, 'Sidebar items must appear A→Z.');
    }

    public function test_add_user_modal_opens_and_closes(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);

        Livewire::actingAs($admin)->test('users-table')
            ->assertSet('showCreateModal', false)
            ->call('openCreateModal')
            ->assertSet('showCreateModal', true)
            ->assertSet('editingUserId', null) // create mode
            ->call('closeCreateModal')
            ->assertSet('showCreateModal', false);
    }

    public function test_create_user_validates_required_and_unique_fields(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
        User::factory()->create(['username' => 'taken', 'email' => 'taken@example.com']);

        Livewire::actingAs($admin)->test('users-table')
            ->call('openCreateModal')
            ->set('username', 'taken')
            ->set('email', 'not-an-email')
            ->set('password', 'short')
            ->call('saveUser')
            ->assertHasErrors(['username' => 'unique'])
            ->assertHasErrors(['email'])
            ->assertHasErrors(['password' => 'min'])
            ->assertSet('showCreateModal', true); // stays open on failure

        $this->assertSame(2, User::count());
    }

    public function test_create_user_makes_an_account_and_updates_the_table(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);

        Livewire::actingAs($admin)->test('users-table')
            ->call('openCreateModal')
            ->set('name', 'Jane Doe')
            ->set('username', 'jane')
            ->set('email', 'jane@example.com')
            ->set('password', 'supersecret1')
            ->call('saveUser')
            ->assertSet('showCreateModal', false)
            ->assertSee('jane')
            ->assertSee('jane@example.com');

        $user = User::where('username', 'jane')->first();

        $this->assertNotNull($user);
        $this->assertSame('Jane Doe', $user->name);
        $this->assertSame('jane@example.com', $user->email);
        $this->assertNotSame('supersecret1', $user->password); // hashed
        $this->assertFalse($user->is_superadmin); // never created as superadmin
    }

    public function test_create_user_shows_a_success_message(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);

        $component = Livewire::actingAs($admin)->test('users-table')
            ->call('openCreateModal')
            ->set('username', 'successive')
            ->set('email', 'successive@example.com')
            ->set('password', 'supersecret1')
            ->call('saveUser')
            ->assertSet('successMessage', 'Account created — successive was added as a user.');

        // The message is popped client-side via SweetAlert: the rendered HTML
        // must carry it into the smartAlert.success(...) call (Js::from may
        // escape non-ASCII, e.g. \u2014 for the em-dash, so compare encoded).
        $this->assertStringContainsString(
            'smartAlert.success(',
            $component->html()
        );
        $this->assertStringContainsString(
            'successive was added as a user.',
            $component->html()
        );
    }

    public function test_edit_modal_prefills_the_account_and_updates_it(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
        $user = User::factory()->create([
            'name' => 'Old Name',
            'username' => 'editable',
            'email' => 'editable@example.com',
        ]);

        Livewire::actingAs($admin)->test('users-table')
            ->call('openEditModal', $user->id)
            ->assertSet('editingUserId', $user->id)
            ->assertSet('username', 'editable')
            ->assertSet('email', 'editable@example.com')
            ->assertSet('name', 'Old Name')
            ->assertSet('password', '') // blank = keep current
            ->set('name', 'New Name')
            ->set('email', 'new@example.com')
            ->call('saveUser')
            ->assertSet('showCreateModal', false)
            ->assertSee('New Name')
            ->assertSet('successMessage', 'Account updated — editable was saved.');

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('editable', $user->username); // unchanged
        $this->assertNull($user->password ? null : 'not-empty'); // password untouched
    }

    public function test_edit_ignores_blank_password_and_allows_keeping_own_email(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
        $user = User::factory()->create([
            'username' => 'selfedit',
            'email' => 'selfedit@example.com',
        ]);
        $originalHash = $user->password;

        Livewire::actingAs($admin)->test('users-table')
            ->call('openEditModal', $user->id)
            // Re-submitting the same email must not trigger unique validation...
            ->set('email', 'selfedit@example.com')
            // ...and a blank password keeps the old one.
            ->call('saveUser')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame($originalHash, $user->password);
    }

    public function test_table_renders_an_edit_action_and_no_joined_column(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
        User::factory()->create();

        $component = Livewire::actingAs($admin)->test('users-table');
        $html = $component->html();

        $this->assertStringContainsString('Edit', $html);
        $this->assertStringNotContainsString('Joined', $html);
    }

    public function test_failed_validation_never_sets_a_success_message(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);

        Livewire::actingAs($admin)->test('users-table')
            ->call('openCreateModal')
            ->set('username', 'x')
            ->call('saveUser')
            ->assertHasErrors()
            ->assertSet('successMessage', null);
    }

    public function test_superadmin_can_create_a_user_with_the_admin_role(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);

        $component = Livewire::actingAs($admin)
            ->test('users-table')
            ->call('openCreateModal')
            ->set('username', 'freshadmin')
            ->set('email', 'freshadmin@example.com')
            ->set('password', 'supersecret1')
            ->set('isSuperadmin', true)
            ->call('saveUser')
            ->assertSet('successMessage', 'Account created — freshadmin was added as a super admin.');

        $user = User::where('username', 'freshadmin')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->is_superadmin);
        $this->assertStringContainsString('super admin', $component->html());
    }

    public function test_non_superadmin_cannot_grant_the_admin_role(): void
    {
        /** @var User $viewer */
        $viewer = User::factory()->create(['is_superadmin' => false]);

        $this->actingAs($viewer)
            ->get(route('users.index'))
            ->assertNotFound();
    }

    public function test_role_modal_prefills_the_role_and_can_change_it(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        $user = User::factory()->create(['is_superadmin' => false]);

        Livewire::actingAs($admin)
            ->test('users-table')
            ->call('openRoleModal', $user->id)
            ->assertSet('selectedRole', 'user')
            ->set('selectedRole', 'superadmin')
            ->call('updateRole');

        $this->assertTrue($user->refresh()->is_superadmin);

        Livewire::actingAs($admin)
            ->test('users-table')
            ->call('openRoleModal', $user->id)
            ->assertSet('selectedRole', 'superadmin')
            ->set('selectedRole', 'viewer')
            ->call('updateRole');

        $this->assertFalse($user->refresh()->is_superadmin);
        $this->assertSame('viewer', $user->role);
    }

    public function test_superadmin_cannot_demote_themselves_via_the_edit_modal(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);

        Livewire::actingAs($admin)
            ->test('users-table')
            ->call('openEditModal', $admin->id)
            ->set('role', 'user')
            ->set('isSuperadmin', false)
            ->call('saveUser');

        $this->assertTrue($admin->refresh()->is_superadmin, 'A superadmin must never be able to demote themselves.');
    }

    public function test_role_picker_is_hidden_from_non_superadmins(): void
    {
        /** @var User $viewer */
        $viewer = User::factory()->create(['is_superadmin' => false]);

        $this->actingAs($viewer)
            ->get(route('users.index'))
            ->assertNotFound();
    }
}
