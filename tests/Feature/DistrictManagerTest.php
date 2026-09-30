<?php

namespace Tests\Feature;

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
            ->test(\App\Livewire\DistrictManager::class)
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
            ->test(\App\Livewire\DistrictManager::class)
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
            ->test(\App\Livewire\DistrictManager::class)
            ->call('openSchoolForm', $district->id)
            ->set('schoolName', 'San Isidro Elementary')
            ->call('addSchool')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('schools', [
            'district_id' => $district->id,
            'name' => 'San Isidro Elementary',
        ]);
    }

    public function test_school_names_must_be_unique_within_a_district(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);
        School::create(['district_id' => $district->id, 'name' => 'San Isidro Elementary']);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\DistrictManager::class)
            ->call('openSchoolForm', $district->id)
            ->set('schoolName', 'San Isidro Elementary')
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
        School::create(['district_id' => $district->id, 'name' => 'San Isidro Elementary']);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\DistrictManager::class)
            ->call('openModal')
            ->assertSee('District 1')
            ->assertSee('San Isidro Elementary')
            ->assertSee('Add District');
    }

    public function test_superadmin_can_delete_a_district_and_its_schools_cascade(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);
        School::create(['district_id' => $district->id, 'name' => 'San Isidro Elementary']);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\DistrictManager::class)
            ->call('deleteDistrict', $district->id);

        $this->assertDatabaseMissing('districts', ['id' => $district->id]);
        $this->assertDatabaseMissing('schools', ['name' => 'San Isidro Elementary']);
    }

    public function test_superadmin_can_delete_a_single_school(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);
        $school = School::create(['district_id' => $district->id, 'name' => 'San Isidro Elementary']);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\DistrictManager::class)
            ->call('deleteSchool', $school->id);

        $this->assertDatabaseMissing('schools', ['id' => $school->id]);
        $this->assertDatabaseHas('districts', ['id' => $district->id]);
    }

    public function test_superadmin_can_rename_a_district(): void
    {
        $admin = $this->superadmin();
        $district = District::create(['name' => 'District 1']);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\DistrictManager::class)
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
            ->test(\App\Livewire\DistrictManager::class)
            ->call('startRename', 'district', $other->id)
            ->set('editingName', 'District 1')
            ->call('saveRename')
            ->assertHasErrors(['editingName']);

        $this->assertDatabaseHas('districts', ['id' => $other->id, 'name' => 'District 2']);
    }

    public function test_non_superadmins_cannot_use_the_district_manager(): void
    {
        $staff = $this->staffUser();

        // The dashboard never renders the button or the component for staff.
        $this->actingAs($staff)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('View District');

        // And even a direct component mount is rejected by the guard.
        $thrown = null;
        try {
            Livewire::actingAs($staff)
                ->test(\App\Livewire\DistrictManager::class)
                ->call('addDistrict');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected the superadmin guard to reject a staff user.');
        $this->assertDatabaseMissing('districts', ['name' => 'District 1']);
    }
}
