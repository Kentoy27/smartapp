<?php

namespace App\Livewire;

use App\Models\District;
use App\Models\School;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Superadmin-only district & school management.
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
     * The add-school form: opens under $schoolFormDistrictId, whose name
     * the modal shows pre-filled and read-only.
     */
    public ?int $schoolFormDistrictId = null;

    public string $schoolName = '';

    public string $schoolId = '';

    /**
     * The edit-school form: opens for $editingSchoolId.
     */
    public ?int $editingSchoolId = null;

    public string $editSchoolName = '';

    public string $editSchoolId = '';

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
        abort_unless(Auth::user()?->is_superadmin, 404);
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
        abort_unless(Auth::user()?->is_superadmin, 404);

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

    /* ---------- ADD SCHOOL ---------- */

    public function openSchoolForm(int $districtId): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

        $this->schoolFormDistrictId = $districtId;
        $this->schoolName = '';
        $this->schoolId = '';
        $this->cancelEditSchool();
        $this->resetValidation();
    }

    public function cancelSchoolForm(): void
    {
        $this->schoolFormDistrictId = null;
        $this->schoolName = '';
        $this->schoolId = '';
        $this->resetValidation();
    }

    public function addSchool(): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

        $this->validate([
            // Unique only within the district being added to.
            'schoolName' => [
                'required', 'string', 'max:160',
                'unique:schools,name,NULL,id,district_id,'.$this->schoolFormDistrictId,
            ],
            // The DepEd school ID is unique across the whole system.
            'schoolId' => ['required', 'string', 'max:20', 'unique:schools,school_id'],
        ], [
            'schoolName.required' => 'The school name is required.',
            'schoolName.unique' => 'This school already exists in the district.',
            'schoolId.required' => 'The School ID is required.',
            'schoolId.unique' => 'That School ID is already used by another school.',
        ]);

        $school = School::create([
            'district_id' => $this->schoolFormDistrictId,
            'name' => trim($this->schoolName),
            'school_id' => trim($this->schoolId),
        ]);

        $this->schoolName = '';
        $this->schoolId = '';
        $this->selectedDistrictId = $school->district_id;
        $this->schoolFormDistrictId = null;
        $this->dispatch('districts-changed');
    }

    /* ---------- EDIT SCHOOL ---------- */

    public function startEditSchool(int $schoolId): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

        $school = School::findOrFail($schoolId);

        $this->editingSchoolId = $school->id;
        $this->editSchoolName = $school->name;
        $this->editSchoolId = (string) $school->school_id;
        $this->cancelSchoolForm();
        $this->resetValidation();
    }

    public function cancelEditSchool(): void
    {
        $this->editingSchoolId = null;
        $this->editSchoolName = '';
        $this->editSchoolId = '';
        $this->resetValidation();
    }

    public function saveEditSchool(): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

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
        ], [
            'editSchoolName.required' => 'The school name is required.',
            'editSchoolName.unique' => 'This school already exists in the district.',
            'editSchoolId.required' => 'The School ID is required.',
            'editSchoolId.unique' => 'That School ID is already used by another school.',
        ]);

        $school->update([
            'name' => trim($this->editSchoolName),
            'school_id' => trim($this->editSchoolId),
        ]);

        $this->cancelEditSchool();
        $this->dispatch('districts-changed');
    }

    /* ---------- DELETE SCHOOL ---------- */

    public function openDeleteSchool(int $schoolId): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

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
        abort_unless(Auth::user()?->is_superadmin, 404);

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
        abort_unless(Auth::user()?->is_superadmin, 404);

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
        abort_unless(Auth::user()?->is_superadmin, 404);

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
        abort_unless(Auth::user()?->is_superadmin, 404);

        // Schools cascade via the foreign key.
        District::whereKey($id)->delete();

        if ($this->selectedDistrictId === $id) {
            $this->selectDistrict(null);
        }

        $this->dispatch('districts-changed');
    }

    private function resetForms(): void
    {
        $this->selectedDistrictId = null;
        $this->schoolSearch = '';
        $this->districtName = '';
        $this->schoolFormDistrictId = null;
        $this->schoolName = '';
        $this->schoolId = '';
        $this->editingSchoolId = null;
        $this->editSchoolName = '';
        $this->editSchoolId = '';
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
