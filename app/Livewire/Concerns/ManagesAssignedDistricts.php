<?php

namespace App\Livewire\Concerns;

use App\Models\District;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * The Assigned District(s) picker — the searchable many-district selector an
 * administrator works on a PSDS account.
 *
 * The list is the account's monitoring reach (`user_district_assignments`),
 * the same rows User::canAccessDistrict() and the monitoring pages consult.
 * It is edited here and saved by the host component's own Save button —
 * nothing in this trait writes to the database, so a change the
 * administrator never saved cannot take effect.
 *
 * The trait carries state, the add/remove actions the picker's menu calls,
 * and the validation rules for the saved list. The host component decides
 * WHO may edit the list (its own guard) and WHEN it is written (its save
 * path); the shared Blade partial renders it.
 */
trait ManagesAssignedDistricts
{
    /**
     * The chosen districts' ids, in the order they were chosen — the value
     * the host component validates and saves.
     *
     * @var array<int, int>
     */
    public array $assigned_districts = [];

    /**
     * The picker's search box. It narrows the menu and nothing else — it is
     * never saved, and typing a district's name without picking it changes
     * no assignment.
     */
    public string $districtSearch = '';

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
        $this->districtSearch = '';
        $this->forgetSelectedDistricts();
    }

    /**
     * Add a district to the list. Called from the picker's menu.
     *
     * Only a district that exists is ever added — an id from the browser is
     * a request, not a fact — and a district already on the list is left
     * where it is rather than doubled.
     */
    public function addAssignedDistrict(int $districtId): void
    {
        if (! District::query()->whereKey($districtId)->exists()) {
            return;
        }

        $ids = array_map(intval(...), $this->assigned_districts);

        if (! in_array($districtId, $ids, true)) {
            $ids[] = $districtId;
        }

        $this->assigned_districts = array_values(array_unique($ids));
        $this->resetValidation(['assigned_districts', 'assigned_districts.*']);
        $this->forgetSelectedDistricts();
    }

    /**
     * Take a district off the list. Nothing is written here: the reach
     * changes when the host saves, so removing a chip and cancelling the
     * modal leaves the account exactly as it was.
     */
    public function removeAssignedDistrict(int $districtId): void
    {
        $this->assigned_districts = array_values(array_filter(
            array_map(intval(...), $this->assigned_districts),
            fn (int $id): bool => $id !== $districtId,
        ));

        $this->resetValidation(['assigned_districts', 'assigned_districts.*']);
        $this->forgetSelectedDistricts();
    }

    /**
     * The validation rules for the saved list, merged into the host form's
     * rules only when the form is actually editing a PSDS's reach — for
     * every other role the field is not part of the form at all, so a
     * crafted payload is ignored rather than saved.
     *
     * The list is required: a PSDS without a district monitors nothing, so
     * an empty list is a form error, not an empty dashboard. Every id must
     * be a real district (the database is the only authority on what may be
     * assigned) and duplicates are refused.
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

    /** Drop the chips' memoised districts after the list changes. */
    private function forgetSelectedDistricts(): void
    {
        unset($this->selectedDistricts);
    }

    /**
     * The chips: the chosen districts as records, in the order they were
     * chosen, each with its school count for the read-only preview. An id
     * that names no district is dropped rather than rendered.
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

    /**
     * The picker's menu: the roster narrowed by the search box. The term
     * matches the district's name.
     *
     * @return Collection<int, District>
     */
    #[Computed]
    public function districtOptions(): Collection
    {
        $term = trim($this->districtSearch);

        if ($term === '') {
            return $this->districtRoster;
        }

        $needle = mb_strtolower($term);

        return $this->districtRoster
            ->filter(fn (District $district): bool => str_contains(mb_strtolower((string) $district->name), $needle))
            ->values();
    }

    /** Whether a district is on the list — the menu's check mark. */
    public function isDistrictSelected(int $districtId): bool
    {
        return in_array($districtId, array_map(intval(...), $this->assigned_districts), true);
    }
}
