<?php

namespace App\Livewire;

use App\Models\District;
use App\Models\School;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Superadmin-only district management.
 *
 * Lives in a modal on the dashboard ("View District" button): a table of
 * districts with the schools under each, plus add / rename / delete for
 * both levels. The dashboard hosts it via <livewire:district-manager />.
 */
class DistrictManager extends Component
{
    public bool $showModal = false;

    public string $districtName = '';

    /** District the "add school" form is currently open for. */
    public ?int $schoolFormDistrictId = null;

    public string $schoolName = '';

    /** Inline rename state: ['type' => 'district'|'school', 'id' => int]. */
    public ?array $editing = null;

    public string $editingName = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);
    }

    #[Computed]
    public function districts()
    {
        return District::query()
            ->with('schools')
            ->withCount('schools')
            ->orderBy('name')
            ->get();
    }

    public function openModal(): void
    {
        $this->showModal = true;
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

        District::create(['name' => trim($this->districtName)]);

        $this->districtName = '';
        $this->dispatch('district-added');
    }

    public function openSchoolForm(int $districtId): void
    {
        $this->schoolFormDistrictId = $districtId;
        $this->schoolName = '';
        $this->editing = null;
        $this->resetValidation();
    }

    public function cancelSchoolForm(): void
    {
        $this->schoolFormDistrictId = null;
        $this->schoolName = '';
        $this->resetValidation();
    }

    public function addSchool(): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

        $this->validate([
            'schoolName' => [
                'required', 'string', 'max:160',
                // Unique only within the district being added to.
                'unique:schools,name,NULL,id,district_id,' . $this->schoolFormDistrictId,
            ],
        ], [
            'schoolName.unique' => 'This school already exists in the district.',
        ]);

        School::create([
            'district_id' => $this->schoolFormDistrictId,
            'name' => trim($this->schoolName),
        ]);

        $this->schoolName = '';
        $this->dispatch('district-added');
    }

    /* ---------- RENAME (inline) ---------- */

    public function startRename(string $type, int $id): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

        $this->editingName = $type === 'district'
            ? District::findOrFail($id)->name
            : School::findOrFail($id)->name;

        $this->editing = ['type' => $type, 'id' => $id];
        $this->schoolFormDistrictId = null;
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
        $this->dispatch('district-added');
    }

    /* ---------- DELETE ---------- */

    public function deleteDistrict(int $id): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

        // Schools cascade via the foreign key.
        District::whereKey($id)->delete();

        if ($this->schoolFormDistrictId === $id) {
            $this->cancelSchoolForm();
        }

        $this->dispatch('district-added');
    }

    public function deleteSchool(int $id): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

        School::whereKey($id)->delete();
        $this->dispatch('district-added');
    }

    private function resetForms(): void
    {
        $this->districtName = '';
        $this->schoolFormDistrictId = null;
        $this->schoolName = '';
        $this->editing = null;
        $this->editingName = '';
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.districts.manager');
    }
}
