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
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="addDistrict">
                        <span wire:loading.remove wire:target="addDistrict">Add District</span>
                        <span wire:loading wire:target="addDistrict">Adding…</span>
                    </button>
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
                                wire:click="selectDistrict({{ $district->id }})"
                            >
                                <span class="district-toggle-name">
                                    <x-icon name="chevron-down" :size="14" class="district-caret" />
                                    {{ $district->name }}
                                </span>
                                <span class="district-toggle-count">{{ $district->schools_count }} {{ Str::plural('School', $district->schools_count) }}</span>
                            </button>

                            @if ($selectedDistrictId === $district->id)
                                <div class="district-schools-panel">
                                    <div class="district-schools-head">
                                        <span class="district-schools-title">Schools</span>
                                        <div class="district-schools-tools">
                                            <input
                                                type="search"
                                                class="district-school-search"
                                                placeholder="Search schools…"
                                                wire:model.live.debounce.300ms="schoolSearch"
                                            >
                                            <button
                                                type="button"
                                                class="btn-primary btn-primary--small"
                                                wire:click="openSchoolForm({{ $district->id }})"
                                            >
                                                <x-icon name="plus" :size="13" />
                                                <span>Add School</span>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="table-wrap">
                                        <table class="data-table district-school-table">
                                            <thead>
                                                <tr>
                                                    <th scope="col">School Name</th>
                                                    <th scope="col">School ID</th>
                                                    <th scope="col">Users</th>
                                                    <th scope="col" class="district-actions-col">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($this->selectedDistrictSchools as $school)
                                                    @if ($editingSchoolId === $school->id)
                                                        <tr wire:key="school-edit-{{ $school->id }}">
                                                            <td colspan="4">
                                                                <form wire:submit="saveEditSchool" class="school-edit-form">
                                                                    {{-- Same panel as Add School: a header that says what
                                                                         is being edited and where it lives, the same two
                                                                         field grid, and the same separated action row. --}}
                                                                    <div class="school-form-head">
                                                                        <span class="school-form-title">
                                                                            <x-icon name="pen-line" :size="15" />
                                                                            <span>Edit School</span>
                                                                        </span>
                                                                        <span class="school-form-chip" title="This school belongs to {{ $district->name }}">
                                                                            <x-icon name="school" :size="13" />
                                                                            <span>{{ $district->name }}</span>
                                                                        </span>
                                                                    </div>

                                                                    <div class="school-form-grid">
                                                                        <div class="field">
                                                                            <label for="editSchoolName-{{ $school->id }}">School Name</label>
                                                                            <span class="input-icon">
                                                                                <x-icon name="school" :size="16" />
                                                                                <input id="editSchoolName-{{ $school->id }}" type="text" placeholder="e.g. ABC Elementary School" autocomplete="off" wire:model="editSchoolName" @class(['error' => $errors->has('editSchoolName')])>
                                                                            </span>
                                                                            @error('editSchoolName') <div class="error-text">{{ $message }}</div> @enderror
                                                                        </div>

                                                                        <div class="field">
                                                                            <label for="editSchoolId-{{ $school->id }}">School ID</label>
                                                                            <span class="input-icon">
                                                                                <x-icon name="shield" :size="16" />
                                                                                <input id="editSchoolId-{{ $school->id }}" type="text" placeholder="e.g. 123456" autocomplete="off" inputmode="numeric" wire:model="editSchoolId" @class(['error' => $errors->has('editSchoolId')])>
                                                                            </span>
                                                                            {{-- Changing an ID never rewrites the logins
                                                                                 already created from it. --}}
                                                                            @if (! $errors->has('editSchoolId'))
                                                                                <div class="field-hint">Must stay unique across all schools. Existing accounts keep the login they were given.</div>
                                                                            @endif
                                                                            @error('editSchoolId') <div class="error-text">{{ $message }}</div> @enderror
                                                                        </div>
                                                                    </div>

                                                                    <div class="school-edit-actions">
                                                                        <button type="button" class="btn-ghost" wire:click="cancelEditSchool">Cancel</button>
                                                                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="saveEditSchool">
                                                                            <x-icon name="pen-line" :size="15" />
                                                                            <span wire:loading.remove wire:target="saveEditSchool">Save Changes</span>
                                                                            <span wire:loading wire:target="saveEditSchool">Saving…</span>
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    @else
                                                        <tr wire:key="school-{{ $school->id }}">
                                                            <td>
                                                                <span class="school-cell">
                                                                    <x-icon name="school" :size="13" class="school-dot" />
                                                                    {{ $school->name }}
                                                                </span>
                                                            </td>
                                                            <td><span class="badge">{{ $school->school_id !== null ? $school->school_id : '—' }}</span></td>
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
                                                    @endif
                                                @endforeach
                                                @if ($this->selectedDistrictSchools->isEmpty())
                                                    <tr>
                                                        <td colspan="4" class="table-empty">
                                                            {{ $schoolSearch !== '' ? 'No schools match your search.' : 'No schools yet — add the first one.' }}
                                                        </td>
                                                    </tr>
                                                @endif
                                            </tbody>
                                        </table>
                                    </div>

                                    {{-- ADD SCHOOL FORM: the district is fixed —
                                         the school lands in the selected district, so the
                                         district reads as context (a chip in the header)
                                         rather than a field the superadmin could change. --}}
                                    @if ($schoolFormDistrictId === $district->id)
                                        <form wire:submit="addSchool" class="school-add-panel" wire:key="school-form-{{ $district->id }}">
                                            <div class="school-form-head">
                                                <span class="school-form-title">
                                                    <x-icon name="plus" :size="15" />
                                                    <span>Add School</span>
                                                </span>
                                                <span class="school-form-chip" title="This school will be added to {{ $district->name }}">
                                                    <x-icon name="school" :size="13" />
                                                    <span>{{ $district->name }}</span>
                                                </span>
                                            </div>

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
                                                        >
                                                    </span>
                                                    {{-- The ID is the SH's default login when the
                                                         account is created, so say why it matters. --}}
                                                    @if (! $errors->has('schoolId'))
                                                        <div class="field-hint">Unique across all schools — it becomes the SH's default login.</div>
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
                                    @endif

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
