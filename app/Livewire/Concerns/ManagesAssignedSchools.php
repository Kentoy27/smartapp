<?php

namespace App\Livewire\Concerns;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * The Assigned Schools picker — the searchable many-school selector a
 * Bookkeeper works on their own profile, and the administrator works on a
 * Bookkeeper's account.
 *
 * The list is the account's readable roster (`user_school_assignments`), the
 * same rows User::canAccessSchool() consults. It is edited here and saved by
 * the host component's own Save button — nothing in this trait writes to the
 * database, so a change the account never saved cannot take effect.
 *
 * The trait carries state, the add/remove actions the picker's menu calls,
 * and the validation rules for the saved list. The host component decides
 * WHO may edit the list (its own guard) and WHEN it is written (its save
 * path); the shared Blade partial renders it.
 */
trait ManagesAssignedSchools
{
    /**
     * The chosen schools' ids, in the order they were chosen — the value the
     * host component validates and saves.
     *
     * @var array<int, int>
     */
    public array $assigned_schools = [];

    /**
     * The picker's search box. It narrows the menu and nothing else — it is
     * never saved, and typing a school's name without picking it changes no
     * assignment.
     */
    public string $schoolSearch = '';

    /**
     * Fill the picker from an account's stored assignments. A role that
     * carries no roster is shown an empty list: the picker is only rendered
     * for the Bookkeeper, and a leftover selection must never ride along
     * into a save.
     */
    protected function loadAssignedSchools(User $account): void
    {
        $this->resetAssignedSchools();

        if ($account->isBookkeeperRole()) {
            $this->assigned_schools = $account->assignedSchoolIds();
        }
    }

    /** Empty the picker (a cancelled edit is discarded, never half-kept). */
    protected function resetAssignedSchools(): void
    {
        $this->assigned_schools = [];
        $this->schoolSearch = '';
        $this->forgetSelectedSchools();
    }

    /**
     * Add a school to the list. Called from the picker's menu; also called by
     * the host when a school is picked elsewhere in its form (the
     * administrator's base-school select), so what the modal shows is always
     * what will be saved.
     *
     * Only a school that exists is ever added — an id from the browser is a
     * request, not a fact — and a school already on the list is left where it
     * is rather than doubled.
     */
    public function addAssignedSchool(int $schoolId): void
    {
        if (! School::query()->whereKey($schoolId)->exists()) {
            return;
        }

        $ids = array_map(intval(...), $this->assigned_schools);

        if (! in_array($schoolId, $ids, true)) {
            $ids[] = $schoolId;
        }

        $this->assigned_schools = array_values(array_unique($ids));
        $this->resetValidation(['assigned_schools', 'assigned_schools.*']);
        $this->forgetSelectedSchools();
    }

    /**
     * Take a school off the list. Nothing is written here: the roster changes
     * when the host saves, so removing a chip and cancelling the modal leaves
     * the account exactly as it was.
     */
    public function removeAssignedSchool(int $schoolId): void
    {
        $this->assigned_schools = array_values(array_filter(
            array_map(intval(...), $this->assigned_schools),
            fn (int $id): bool => $id !== $schoolId,
        ));

        $this->resetValidation(['assigned_schools', 'assigned_schools.*']);
        $this->forgetSelectedSchools();
    }

    /**
     * The validation rules for the saved list, merged into the host form's
     * rules only when the form is actually editing a Bookkeeper's roster —
     * for every other role the field is not part of the form at all, so a
     * crafted payload is ignored rather than saved.
     *
     * Every id must be a real school (the database is the only authority on
     * what may be assigned) and duplicates are refused.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function assignedSchoolRules(): array
    {
        return [
            'assigned_schools' => ['array'],
            'assigned_schools.*' => ['integer', 'distinct', Rule::exists('schools', 'id')],
        ];
    }

    /**
     * Human-friendly field names for the list's error messages.
     *
     * @return array<string, string>
     */
    protected function assignedSchoolAttributes(): array
    {
        return ['assigned_schools' => 'assigned schools'];
    }

    /** Drop the chips' memoised schools after the list changes. */
    private function forgetSelectedSchools(): void
    {
        unset($this->selectedSchools);
    }

    /**
     * The chips: the chosen schools as records, in the order they were
     * chosen. An id that names no school is dropped rather than rendered.
     *
     * @return Collection<int, School>
     */
    #[Computed]
    public function selectedSchools(): Collection
    {
        $ids = array_values(array_unique(array_map(intval(...), $this->assigned_schools)));

        if ($ids === []) {
            return collect();
        }

        $schools = School::query()
            ->with('district')
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'school_id', 'district_id'])
            ->keyBy('id');

        return collect($ids)
            ->map(fn (int $id) => $schools->get($id))
            ->filter()
            ->values();
    }

    /**
     * Every school the picker may offer, by name. A deleted school is gone
     * from the schools table, so it can never be offered again — and a row
     * still pointing at it resolves to no chip.
     *
     * @return Collection<int, School>
     */
    #[Computed]
    public function schoolRoster(): Collection
    {
        return School::query()
            ->with('district')
            ->orderBy('name')
            ->get(['id', 'name', 'school_id', 'district_id']);
    }

    /**
     * The picker's menu: the roster narrowed by the search box. The term
     * matches the name, the DepEd school ID and the district, the same way
     * the checking pages' school search does.
     *
     * @return Collection<int, School>
     */
    #[Computed]
    public function schoolOptions(): Collection
    {
        $term = trim($this->schoolSearch);

        if ($term === '') {
            return $this->schoolRoster;
        }

        $needle = mb_strtolower($term);

        return $this->schoolRoster
            ->filter(fn (School $school): bool => str_contains(mb_strtolower((string) $school->name), $needle)
                || str_contains(mb_strtolower((string) $school->school_id), $needle)
                || str_contains(mb_strtolower((string) $school->district?->name), $needle))
            ->values();
    }

    /** Whether a school is on the list — the menu's check mark. */
    public function isSchoolSelected(int $schoolId): bool
    {
        return in_array($schoolId, array_map(intval(...), $this->assigned_schools), true);
    }
}
