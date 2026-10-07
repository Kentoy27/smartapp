<?php

namespace Tests\Feature;

use App\Livewire\DistrictManager;
use App\Models\District;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class DistrictManagerTest extends TestCase
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

    private function staffUser(): User
    {
        return User::create([
            'name' => 'Staff Member',
            'username' => 'staff',
            'email' => 'staff@example.com',
            'password' => Hash::make('password123'),
            'is_superadmin' => false,
        ]);
    }

    public function test_superadmin_can_add_a_district(): void
    {
        $admin = $this->superadmin();

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->set('districtName', 'District 1')
            ->call('addDistrict')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('districts', ['name' => 'District 1']);
    }

    public function test_district_names_must_be_unique(): void
    {
        $admin = $this->superadmin();
        District::create(['name' => 'District 1']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->set('districtName', 'District 1')
            ->call('addDistrict')
            ->assertHasErrors(['districtName']);

        $this->assertSame(1, District::count());
    }

    public function test_superadmin_can_add_a_school_under_a_district(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('openSchoolForm', $district->id)
            ->set('schoolName', 'San Isidro Elementary')
            ->set('schoolId', '123456')
            ->call('addSchool')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('schools', [
            'district_id' => $district->id,
            'name' => 'San Isidro Elementary',
            'school_id' => '123456',
        ]);
    }

    public function test_a_school_requires_its_school_id(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('openSchoolForm', $district->id)
            ->set('schoolName', 'San Isidro Elementary')
            ->set('schoolId', '')
            ->call('addSchool')
            ->assertHasErrors(['schoolId']);

        $this->assertSame(0, School::count());
    }

    public function test_school_ids_must_be_unique_across_the_system(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);
        $other = District::create(['name' => 'District 2']);
        School::create(['district_id' => $district->id, 'name' => 'First School', 'school_id' => '123456']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('openSchoolForm', $other->id)
            ->set('schoolName', 'Second School')
            ->set('schoolId', '123456')
            ->call('addSchool')
            ->assertHasErrors(['schoolId']);

        $this->assertSame(1, School::count());
    }

    public function test_a_school_with_users_cannot_be_deleted(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);
        $school = School::create(['district_id' => $district->id, 'name' => 'Full School', 'school_id' => '111111']);

        $staff = User::create([
            'name' => 'Staff', 'username' => 'staffy', 'email' => 'staffy@example.com',
            'password' => Hash::make('password123'), 'is_superadmin' => false, 'school_id' => $school->id,
        ]);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('openDeleteSchool', $school->id)
            ->call('confirmDeleteSchool')
            ->assertHasErrors(['deleteSchool']);

        $this->assertDatabaseHas('schools', ['id' => $school->id]);

        // Reassign the user away, and the school becomes deletable.
        $staff->update(['school_id' => null]);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('openDeleteSchool', $school->id)
            ->call('confirmDeleteSchool')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('schools', ['id' => $school->id]);
    }

    public function test_selecting_a_district_lists_its_schools_with_ids(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District I']);
        School::create(['district_id' => $district->id, 'name' => 'ABC Elementary School', 'school_id' => '123456']);
        School::create(['district_id' => $district->id, 'name' => 'XYZ National High School', 'school_id' => '123457']);
        $other = District::create(['name' => 'District II']);
        School::create(['district_id' => $other->id, 'name' => 'Elsewhere School', 'school_id' => '999999']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('openModal')
            ->assertSee('District I')
            ->call('selectDistrict', $district->id)
            ->assertSee('ABC Elementary School')
            ->assertSee('123456')
            ->assertSee('XYZ National High School')
            // Another district's school stays hidden until selected.
            ->assertDontSee('Elsewhere School')
            ->assertSee('2 Schools');
    }

    public function test_the_school_search_filters_the_selected_district(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District I']);
        School::create(['district_id' => $district->id, 'name' => 'ABC Elementary School', 'school_id' => '123456']);
        School::create(['district_id' => $district->id, 'name' => 'XYZ National High School', 'school_id' => '123457']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('openModal')
            ->call('selectDistrict', $district->id)
            ->set('schoolSearch', 'ABC')
            ->assertSee('ABC Elementary School')
            ->assertDontSee('XYZ National High School');
    }

    public function test_school_names_must_be_unique_within_a_district(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);
        School::create(['district_id' => $district->id, 'name' => 'San Isidro Elementary', 'school_id' => '123456']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('openSchoolForm', $district->id)
            ->set('schoolName', 'San Isidro Elementary')
            ->set('schoolId', '654321')
            ->call('addSchool')
            ->assertHasErrors(['schoolName']);

        // The same name in ANOTHER district is fine.
        $other = District::create(['name' => 'District 2']);
        School::create(['district_id' => $other->id, 'name' => 'San Isidro Elementary']);
        $this->assertSame(2, School::count());
    }

    public function test_modal_lists_districts_with_their_schools(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);
        School::create(['district_id' => $district->id, 'name' => 'San Isidro Elementary', 'school_id' => '123456']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('openModal')
            ->assertSee('District 1')
            ->assertSee('Add District')
            ->assertSee('Manage Districts');
    }

    public function test_superadmin_can_delete_a_district_and_its_schools_cascade(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);
        School::create(['district_id' => $district->id, 'name' => 'San Isidro Elementary']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('deleteDistrict', $district->id);

        $this->assertDatabaseMissing('districts', ['id' => $district->id]);
        $this->assertDatabaseMissing('schools', ['name' => 'San Isidro Elementary']);
    }

    public function test_superadmin_can_delete_a_single_school(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);
        $school = School::create(['district_id' => $district->id, 'name' => 'San Isidro Elementary', 'school_id' => '123456']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('openDeleteSchool', $school->id)
            ->call('confirmDeleteSchool')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('schools', ['id' => $school->id]);
        $this->assertDatabaseHas('districts', ['id' => $district->id]);
    }

    public function test_superadmin_can_rename_a_district(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('startRename', 'district', $district->id)
            ->set('editingName', 'District One Revised')
            ->call('saveRename')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('districts', ['id' => $district->id, 'name' => 'District One Revised']);
    }

    public function test_rename_rejects_duplicate_district_names(): void
    {
        $admin = $this->superadmin();
        District::create(['name' => 'District 1']);
        $other = District::create(['name' => 'District 2']);

        Livewire::actingAs($admin)
            ->test(DistrictManager::class)
            ->call('startRename', 'district', $other->id)
            ->set('editingName', 'District 1')
            ->call('saveRename')
            ->assertHasErrors(['editingName']);

        $this->assertDatabaseHas('districts', ['id' => $other->id, 'name' => 'District 2']);
    }

    public function test_the_school_forms_are_styled_fields_not_plain_textboxes(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
        $district = District::create(['name' => 'Division A']);
        $school = School::create(['district_id' => $district->id, 'name' => 'A School', 'school_id' => '111111']);

        $component = Livewire::actingAs($admin)->test(DistrictManager::class)
            ->call('openModal', $district->id);

        // Both school forms sit OUTSIDE .modal-form, which is what styles every
        // other input in the app. They carry their own .input-icon shell and
        // placeholders so the panel CSS has something to hang the standard
        // field treatment on — without it they render as raw textboxes.
        $component->call('openSchoolForm', $district->id)
            ->assertSee('school-add-panel', false)
            ->assertSee('school-form-head', false)
            ->assertSee('school-form-grid', false)
            ->assertSee('input-icon', false)
            ->assertSee('e.g. ABC Elementary School', false)
            ->assertSee('e.g. 123456', false);

        $component->call('cancelSchoolForm')
            ->call('startEditSchool', $school->id)
            ->assertSee('school-edit-form', false)
            ->assertSee('school-form-head', false)
            ->assertSee('school-form-grid', false)
            ->assertSee('input-icon', false)
            ->assertSee('e.g. 123456', false)
            ->assertSee('Save Changes', false);
    }

    public function test_non_superadmins_cannot_use_the_district_manager(): void
    {
        $staff = $this->staffUser();

        // The dashboard never renders the button or the component for staff.
        $this->actingAs($staff)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Manage Districts');

        // And even a direct component mount is rejected by the guard.
        $thrown = null;
        try {
            Livewire::actingAs($staff)
                ->test(DistrictManager::class)
                ->call('addDistrict');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected the superadmin guard to reject a staff user.');
        $this->assertDatabaseMissing('districts', ['name' => 'District 1']);
    }
}
