{{-- THE ASSIGNED DISTRICT(S) PICKER — multiple districts can be assigned.

     Selecting a district adds it immediately; assigned districts appear as
     removable chips above. The host form's Save button persists the list.

     Expected variables:
       $label    string  the field's label
       $hint     string  the helper line under the label
       $inputId  string  a unique id prefix for this input
--}}
@php($chosenIds = array_map('intval', $assigned_districts))
@php($availableDistricts = $this->districtRoster->reject(fn ($district) => in_array($district->id, $chosenIds, true)))

<div class="field assigned-schools-field">
    <label for="{{ $inputId }}">{{ $label }}</label>

    @if (trim($hint) !== '')
        <div class="field-hint">{{ $hint }}</div>
    @endif

    <div class="assigned-schools-chips" role="list" aria-label="{{ $label }}">
        @forelse ($this->selectedDistricts as $district)
            <span
                class="assigned-school-chip"
                role="listitem"
                wire:key="{{ $inputId }}-chip-{{ $district->id }}"
            >
                <span
                    class="assigned-school-chip__name"
                    title="{{ $district->schools_count }} {{ $district->schools_count === 1 ? 'school' : 'schools' }} in this district"
                >{{ $district->name }}</span>

                <button
                    type="button"
                    class="assigned-school-chip__remove"
                    wire:click="removeAssignedDistrict({{ $district->id }})"
                    wire:loading.attr="disabled"
                    wire:target="removeAssignedDistrict"
                    aria-label="Remove {{ $district->name }}"
                    title="Remove {{ $district->name }}"
                >
                    <x-icon name="x" :size="12" />
                </button>
            </span>
        @empty
            <span class="assigned-schools-none">
                No districts selected yet — select every district this PSDS supervises.
            </span>
        @endforelse
    </div>

    <div class="assigned-schools-meta">
        <span class="assigned-schools-count">
            {{ count($chosenIds) }} {{ count($chosenIds) === 1 ? 'district' : 'districts' }} selected
        </span>

        <span
            class="assigned-schools-loading"
            wire:loading.delay
            wire:target="addAssignedDistrict,removeAssignedDistrict"
        >Updating…</span>
    </div>

    <div class="assigned-districts-select">
        <select
            id="{{ $inputId }}"
            wire:change="addAssignedDistrict($event.target.value)"
            @class(['error' => $errors->has('districtToAdd')])
            aria-label="Select a district to assign"
        >
            <option value="" disabled selected>Select a district…</option>
            @foreach ($availableDistricts as $district)
                <option value="{{ $district->id }}">
                    {{ $district->name }} ({{ $district->schools_count }} {{ $district->schools_count === 1 ? 'school' : 'schools' }})
                </option>
            @endforeach
        </select>
    </div>

    @if ($this->districtRoster->isEmpty())
        <div class="field-hint">No districts are registered yet, so there is nothing to assign.</div>
    @elseif ($availableDistricts->isEmpty())
        <div class="field-hint">All available districts are assigned.</div>
    @endif

    @error('districtToAdd') <div class="error-text">{{ $message }}</div> @enderror
    @error('assigned_districts') <div class="error-text">{{ $message }}</div> @enderror
    @error('assigned_districts.*') <div class="error-text">{{ $message }}</div> @enderror
</div>
