{{-- THE ASSIGNED SCHOOLS PICKER — the searchable many-school selector shared
     by the Bookkeeper's own profile modal and the administrator's user modal.

     The chips are the list as it stands; the search box narrows the menu
     only, and a school joins or leaves the list by picking it in the menu (or
     removing its chip). Nothing here saves — the modal's own Save button
     writes the list, so a change that was never saved cannot take effect.

     The menu's open/closed state is a class (like the matrix editor's
     combobox), so a Livewire re-render never resurrects it mid-typing.

     Expected variables:
       $label    string  the field's label
       $hint     string  the helper line under the label
       $inputId  string  a unique id prefix, so two modals on one page never
                         collide on ids or wire:keys

     Reads the host component's own state through the ManagesAssignedSchools
     trait: $assigned_schools, $schoolSearch, selectedSchools, schoolOptions,
     schoolRoster, addAssignedSchool(), removeAssignedSchool(). --}}
@php($chosenIds = array_map('intval', $assigned_schools))

<div class="field assigned-schools-field">
    <label for="{{ $inputId }}">{{ $label }}</label>

    @if (trim($hint) !== '')
        <div class="field-hint">{{ $hint }}</div>
    @endif

    {{-- THE CHOSEN SCHOOLS, as removable chips. --}}
    <div class="assigned-schools-chips" role="list" aria-label="{{ $label }}">
        @forelse ($this->selectedSchools as $school)
            <span
                class="assigned-school-chip"
                role="listitem"
                wire:key="{{ $inputId }}-chip-{{ $school->id }}"
            >
                <span
                    class="assigned-school-chip__name"
                    title="{{ $school->district?->name ?? 'No district' }}@if ($school->school_id) · {{ $school->school_id }}@endif"
                >{{ $school->name }}</span>

                <button
                    type="button"
                    class="assigned-school-chip__remove"
                    wire:click="removeAssignedSchool({{ $school->id }})"
                    wire:loading.attr="disabled"
                    wire:target="removeAssignedSchool"
                    aria-label="Remove {{ $school->name }}"
                    title="Remove {{ $school->name }}"
                >
                    <x-icon name="x" :size="12" />
                </button>
            </span>
        @empty
            <span class="assigned-schools-none">
                No schools selected yet — search below and pick every school this account is responsible for.
            </span>
        @endforelse
    </div>

    <div class="assigned-schools-meta">
        <span class="assigned-schools-count">
            {{ count($chosenIds) }} {{ count($chosenIds) === 1 ? 'school' : 'schools' }} selected
        </span>

        <span
            class="assigned-schools-loading"
            wire:loading.delay
            wire:target="schoolSearch,addAssignedSchool,removeAssignedSchool"
        >Updating…</span>
    </div>

    {{-- THE SEARCH BOX AND ITS MENU. --}}
    <div class="assigned-schools-search" x-data="{ open: false }" @click.outside="open = false">
        <div class="mem-combo">
            <span class="mem-combo__search" aria-hidden="true">
                <x-icon name="search" :size="15" />
            </span>

            <input
                id="{{ $inputId }}"
                type="text"
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="{{ $inputId }}Options"
                :aria-expanded="String(open)"
                wire:model.live.debounce.300ms="schoolSearch"
                placeholder="Search schools…"
                @focus="open = true"
                @input="open = true"
                @keydown.escape="open = false"
                {{-- Enter picks nothing and must not submit the surrounding
                     modal form mid-search. --}}
                @keydown.enter.prevent="open = true"
            >

            @if (trim($schoolSearch) !== '')
                <button
                    type="button"
                    class="mem-combo__clear"
                    wire:click="$set('schoolSearch', '')"
                    aria-label="Clear school search"
                    title="Clear"
                >
                    <x-icon name="x" :size="14" />
                </button>
            @endif

            <div
                id="{{ $inputId }}Options"
                class="mem-combo__menu"
                role="listbox"
                aria-multiselectable="true"
                :class="open ? 'open' : ''"
            >
                @forelse ($this->schoolOptions as $option)
                    @php($isChosen = in_array($option->id, $chosenIds, true))

                    <button
                        type="button"
                        role="option"
                        class="mem-combo__option"
                        wire:key="{{ $inputId }}-option-{{ $option->id }}"
                        wire:click="{{ $isChosen ? 'removeAssignedSchool' : 'addAssignedSchool' }}({{ $option->id }})"
                        aria-selected="{{ $isChosen ? 'true' : 'false' }}"
                    >
                        <span class="mem-combo__option-main">
                            <span class="mem-combo__option-title">{{ $option->name }}</span>
                            <span class="mem-combo__option-sub">
                                {{ $option->district?->name ?? 'No district' }}@if ($option->school_id) · {{ $option->school_id }}@endif
                            </span>
                        </span>

                        @if ($isChosen)
                            <span class="mem-combo__option-meta" title="Assigned — pick again to remove">
                                <x-icon name="check" :size="14" />
                            </span>
                        @endif
                    </button>
                @empty
                    <div class="mem-combo__empty">
                        @if ($this->schoolRoster->isEmpty())
                            No schools are registered yet, so there is nothing to assign.
                        @else
                            No schools match “{{ trim($schoolSearch) }}”.
                        @endif
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    @error('assigned_schools') <div class="error-text">{{ $message }}</div> @enderror
    @error('assigned_schools.*') <div class="error-text">{{ $message }}</div> @enderror
</div>
