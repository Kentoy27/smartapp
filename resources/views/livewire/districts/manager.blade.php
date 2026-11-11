{{-- The Manage Districts & Schools button lives in the static page shell
     (outside this component), so it dispatches an Alpine window event that
     lands here. --}}
<div class="district-manager" x-data x-on:open-districts.window="$wire.openModal($event.detail?.district ?? null)">
    {{-- DISTRICTS & SCHOOLS MODAL --}}
    <div class="modal-backdrop {{ $showModal ? 'is-open' : '' }}"
         @if ($showModal) @click="self && $wire.closeModal()" @endif
         role="presentation">
        <div class="modal modal--district" role="dialog" aria-modal="true" aria-labelledby="districtModalTitle" @click.stop>
            <div class="modal-head">
                <h2 id="districtModalTitle">Manage Districts &amp; Schools</h2>
                <button type="button" class="modal-close" wire:click="closeModal" aria-label="Close" title="Close">×</button>
            </div>

            <div class="district-modal-body">

                {{-- ADD DISTRICT --}}
                <form wire:submit="addDistrict" class="district-add-form">
                    <div class="field">
                        <label for="districtName">District name</label>
                        <input
                            id="districtName"
                            type="text"
                            placeholder="e.g. District I"
                            autocomplete="off"
                            wire:model="districtName"
                            @class(['error' => $errors->has('districtName')])
                        >
                        @error('districtName') <div class="error-text">{{ $message }}</div> @enderror
                    </div>
                    <div class="district-add-actions">
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="addDistrict">
                            <span wire:loading.remove wire:target="addDistrict">Add District</span>
                            <span wire:loading wire:target="addDistrict">Adding…</span>
                        </button>
                        <button
                            type="button"
                            class="btn-ghost"
                            wire:click="openSchoolForm({{ $selectedDistrictId ?? 'null' }})"
                            @disabled($this->districts->isEmpty())
                        >
                            <x-icon name="plus" :size="14" />
                            <span>Add School</span>
                        </button>
                    </div>
                </form>

                @error('deleteSchool') <div class="error-text">{{ $message }}</div> @enderror

                {{-- DISTRICT LIST: each row clickable — selecting a district
                     expands its schools table (name + School ID + actions). --}}
                <div class="district-list">
                    @forelse ($this->districts as $district)
                        <div class="district-block" wire:key="district-{{ $district->id }}">
                            <button
                                type="button"
                                class="district-toggle {{ $selectedDistrictId === $district->id ? 'is-selected' : '' }}"
                                wire:click="toggleDistrict({{ $district->id }})"
                                aria-expanded="{{ $selectedDistrictId === $district->id ? 'true' : 'false' }}"
                                aria-controls="district-schools-{{ $district->id }}"
                            >
                                <span class="district-toggle-name">
                                    <x-icon name="chevron-down" :size="14" class="district-caret" />
                                    {{ $district->name }}
                                </span>
                                <span class="district-toggle-count">{{ $district->schools_count }} {{ Str::plural('School', $district->schools_count) }}</span>
                            </button>

                            @if ($selectedDistrictId === $district->id)
                                <div class="district-schools-panel" id="district-schools-{{ $district->id }}">
                                    <div class="district-schools-head">
                                        <span class="district-schools-title">Schools</span>
                                        <div class="district-schools-tools">
                                            <input
                                                type="search"
                                                class="district-school-search"
                                                placeholder="Search schools…"
                                                wire:model.live.debounce.300ms="schoolSearch"
                                            >
                                        </div>
                                    </div>

                                    <div class="table-wrap">
                                        <table class="data-table district-school-table">
                                            <thead>
                                                <tr>
                                                    <th scope="col">School Name</th>
                                                    <th scope="col">School ID</th>
                                                    <th scope="col">Divisions</th>
                                                    <th scope="col">Users</th>
                                                    <th scope="col" class="district-actions-col">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($this->selectedDistrictSchools as $school)
                                                    <tr wire:key="school-{{ $school->id }}">
                                                        <td>
                                                            <span class="school-cell">
                                                                <x-icon name="school" :size="13" class="school-dot" />
                                                                {{ $school->name }}
                                                            </span>
                                                        </td>
                                                        <td><span class="badge">{{ $school->school_id !== null ? $school->school_id : '—' }}</span></td>
                                                        <td>
                                                            <span class="school-division-badges">
                                                                @foreach ($school->availableDivisions() as $division)
                                                                    <span class="badge">{{ \App\Support\SchoolDivision::label($division) }}</span>
                                                                @endforeach
                                                            </span>
                                                        </td>
                                                        <td>{{ $school->users_count }}</td>
                                                        <td class="district-actions-col">
                                                            <x-row-menu :label="'More actions for '.$school->name">
                                                                <x-row-menu-item wire:click="startEditSchool({{ $school->id }})" title="Edit {{ $school->name }}">
                                                                    <x-icon name="pencil" :size="15" />
                                                                    <span>Edit</span>
                                                                </x-row-menu-item>
                                                                <x-row-menu-item wire:click="openDeleteSchool({{ $school->id }})" title="Delete {{ $school->name }}" danger>
                                                                    <x-icon name="trash" :size="15" />
                                                                    <span>Delete</span>
                                                                </x-row-menu-item>
                                                            </x-row-menu>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                @if ($this->selectedDistrictSchools->isEmpty())
                                                    <tr>
                                                        <td colspan="5" class="table-empty">
                                                            {{ $schoolSearch !== '' ? 'No schools match your search.' : 'No schools yet — add the first one.' }}
                                                        </td>
                                                    </tr>
                                                @endif
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="district-panel-foot">
                                        <button type="button" class="district-action district-action--danger" wire:click="deleteDistrict({{ $district->id }})" wire:confirm="Delete {{ $district->name }} and all of its schools? This cannot be undone." title="Delete {{ $district->name }}">
                                            <x-icon name="trash" :size="13" /><span>Delete district</span>
                                        </button>
                                        <button type="button" class="district-action" wire:click="selectDistrict(null)">
                                            <x-icon name="chevron-down" :size="13" class="district-caret district-caret--up" /><span>Close</span>
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="table-empty">No districts yet — add the first one above.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- ADD SCHOOL MODAL --}}
    @if ($showSchoolFormModal)
        <div
            class="modal-backdrop is-open"
            @keydown.escape.window="$wire.cancelSchoolForm()"
            @click.self="$wire.cancelSchoolForm()"
            role="presentation"
        >
            <section class="modal modal--school-add" role="dialog" aria-modal="true" aria-labelledby="schoolAddTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="schoolAddTitle">Add School</h2>
                    <button type="button" class="modal-close" wire:click="cancelSchoolForm" aria-label="Close" title="Close">×</button>
                </div>

                <form wire:submit="addSchool" class="school-add-panel school-add-modal-form">
                    <div class="field">
                        <label for="schoolFormDistrictId">District</label>
                        <select
                            id="schoolFormDistrictId"
                            wire:model="schoolFormDistrictId"
                            @class(['error' => $errors->has('schoolFormDistrictId')])
                            required
                        >
                            <option value="">Select a district</option>
                            @foreach ($this->districts as $districtOption)
                                <option value="{{ $districtOption->id }}">{{ $districtOption->name }}</option>
                            @endforeach
                        </select>
                        @error('schoolFormDistrictId') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <fieldset class="field school-divisions-field">
                        <legend>Division</legend>
                        <div class="field-hint">Choose the division or divisions this school operates.</div>
                        <div class="school-division-options">
                            @foreach ($this->divisionOptions() as $option)
                                <label class="school-division-option">
                                    <input
                                        type="checkbox"
                                        value="{{ $option['value'] }}"
                                        wire:model.live="schoolDivisions"
                                    >
                                    <span>{{ $option['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('schoolDivisions') <div class="error-text">{{ $message }}</div> @enderror
                        @error('schoolDivisions.*') <div class="error-text">{{ $message }}</div> @enderror
                    </fieldset>

                    <div class="school-form-grid">
                        <div class="field">
                            <label for="schoolName">School Name</label>
                            <span class="input-icon">
                                <x-icon name="school" :size="16" />
                                <input
                                    id="schoolName"
                                    type="text"
                                    placeholder="e.g. ABC Elementary School"
                                    autocomplete="off"
                                    wire:model="schoolName"
                                    @class(['error' => $errors->has('schoolName')])
                                    required
                                >
                            </span>
                            @error('schoolName') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="field">
                            <label for="schoolId">School ID</label>
                            <span class="input-icon">
                                <x-icon name="shield" :size="16" />
                                <input
                                    id="schoolId"
                                    type="text"
                                    placeholder="e.g. 123456"
                                    autocomplete="off"
                                    inputmode="numeric"
                                    wire:model="schoolId"
                                    @class(['error' => $errors->has('schoolId')])
                                    required
                                >
                            </span>
                            @if (! $errors->has('schoolId'))
                                <div class="field-hint">Unique across all schools — it becomes the School Head's default login.</div>
                            @endif
                            @error('schoolId') <div class="error-text">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="school-edit-actions">
                        <button type="button" class="btn-ghost" wire:click="cancelSchoolForm">Cancel</button>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="addSchool">
                            <x-icon name="plus" :size="15" />
                            <span wire:loading.remove wire:target="addSchool">Add School</span>
                            <span wire:loading wire:target="addSchool">Adding…</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>
    @endif

    {{-- EDIT SCHOOL MODAL --}}
    @if ($editingSchoolId !== null)
        @php($editingSchool = $this->editingSchool)
        <div
            class="modal-backdrop is-open"
            @keydown.escape.window="$wire.cancelEditSchool()"
            @click.self="$wire.cancelEditSchool()"
            role="presentation"
        >
            <section class="modal modal--school-edit" role="dialog" aria-modal="true" aria-labelledby="schoolEditTitle" @click.stop>
                <div class="modal-head">
                    <div>
                        <h2 id="schoolEditTitle">Edit School</h2>
                        <p class="school-edit-subtitle">{{ $editingSchool->name }}</p>
                    </div>
                    <button type="button" class="modal-close" wire:click="cancelEditSchool" aria-label="Close" title="Close">×</button>
                </div>

                <form wire:submit="saveEditSchool" class="school-edit-form school-edit-modal-form">
                    <span class="school-form-chip" title="This school belongs to {{ $editingSchool->district?->name ?? 'an unassigned district' }}">
                        <x-icon name="school" :size="13" />
                        <span>{{ $editingSchool->district?->name ?? 'Unassigned district' }}</span>
                    </span>

                    <div class="school-form-grid">
                        <div class="field">
                            <label for="editSchoolName-{{ $editingSchool->id }}">School Name</label>
                            <span class="input-icon">
                                <x-icon name="school" :size="16" />
                                <input
                                    id="editSchoolName-{{ $editingSchool->id }}"
                                    type="text"
                                    placeholder="e.g. ABC Elementary School"
                                    autocomplete="off"
                                    wire:model="editSchoolName"
                                    @class(['error' => $errors->has('editSchoolName')])
                                    required
                                >
                            </span>
                            @error('editSchoolName') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="field">
                            <label for="editSchoolId-{{ $editingSchool->id }}">School ID</label>
                            <span class="input-icon">
                                <x-icon name="shield" :size="16" />
                                <input
                                    id="editSchoolId-{{ $editingSchool->id }}"
                                    type="text"
                                    placeholder="e.g. 123456"
                                    autocomplete="off"
                                    inputmode="numeric"
                                    wire:model="editSchoolId"
                                    @class(['error' => $errors->has('editSchoolId')])
                                    required
                                >
                            </span>
                            @if (! $errors->has('editSchoolId'))
                                <div class="field-hint">Must stay unique across all schools. Existing accounts keep the login they were given.</div>
                            @endif
                            @error('editSchoolId') <div class="error-text">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <fieldset class="field school-divisions-field">
                        <legend>Divisions</legend>
                        <div class="field-hint">The divisions this school operates. The user form offers only these.</div>
                        <div class="school-division-options">
                            @foreach ($this->divisionOptions() as $option)
                                <label class="school-division-option">
                                    <input
                                        type="checkbox"
                                        value="{{ $option['value'] }}"
                                        wire:model.live="editSchoolDivisions"
                                    >
                                    <span>{{ $option['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('editSchoolDivisions') <div class="error-text">{{ $message }}</div> @enderror
                        @error('editSchoolDivisions.*') <div class="error-text">{{ $message }}</div> @enderror
                    </fieldset>

                    <div class="school-edit-actions">
                        <button type="button" class="btn-ghost" wire:click="cancelEditSchool">Cancel</button>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="saveEditSchool">
                            <x-icon name="pen-line" :size="15" />
                            <span wire:loading.remove wire:target="saveEditSchool">Save Changes</span>
                            <span wire:loading wire:target="saveEditSchool">Saving…</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>
    @endif

    {{-- DELETE SCHOOL CONFIRMATION --}}
    @if ($showDeleteSchoolModal)
        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.closeDeleteSchool()"
            @click.self="$wire.closeDeleteSchool()"
            role="presentation"
        >
            <div class="modal modal--confirmation" role="dialog" aria-modal="true" aria-labelledby="deleteSchoolTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="deleteSchoolTitle">Delete School</h2>
                    <button type="button" class="modal-close" wire:click="closeDeleteSchool" aria-label="Close" title="Close">×</button>
                </div>

                <div class="modal-form">
                    <p class="delete-modal-description">
                        Are you sure you want to delete
                        <strong>{{ $deleteSchoolLabel }}</strong>?
                        This cannot be undone. A school that still has user
                        accounts cannot be deleted.
                    </p>

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeDeleteSchool">Cancel</button>
                        <button type="button" class="btn-danger" wire:click="confirmDeleteSchool" wire:loading.attr="disabled" wire:target="confirmDeleteSchool">
                            <span wire:loading.remove wire:target="confirmDeleteSchool">Delete School</span>
                            <span wire:loading wire:target="confirmDeleteSchool">Deleting…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
