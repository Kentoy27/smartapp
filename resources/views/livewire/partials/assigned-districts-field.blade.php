{{-- THE ASSIGNED DISTRICT(S) PICKER — the searchable many-district selector the
     administrator works on a PSDS account.

     The chips are the list as it stands; the search box narrows the menu
     only, and a district joins or leaves the list by picking it in the menu
     (or removing its chip). Nothing here saves — the modal's own Save button
     writes the list, so a change that was never saved cannot take effect.

     Each chip carries the district's school count, the read-only preview of
     what the assignment grants: the PSDS monitors every school currently in
     the district, so no school is ever assigned individually.

     The menu's open/closed state is a class (like the matrix editor's
     combobox), so a Livewire re-render never resurrects it mid-typing.

     Expected variables:
       $label    string  the field's label
       $hint     string  the helper line under the label
       $inputId  string  a unique id prefix, so two modals on one page never
                         collide on ids or wire:keys

     Reads the host component's own state through the ManagesAssignedDistricts
     trait: $assigned_districts, $districtSearch, selectedDistricts,
     districtOptions, districtRoster, addAssignedDistrict(),
     removeAssignedDistrict(). --}}
@php($chosenIds = array_map('intval', $assigned_districts))

<div class="field assigned-schools-field">
    <label for="{{ $inputId }}">{{ $label }}</label>

    @if (trim($hint) !== '')
        <div class="field-hint">{{ $hint }}</div>
    @endif

    {{-- THE CHOSEN DISTRICTS, as removable chips. --}}
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
                No districts selected yet — search below and pick every district this PSDS supervises.
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
            wire:target="districtSearch,addAssignedDistrict,removeAssignedDistrict"
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
                wire:model.live.debounce.300ms="districtSearch"
                placeholder="Search districts…"
                @focus="open = true"
                @input="open = true"
                @keydown.escape="open = false"
                {{-- Enter picks nothing and must not submit the surrounding
                     modal form mid-search. --}}
                @keydown.enter.prevent="open = true"
            >

            @if (trim($districtSearch) !== '')
                <button
                    type="button"
                    class="mem-combo__clear"
                    wire:click="$set('districtSearch', '')"
                    aria-label="Clear district search"
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
                @forelse ($this->districtOptions as $option)
                    @php($isChosen = in_array($option->id, $chosenIds, true))

                    <button
                        type="button"
                        role="option"
                        class="mem-combo__option"
                        wire:key="{{ $inputId }}-option-{{ $option->id }}"
                        wire:click="{{ $isChosen ? 'removeAssignedDistrict' : 'addAssignedDistrict' }}({{ $option->id }})"
                        aria-selected="{{ $isChosen ? 'true' : 'false' }}"
                    >
                        <span class="mem-combo__option-main">
                            <span class="mem-combo__option-title">{{ $option->name }}</span>
                            <span class="mem-combo__option-sub">
                                {{ $option->schools_count }} {{ $option->schools_count === 1 ? 'school' : 'schools' }}
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
                        @if ($this->districtRoster->isEmpty())
                            No districts are registered yet, so there is nothing to assign.
                        @else
                            No districts match “{{ trim($districtSearch) }}”.
                        @endif
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    @error('assigned_districts') <div class="error-text">{{ $message }}</div> @enderror
    @error('assigned_districts.*') <div class="error-text">{{ $message }}</div> @enderror
</div>
