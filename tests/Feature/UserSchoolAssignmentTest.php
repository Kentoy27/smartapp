<?php

namespace Tests\Feature;

use App\Livewire\UsersTable;
use App\Models\District;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Add User form's School Division → School → School ID workflow:
 * the account is linked to the selected school record, its School ID is
 * never typed by hand, the dropdowns load from the database, and — the
 * cascade belongs to the SH role alone, where the School ID doubles as the
 * account's default login.
 */
class UserSchoolAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::create([
            'name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'adminuser@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => true,
        ]);
    }

    public function test_creating_a_user_links_the_selected_school(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'SDO Example Division']);
        $school = School::create(['district_id' => $district->id, 'name' => 'ABC Elementary School', 'school_id' => '123456']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('school_division', (string) $district->id)
            ->assertSee('ABC Elementary School', false)
            ->set('school_id_choice', (string) $school->id)
            // The School ID fills itself from the school record.
            ->assertSet('selectedSchoolId', '123456')
            ->set('name', 'Test Person')
            ->set('username', 'jane')
            ->set('email', 'jane@example.com')
            ->set('password', 'password123')
            ->call('saveUser')
            ->assertHasNoErrors();

        $user = User::where('username', 'jane')->first();

        $this->assertNotNull($user);
        $this->assertSame($school->id, $user->school_id);
        $this->assertSame('ABC Elementary School', $user->school->name);
        $this->assertSame('123456', $user->school->school_id);
    }

    public function test_division_and_school_are_required(): void
    {
        $admin = $this->superadmin();

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('name', 'Test Person')
            ->set('username', 'noschool')
            ->set('email', 'noschool@example.com')
            ->set('password', 'password123')
            ->call('saveUser')
            ->assertHasErrors(['school_division', 'school_id_choice']);

        $this->assertDatabaseMissing('users', ['username' => 'noschool']);
    }

    public function test_a_school_outside_the_selected_division_is_rejected(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        $other = District::create(['name' => 'Division B']);
        $school = School::create(['district_id' => $other->id, 'name' => 'Elsewhere School', 'school_id' => '999999']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            // Division A selected, but the choice points into Division B —
            // a crafted call must fail validation.
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id)
            ->set('name', 'Test Person')
            ->set('username', 'crosser')
            ->set('email', 'crosser@example.com')
            ->set('password', 'password123')
            ->call('saveUser')
            ->assertHasErrors(['school_id_choice']);

        $this->assertDatabaseMissing('users', ['username' => 'crosser']);
    }

    public function test_changing_the_division_clears_the_school_choice(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        $other = District::create(['name' => 'Division B']);
        School::create(['district_id' => $district->id, 'name' => 'A School', 'school_id' => '111111']);
        School::create(['district_id' => $other->id, 'name' => 'B School', 'school_id' => '222222']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', '1')
            ->set('school_division', (string) $other->id)
            // The stale school choice was dropped with the division change.
            ->assertSet('school_id_choice', '');
    }

    public function test_editing_a_user_prefills_their_school_and_can_change_it(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        $schoolA = School::create(['district_id' => $district->id, 'name' => 'A School', 'school_id' => '111111']);
        $schoolB = School::create(['district_id' => $district->id, 'name' => 'B School', 'school_id' => '222222']);

        $user = User::create([
            'name' => 'Jane', 'username' => 'jane', 'email' => 'jane@example.com',
            'password' => Hash::make('password123'), 'is_superadmin' => false,
            'school_id' => $schoolA->id,
        ]);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openEditModal', $user->fresh())
            ->assertSet('school_division', (string) $district->id)
            ->assertSet('school_id_choice', (string) $schoolA->id)
            ->assertSet('selectedSchoolId', '111111')
            ->set('school_id_choice', (string) $schoolB->id)
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertSame($schoolB->id, $user->fresh()->school_id);
    }

    public function test_the_division_dropdown_comes_from_the_database(): void
    {
        $admin = $this->superadmin();
        District::create(['name' => 'District I']);
        District::create(['name' => 'District II']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->assertSee('District I', false)
            ->assertSee('District II', false)
            ->assertSee('Select School Division', false);
    }

    public function test_picking_a_school_fills_the_login_with_its_school_id(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        $school = School::create(['district_id' => $district->id, 'name' => 'ABC Elementary School', 'school_id' => '123456']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->assertSet('username', '')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id)
            // The DepEd School ID is the SH's login.
            ->assertSet('username', '123456');
    }

    public function test_a_school_without_a_school_id_leaves_the_login_alone(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        // Not every school record carries a DepEd ID (older rows may not),
        // and then there is simply nothing to default the login to.
        $school = School::create(['district_id' => $district->id, 'name' => 'No ID School', 'school_id' => null]);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id)
            ->assertSet('selectedSchoolId', '')
            ->assertSet('username', '')
            ->assertSee('no School ID on record', false);
    }

    public function test_an_sh_password_defaults_to_the_school_id(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        $school = School::create(['district_id' => $district->id, 'name' => 'ABC Elementary School', 'school_id' => '123456']);

        // No password is typed: the login and the starting password are both
        // the school's DepEd ID, so an SH can sign in straight away.
        $component = Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal');

        $this->assertTrue($component->instance()->hasAutoPassword()); // SH: never a field

        $component
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id)
            ->assertSet('username', '123456')
            ->set('name', 'Test Person')
            ->set('email', 'head@example.com')
            ->call('saveUser')
            ->assertHasNoErrors();

        $user = User::where('username', '123456')->first();

        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('123456', $user->password));
    }

    public function test_the_password_field_stays_for_a_school_without_an_id(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        $school = School::create(['district_id' => $district->id, 'name' => 'No ID School', 'school_id' => null]);

        // The field is decided by the ROLE, so it is already gone for an SH —
        // but there is then nothing to hand out, and saving is refused rather
        // than minting an account nobody can sign in to.
        $component = Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal');

        $this->assertTrue($component->instance()->hasAutoPassword()); // SH: no field

        $component
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id)
            ->set('name', 'Test Person')
            ->set('username', 'head_two')
            ->set('email', 'head2@example.com')
            ->call('saveUser')
            ->assertHasErrors(['school_id_choice'])
            ->assertSet('showCreateModal', true); // not created

        $this->assertDatabaseMissing('users', ['username' => 'head_two']);
    }

    public function test_the_password_field_is_gone_for_an_sh_before_a_school_is_picked(): void
    {
        $admin = $this->superadmin();

        // The role alone decides it: the field must not pop back into the
        // "add form" the moment a school has not been chosen yet.
        $component = Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal');

        $this->assertTrue($component->instance()->hasAutoPassword());
        $component->assertDontSee('formPassword', false);

        // Switching to a role with no school brings the field back.
        $component->set('role', 'viewer');
        $this->assertFalse($component->instance()->hasAutoPassword());
        $component->assertSee('formPassword', false);
    }

    public function test_a_non_sh_account_still_sets_its_own_password(): void
    {
        $admin = $this->superadmin();

        $component = Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'viewer')
            ->set('name', 'Test Person')
            ->set('username', 'viewer_two')
            ->set('email', 'viewer2@example.com')
            ->set('password', 'supersecret1');

        // No school, so nothing can be derived — the superadmin sets it.
        $this->assertFalse($component->instance()->hasAutoPassword());

        $component->call('saveUser')->assertHasNoErrors();

        $this->assertTrue(Hash::check('supersecret1', User::where('username', 'viewer_two')->first()->password));
    }

    public function test_a_stale_default_login_is_dropped_for_a_school_without_an_id(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        $withId = School::create(['district_id' => $district->id, 'name' => 'A School', 'school_id' => '111111']);
        $withoutId = School::create(['district_id' => $district->id, 'name' => 'B School', 'school_id' => null]);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $withId->id)
            ->assertSet('username', '111111')
            ->set('school_id_choice', (string) $withoutId->id)
            // Another school's ID would be a login nobody can explain.
            ->assertSet('username', '');
    }

    public function test_a_login_typed_by_hand_survives_a_change_of_school(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        $first = School::create(['district_id' => $district->id, 'name' => 'A School', 'school_id' => '111111']);
        $second = School::create(['district_id' => $district->id, 'name' => 'B School', 'school_id' => '222222']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $first->id)
            ->set('name', 'Test Person')
            ->set('username', 'head.principal')
            ->set('school_id_choice', (string) $second->id)
            // The prefilled default may be replaced, never a chosen login.
            ->assertSet('username', 'head.principal');
    }

    public function test_the_still_untouched_default_login_follows_a_changed_school(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        $first = School::create(['district_id' => $district->id, 'name' => 'A School', 'school_id' => '111111']);
        $second = School::create(['district_id' => $district->id, 'name' => 'B School', 'school_id' => '222222']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $first->id)
            ->assertSet('username', '111111')
            ->set('school_id_choice', (string) $second->id)
            // Nobody edited it, so the default tracks the new school.
            ->assertSet('username', '222222');
    }

    public function test_the_school_cascade_belongs_to_the_sh_role_alone(): void
    {
        $admin = $this->superadmin();
        District::create(['name' => 'Division A']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->assertSet('role', 'user')
            ->assertSee('School Division', false)
            ->set('role', 'superadmin')
            ->assertDontSee('School Division', false)
            ->assertDontSee('School ID', false)
            ->set('role', 'viewer')
            ->assertDontSee('School Division', false);
    }

    public function test_a_non_sh_account_is_created_without_a_school(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        $school = School::create(['district_id' => $district->id, 'name' => 'A School', 'school_id' => '111111']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('role', 'viewer')
            ->set('name', 'Test Person')
            ->set('username', 'viewer_one')
            ->set('email', 'viewer@example.com')
            ->set('password', 'password123')
            // A crafted payload cannot attach a school to a non-SH either.
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id)
            ->call('saveUser')
            ->assertHasNoErrors();

        $viewer = User::where('username', 'viewer_one')->first();

        $this->assertNotNull($viewer);
        $this->assertSame('viewer', $viewer->role);
        $this->assertNull($viewer->school_id);
    }

    public function test_switching_away_from_sh_drops_the_school_selection(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'Division A']);
        $school = School::create(['district_id' => $district->id, 'name' => 'A School', 'school_id' => '111111']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('openCreateModal')
            ->set('school_division', (string) $district->id)
            ->set('school_id_choice', (string) $school->id)
            ->set('role', 'superadmin')
            // Hidden fields must not leave a school attached on save.
            ->assertSet('school_division', '')
            ->assertSet('school_id_choice', '');
    }
}
