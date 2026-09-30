{{-- The View District button lives in the static page shell (outside this
     component), so it dispatches an Alpine window event that lands here. --}}
<div class="district-manager" x-data x-on:open-districts.window="$wire.openModal()">
    {{-- VIEW DISTRICT MODAL --}}
    <div class="modal-backdrop {{ $showModal ? 'is-open' : '' }}"
         @if ($showModal) @click="self && $wire.closeModal()" @endif
         role="presentation">
        <div class="modal modal--district" role="dialog" aria-modal="true" aria-labelledby="districtModalTitle" @click.stop>
            <div class="modal-head">
                <h2 id="districtModalTitle">Districts &amp; Schools</h2>
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
                            placeholder="e.g. District 1"
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

                {{-- DISTRICT / SCHOOL TABLE --}}
                <div class="table-wrap district-table-wrap">
                    <table class="data-table district-table">
                        <thead>
                            <tr>
                                <th scope="col">District / School</th>
                                <th scope="col" class="district-type-col">Type</th>
                                <th scope="col" class="district-actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->districts as $district)
                                {{-- DISTRICT ROW --}}
                                <tr class="district-row-row" wire:key="district-{{ $district->id }}">
                                    <td>
                                        @if ($editing && $editing['type'] === 'district' && $editing['id'] === $district->id)
                                            <form wire:submit="saveRename" class="rename-form">
                                                <input
                                                    type="text"
                                                    wire:model="editingName"
                                                    autofocus
                                                    @class(['error' => $errors->has('editingName')])
                                                >
                                                <button type="submit" class="rename-save" wire:loading.attr="disabled" wire:target="saveRename" title="Save">✓</button>
                                                <button type="button" class="rename-cancel" wire:click="cancelRename" title="Cancel">×</button>
                                                @error('editingName') <div class="error-text">{{ $message }}</div> @enderror
                                            </form>
                                        @else
                                            <span class="cell-strong">{{ $district->name }}</span>
                                            <span class="cell-sub">{{ $district->schools_count }} {{ Str::plural('school', $district->schools_count) }}</span>
                                        @endif
                                    </td>
                                    <td class="district-type-col"><span class="badge">District</span></td>
                                    <td class="district-actions-col">
                                        <div class="district-actions-row">
                                            <button type="button" class="district-action" wire:click="openSchoolForm({{ $district->id }})" title="Add a school under {{ $district->name }}">
                                                <x-icon name="plus" :size="13" /><span>Add school</span>
                                            </button>
                                            <button type="button" class="district-action" wire:click="startRename('district', {{ $district->id }})" title="Rename {{ $district->name }}">
                                                <x-icon name="pencil" :size="13" /><span>Rename</span>
                                            </button>
                                            <button type="button" class="district-action district-action--danger" wire:click="deleteDistrict({{ $district->id }})" wire:confirm="Delete {{ $district->name }} and all of its schools? This cannot be undone." title="Delete {{ $district->name }}">
                                                <x-icon name="trash" :size="13" /><span>Delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                {{-- SCHOOL ROWS --}}
                                @foreach ($district->schools as $school)
                                    <tr class="school-row-row" wire:key="school-{{ $school->id }}">
                                        <td>
                                            @if ($editing && $editing['type'] === 'school' && $editing['id'] === $school->id)
                                                <form wire:submit="saveRename" class="rename-form">
                                                    <input
                                                        type="text"
                                                        wire:model="editingName"
                                                        autofocus
                                                        @class(['error' => $errors->has('editingName')])
                                                    >
                                                    <button type="submit" class="rename-save" wire:loading.attr="disabled" wire:target="saveRename" title="Save">✓</button>
                                                    <button type="button" class="rename-cancel" wire:click="cancelRename" title="Cancel">×</button>
                                                    @error('editingName') <div class="error-text">{{ $message }}</div> @enderror
                                                </form>
                                            @else
                                                <span class="school-cell">
                                                    <x-icon name="school" :size="13" class="school-dot" />
                                                    {{ $school->name }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="district-type-col"><span class="badge badge-muted">School</span></td>
                                        <td class="district-actions-col">
                                            <div class="district-actions-row">
                                                <button type="button" class="district-action" wire:click="startRename('school', {{ $school->id }})" title="Rename {{ $school->name }}">
                                                    <x-icon name="pencil" :size="13" /><span>Rename</span>
                                                </button>
                                                <button type="button" class="district-action district-action--danger" wire:click="deleteSchool({{ $school->id }})" wire:confirm="Delete {{ $school->name }}?" title="Delete {{ $school->name }}">
                                                    <x-icon name="trash" :size="13" /><span>Delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach

                                {{-- INLINE ADD-SCHOOL ROW --}}
                                @if ($schoolFormDistrictId === $district->id)
                                    <tr class="school-row-row" wire:key="school-form-{{ $district->id }}">
                                        <td colspan="3">
                                            <form wire:submit="addSchool" class="school-add-form">
                                                <span class="school-cell">
                                                    <x-icon name="plus" :size="13" class="school-dot" />
                                                    <input
                                                        type="text"
                                                        placeholder="New school name…"
                                                        autocomplete="off"
                                                        wire:model="schoolName"
                                                        autofocus
                                                        @class(['error' => $errors->has('schoolName')])
                                                    >
                                                </span>
                                                <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="addSchool">Add</button>
                                                <button type="button" class="btn-ghost" wire:click="cancelSchoolForm">Cancel</button>
                                                @error('schoolName') <div class="error-text">{{ $message }}</div> @enderror
                                            </form>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="3" class="table-empty">No districts yet — add the first one above.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
