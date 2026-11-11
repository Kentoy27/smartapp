<?php

namespace App\Livewire\Concerns;

use App\Models\District;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * The Assigned District(s) picker — the multi-district selector an
 * administrator works on a PSDS account.
 *
 * The list is the account's monitoring reach (`user_district_assignments`),
 * the same rows User::canAccessDistrict() and the monitoring pages consult.
 * It is edited here and saved by the host component's own Save button —
 * nothing in this trait writes to the database, so a change the
 * administrator never saved cannot take effect.
 *
 * The trait carries the selected districts, their add/remove actions, and
 * validation rules. The host decides
 * WHO may edit the list (its own guard) and WHEN it is written (its save
 * path); the shared Blade partial renders it.
 */
trait ManagesAssignedDistricts
{
    /**
     * The chosen district ids in selection order.
     *
     * @var array<int, int>
     */
    public array $assigned_districts = [];

    /**
     * Fill the picker from an account's stored assignments. A role that
     * carries no district reach is shown an empty list: the picker is only
     * rendered for the PSDS, and a leftover selection must never ride along
     * into a save.
     */
    protected function loadAssignedDistricts(User $account): void
    {
        $this->resetAssignedDistricts();

        if ($account->isPsdsRole()) {
            $this->assigned_districts = $account->assignedDistrictIds();
        }
    }

    /** Empty the picker (a cancelled edit is discarded, never half-kept). */
    protected function resetAssignedDistricts(): void
    {
        $this->assigned_districts = [];
        $this->forgetSelectedDistricts();
    }

    /**
     * Add the chosen district without removing earlier selections.
     */
    public function addAssignedDistrict(int $districtId): void
    {
        if (! District::query()->whereKey($districtId)->exists()) {
            $this->addError('districtToAdd', 'Select a valid district.');

            return;
        }

        $ids = array_map(intval(...), $this->assigned_districts);

        if (! in_array($districtId, $ids, true)) {
            $ids[] = $districtId;
        }

        $this->assigned_districts = array_values(array_unique($ids));
        $this->resetValidation(['assigned_districts', 'assigned_districts.*']);
        $this->resetValidation('districtToAdd');
        $this->forgetSelectedDistricts();
    }

    /**
     * Remove a district from the unsaved assignment list.
     */
    public function removeAssignedDistrict(int $districtId): void
    {
        $this->assigned_districts = array_values(array_filter(
            array_map(intval(...), $this->assigned_districts),
            fn (int $id): bool => $id !== $districtId,
        ));

        $this->resetValidation(['assigned_districts', 'assigned_districts.*']);
        $this->resetValidation('districtToAdd');
        $this->forgetSelectedDistricts();
    }

    /**
     * The validation rules for the saved list, merged into the host form's
     * rules only when the form is actually editing a PSDS's reach — for
     * every other role the field is not part of the form at all, so a
     * crafted payload is ignored rather than saved.
     *
     * A PSDS must be assigned at least one real district; duplicate and
     * nonexistent district ids are rejected.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function assignedDistrictRules(): array
    {
        return [
            'assigned_districts' => ['required', 'array', 'min:1'],
            'assigned_districts.*' => ['integer', 'distinct', Rule::exists('districts', 'id')],
        ];
    }

    /**
     * Human-friendly field names for the list's error messages.
     *
     * @return array<string, string>
     */
    protected function assignedDistrictAttributes(): array
    {
        return ['assigned_districts' => 'assigned districts'];
    }

    private function forgetSelectedDistricts(): void
    {
        unset($this->selectedDistricts);
    }

    /**
     * Selected districts as records, in selection order, for the chips.
     *
     * @return Collection<int, District>
     */
    #[Computed]
    public function selectedDistricts(): Collection
    {
        $ids = array_values(array_unique(array_map(intval(...), $this->assigned_districts)));

        if ($ids === []) {
            return collect();
        }

        $districts = District::query()
            ->withCount('schools')
            ->whereIn('id', $ids)
            ->get(['id', 'name'])
            ->keyBy('id');

        return collect($ids)
            ->map(fn (int $id) => $districts->get($id))
            ->filter()
            ->values();
    }

    /**
     * Every district the picker may offer, by name. A deleted district is
     * gone from the districts table, so it can never be offered again — and
     * a row still pointing at it resolves to no chip.
     *
     * @return Collection<int, District>
     */
    #[Computed]
    public function districtRoster(): Collection
    {
        return District::query()
            ->withCount('schools')
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
