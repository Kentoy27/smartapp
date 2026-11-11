<?php

namespace App\Livewire;

use App\Models\District;
use App\Models\School;
use App\Support\SchoolDivision;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Administrator-only district & school management.
 *
 * Lives in a modal on the dashboard ("Manage Districts & Schools"):
 * clickable districts with their schools underneath (name + School ID),
 * an Add School form (district pre-filled, School ID required and unique
 * across the system), edit and delete per school — a school that still
 * has active user accounts cannot be deleted — plus add / rename /
 * delete for districts. The dashboard hosts it via
 * <livewire:district-manager />.
 */
class DistrictManager extends Component
{
    public bool $showModal = false;

    /**
     * The district whose schools are expanded (clicked) in the modal —
     * null collapses every district to a single row.
     */
    public ?int $selectedDistrictId = null;

    /**
     * School search within the selected district.
     */
    public string $schoolSearch = '';

    public string $districtName = '';

    /**
     * The district selected in the Add School modal.
     */
    public ?int $schoolFormDistrictId = null;

    public bool $showSchoolFormModal = false;

    public string $schoolName = '';

    public string $schoolId = '';

    /**
     * The divisions the new school operates. Elementary is preselected so
     * the common case stays one click; the administrator checks any others.
     *
     * @var array<int, string>
     */
    public array $schoolDivisions = [SchoolDivision::ELEMENTARY];

    /**
     * The edit-school form: opens for $editingSchoolId.
     */
    public ?int $editingSchoolId = null;

    public string $editSchoolName = '';

    public string $editSchoolId = '';

    /**
     * The divisions the edited school operates.
     *
     * @var array<int, string>
     */
    public array $editSchoolDivisions = [];

    /** Inline district rename state: ['id' => int]. */
    public ?array $editing = null;

    public string $editingName = '';

    /**
     * Delete-school confirmation: guarded against schools with users.
     */
    public bool $showDeleteSchoolModal = false;

    public ?int $deleteSchoolId = null;

    public string $deleteSchoolLabel = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);
    }

    #[Computed]
    public function districts()
    {
        return District::query()
            ->with(['schools' => fn ($query) => $query->withCount('users')])
            ->withCount('schools')
            ->orderBy('name')
            ->get();
    }

    /**
     * The schools of the selected district (search-filtered), for the
     * modal's schools table.
     */
    #[Computed]
    public function selectedDistrictSchools()
    {
        if ($this->selectedDistrictId === null) {
            return collect();
        }

        return School::query()
            ->where('district_id', $this->selectedDistrictId)
            ->with('divisionSettings')
            ->withCount('users')
            ->when($this->schoolSearch !== '', function ($query): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->schoolSearch).'%';

                $query->where(function ($q) use ($term): void {
                    $q->where('name', 'like', $term)
                        ->orWhere('school_id', 'like', $term);
                });
            })
            ->orderBy('name')
            ->get();
    }

    public function selectDistrict(?int $districtId): void
    {
        $this->selectedDistrictId = $districtId;
        $this->schoolSearch = '';
        $this->cancelSchoolForm();
        $this->cancelEditSchool();
    }

    public function toggleDistrict(int $districtId): void
    {
        District::query()->findOrFail($districtId);

        $this->selectDistrict($this->selectedDistrictId === $districtId ? null : $districtId);
    }

    public function openModal(?int $districtId = null): void
    {
        $this->showModal = true;

        if ($districtId !== null && District::whereKey($districtId)->exists()) {
            $this->selectDistrict($districtId);
        }
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForms();
    }

    public function addDistrict(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->validateOnly('districtName', [
            'districtName' => ['required', 'string', 'max:120', 'unique:districts,name'],
        ], [
            'districtName.unique' => 'A district with this name already exists.',
        ]);

        $district = District::create(['name' => trim($this->districtName)]);

        $this->districtName = '';
        $this->selectedDistrictId = $district->id;
        $this->dispatch('districts-changed');
    }

    /**
     * The division choices a school can be configured with.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function divisionOptions(): array
    {
        return array_map(
            fn (string $division): array => [
                'value' => $division,
                'label' => SchoolDivision::label($division),
            ],
            SchoolDivision::all(),
        );
    }

    /* ---------- ADD SCHOOL ---------- */

    public function openSchoolForm(?int $districtId = null): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        if ($districtId !== null) {
            District::query()->findOrFail($districtId);
        } else {
            $districtId = $this->selectedDistrictId;
        }

        $this->schoolFormDistrictId = $districtId;
        $this->schoolName = '';
        $this->schoolId = '';
        $this->schoolDivisions = [SchoolDivision::ELEMENTARY];
        $this->cancelEditSchool();
        $this->resetValidation();
        $this->showSchoolFormModal = true;
    }

    public function cancelSchoolForm(): void
    {
        $this->showSchoolFormModal = false;
        $this->schoolFormDistrictId = null;
        $this->schoolName = '';
        $this->schoolId = '';
        $this->schoolDivisions = [SchoolDivision::ELEMENTARY];
        $this->resetValidation();
    }

    public function addSchool(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->validate([
            'schoolFormDistrictId' => ['required', 'integer', 'exists:districts,id'],
            // Unique only within the district being added to.
            'schoolName' => [
                'required', 'string', 'max:160',
                'unique:schools,name,NULL,id,district_id,'.$this->schoolFormDistrictId,
            ],
            // The DepEd school ID is unique across the whole system.
            'schoolId' => ['required', 'string', 'max:20', 'unique:schools,school_id'],
            // A school must operate at least one division; each value is
            // re-validated here because the checkboxes are only a convenience.
            'schoolDivisions' => ['required', 'array', 'min:1'],
            'schoolDivisions.*' => [Rule::in(SchoolDivision::all())],
        ], [
            'schoolName.required' => 'The school name is required.',
            'schoolName.unique' => 'This school already exists in the district.',
            'schoolFormDistrictId.required' => 'Select a district for this school.',
            'schoolFormDistrictId.exists' => 'Select a valid district for this school.',
            'schoolId.required' => 'The School ID is required.',
            'schoolId.unique' => 'That School ID is already used by another school.',
            'schoolDivisions.required' => 'Choose at least one division the school operates.',
            'schoolDivisions.min' => 'Choose at least one division the school operates.',
        ]);

        $school = School::create([
            'district_id' => $this->schoolFormDistrictId,
            'name' => trim($this->schoolName),
            'school_id' => trim($this->schoolId),
        ]);

        $this->syncSchoolDivisions($school, $this->schoolDivisions);

        $this->schoolName = '';
        $this->schoolId = '';
        $this->schoolDivisions = [SchoolDivision::ELEMENTARY];
        $this->selectedDistrictId = $school->district_id;
        $this->showSchoolFormModal = false;
        $this->schoolFormDistrictId = null;
        $this->dispatch('districts-changed');
    }

    /* ---------- EDIT SCHOOL ---------- */

    public function startEditSchool(int $schoolId): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $school = School::findOrFail($schoolId);

        $this->editingSchoolId = $school->id;
        $this->editSchoolName = $school->name;
        $this->editSchoolId = (string) $school->school_id;
        $this->editSchoolDivisions = $school->availableDivisions();
        $this->cancelSchoolForm();
        $this->resetValidation();
    }

    public function cancelEditSchool(): void
    {
        $this->editingSchoolId = null;
        $this->editSchoolName = '';
        $this->editSchoolId = '';
        $this->editSchoolDivisions = [];
        $this->resetValidation();
    }

    #[Computed]
    public function editingSchool(): ?School
    {
        if ($this->editingSchoolId === null) {
            return null;
        }

        return School::query()
            ->with('district')
            ->findOrFail($this->editingSchoolId);
    }

    public function saveEditSchool(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $school = School::findOrFail($this->editingSchoolId);

        $this->validate([
            'editSchoolName' => [
                'required', 'string', 'max:160',
                Rule::unique('schools', 'name')
                    ->where('district_id', $school->district_id)
                    ->ignore($school->id),
            ],
            'editSchoolId' => [
                'required', 'string', 'max:20',
                Rule::unique('schools', 'school_id')->ignore($school->id),
            ],
            'editSchoolDivisions' => ['required', 'array', 'min:1'],
            'editSchoolDivisions.*' => [Rule::in(SchoolDivision::all())],
        ], [
            'editSchoolName.required' => 'The school name is required.',
            'editSchoolName.unique' => 'This school already exists in the district.',
            'editSchoolId.required' => 'The School ID is required.',
            'editSchoolId.unique' => 'That School ID is already used by another school.',
            'editSchoolDivisions.required' => 'Choose at least one division the school operates.',
            'editSchoolDivisions.min' => 'Choose at least one division the school operates.',
        ]);

        $school->update([
            'name' => trim($this->editSchoolName),
            'school_id' => trim($this->editSchoolId),
        ]);

        $this->syncSchoolDivisions($school, $this->editSchoolDivisions);

        $this->cancelEditSchool();
        $this->dispatch('districts-changed');
    }

    /* ---------- DELETE SCHOOL ---------- */

    public function openDeleteSchool(int $schoolId): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $school = School::withCount('users')->findOrFail($schoolId);

        $this->deleteSchoolId = $school->id;
        $this->deleteSchoolLabel = $school->name;
        $this->showDeleteSchoolModal = true;
        $this->resetValidation();
    }

    public function closeDeleteSchool(): void
    {
        $this->showDeleteSchoolModal = false;
        $this->deleteSchoolId = null;
        $this->deleteSchoolLabel = '';
    }

    public function confirmDeleteSchool(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $school = School::withCount('users')->find($this->deleteSchoolId);

        if ($school === null) {
            $this->closeDeleteSchool();

            return;
        }

        // A school with active user accounts cannot be deleted — the users
        // would be orphaned. Reassign them first.
        if ($school->users_count > 0) {
            $this->closeDeleteSchool();
            $this->addError('deleteSchool', $school->name.' still has '.$school->users_count.' '
                .Str::plural('user', $school->users_count).' — reassign them before deleting the school.');

            return;
        }

        $school->delete();

        $this->closeDeleteSchool();
        $this->dispatch('districts-changed');
    }

    /* ---------- DISTRICT RENAME / DELETE ---------- */

    public function startRename(string $type, int $id): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->editingName = $type === 'district'
            ? District::findOrFail($id)->name
            : School::findOrFail($id)->name;

        $this->editing = ['type' => $type, 'id' => $id];
        $this->cancelSchoolForm();
        $this->resetValidation();
    }

    public function cancelRename(): void
    {
        $this->editing = null;
        $this->editingName = '';
        $this->resetValidation();
    }

    public function saveRename(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->validate([
            'editingName' => ['required', 'string', 'max:160'],
        ]);

        $name = trim($this->editingName);

        if (($this->editing['type'] ?? '') === 'district') {
            $district = District::findOrFail($this->editing['id']);

            $taken = District::where('name', $name)->where('id', '!=', $district->id)->exists();
            if ($taken) {
                $this->addError('editingName', 'A district with this name already exists.');

                return;
            }

            $district->update(['name' => $name]);
        } else {
            $school = School::findOrFail($this->editing['id']);

            $taken = School::where('name', $name)
                ->where('district_id', $school->district_id)
                ->where('id', '!=', $school->id)
                ->exists();
            if ($taken) {
                $this->addError('editingName', 'This school already exists in the district.');

                return;
            }

            $school->update(['name' => $name]);
        }

        $this->editing = null;
        $this->editingName = '';
        $this->dispatch('districts-changed');
    }

    public function deleteDistrict(int $id): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        // Schools cascade via the foreign key.
        District::whereKey($id)->delete();

        if ($this->selectedDistrictId === $id) {
            $this->selectDistrict(null);
        }

        $this->dispatch('districts-changed');
    }

    /**
     * Write the school's division menu. A division switched off keeps its row
     * (inactive) so re-enabling it is a toggle, not a new configuration.
     *
     * @param  array<int, string>  $divisions
     */
    private function syncSchoolDivisions(School $school, array $divisions): void
    {
        $selected = SchoolDivision::validAccessList($divisions);

        $school->divisionSettings()->update(['is_active' => false]);

        foreach ($selected as $division) {
            $school->divisionSettings()->updateOrCreate(
                ['division_code' => $division],
                ['is_active' => true],
            );
        }

        $school->unsetRelation('divisionSettings');
    }

    private function resetForms(): void
    {
        $this->selectedDistrictId = null;
        $this->schoolSearch = '';
        $this->districtName = '';
        $this->schoolFormDistrictId = null;
        $this->showSchoolFormModal = false;
        $this->schoolName = '';
        $this->schoolId = '';
        $this->schoolDivisions = [SchoolDivision::ELEMENTARY];
        $this->editingSchoolId = null;
        $this->editSchoolName = '';
        $this->editSchoolId = '';
        $this->editSchoolDivisions = [];
        $this->editing = null;
        $this->editingName = '';
        $this->showDeleteSchoolModal = false;
        $this->deleteSchoolId = null;
        $this->deleteSchoolLabel = '';
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.districts.manager');
    }
}
