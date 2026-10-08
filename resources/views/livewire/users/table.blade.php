<div class="users-table-component" wire:poll>
    <div class="page-header">
        <h1>Users</h1>
        <p>Every account in the system, updated live every few seconds.</p>
    </div>

    {{-- SUCCESS ALERT: the server sets $successMessage after a create;
         this invisible element exists only while a message is pending and
         pops the SweetAlert once. The component then clears the message so
         later renders/polls never re-fire it. --}}
    @if ($successMessage)
        <div
            wire:key="alert-{{ md5($successMessage) }}"
            x-data
            x-init="
                if (window.smartAlert) {
                    smartAlert.success({{ \Illuminate\Support\Js::from($successMessage) }});
                }
                $wire.clearSuccess();
            "
            class="success-alert-sentinel"
            aria-hidden="true"
        ></div>
    @endif

    <div class="card">
        <div class="card-head users-toolbar">
            <div class="users-search">
                <input
                    type="search"
                    placeholder="Search username, email, or name…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Search users"
                >

                @if (trim($search) !== '')
                    <button type="button" class="users-clear" wire:click="clearSearch" title="Clear search">×</button>
                @endif
            </div>

            @if ($this->canManageUsers())
                <button type="button" class="users-add-btn" wire:click="openCreateModal">
                    <x-icon name="user-plus" :size="16" />
                    <span>Add User</span>
                </button>
            @endif

            <label class="users-per-page">
                <span>Rows</span>
                <select wire:model.live="perPage">
                    @foreach ([10, 25, 50] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">User</th>
                        <th scope="col">Role</th>
                        <th scope="col">Google</th>
                        <th scope="col" class="users-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $account)
                        <tr wire:key="user-{{ $account->id }}">
                            <td>
                                <span class="cell-strong">{{ $account->username }}</span>
                                @if ($account->name)
                                    <span class="cell-sub">{{ $account->name }}</span>
                                @endif
                                <span class="cell-sub">{{ $account->email }}</span>
                                @if ($account->school)
                                    <span class="cell-sub">{{ $account->school->name }}@if ($account->school->school_id) · {{ $account->school->school_id }}@endif</span>
                                @endif
                            </td>
                            <td>
                                <span @class(['badge', 'badge-muted' => ! $account->hasAdminAccess()])>
                                    {{ $account->roleLabel() }}
                                </span>
                            </td>
                            <td>
                                @if ($account->google_id)
                                    <span class="badge">Linked</span>
                                @else
                                    <span class="badge badge-muted">—</span>
                                @endif
                            </td>
                            <td class="users-actions-col">
                                @if ($this->canManageUsers())
                                    {{-- A superadmin cannot change their own role or
                                         delete themselves, so their own row has a
                                         single action — shown as a plain button
                                         rather than a menu holding one item. --}}
                                    @php($canChangeRole = $this->canChangeRoles() && ! $account->is(auth()->user()))
                                    @php($canDelete = ! $account->is(auth()->user()))

                                    @if (! $canChangeRole && ! $canDelete)
                                        <button
                                            type="button"
                                            class="users-action"
                                            wire:click="openEditModal({{ $account->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openEditModal"
                                            title="Edit {{ $account->username }}"
                                        >
                                            <span class="users-action-inner">
                                                <x-icon name="pencil" :size="14" />
                                                <span>Edit</span>
                                            </span>
                                        </button>
                                    @else
                                        {{-- Otherwise the row's actions collapse behind
                                             one trigger: three buttons on every row is
                                             a wall of chrome, and the destructive one
                                             should not sit in the open. --}}
                                        <x-row-menu :label="'More actions for '.$account->username">
                                            <x-row-menu-item
                                                wire:click="openEditModal({{ $account->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="openEditModal"
                                                title="Edit {{ $account->username }}"
                                            >
                                                <x-icon name="pencil" :size="15" />
                                                <span>Edit</span>
                                            </x-row-menu-item>

                                            @if ($canChangeRole)
                                                <x-row-menu-item
                                                    wire:click="openRoleModal({{ $account->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="openRoleModal"
                                                    title="Change role for {{ $account->username }}"
                                                >
                                                    <x-icon name="shield" :size="15" />
                                                    <span>Change Role</span>
                                                </x-row-menu-item>
                                            @endif

                                            @if ($canDelete)
                                                <x-row-menu-item
                                                    wire:click="openDeleteModal({{ $account->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="openDeleteModal"
                                                    title="Delete {{ $account->username }}"
                                                    danger
                                                >
                                                    <x-icon name="trash" :size="15" />
                                                    <span>Delete</span>
                                                </x-row-menu-item>
                                            @endif
                                        </x-row-menu>
                                    @endif
                                @else
                                    <span class="badge badge-muted">View only</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="table-empty">
                                @if (trim($search) !== '')
                                    No accounts match “{{ $search }}”.
                                @else
                                    No accounts to display yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="users-footer">
            <span class="users-live-indicator" wire:loading.delay wire:target="*" title="Live update in progress">
                <span class="users-live-dot"></span> Updating…
            </span>

            <span class="users-count">
                {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ $users->total() }}
            </span>

            {{ $users->links() }}
        </div>

        @if ($this->canChangeRoles())
            <div class="modal-backdrop {{ $showRoleModal ? 'is-open' : '' }}"
                 @if ($showRoleModal) @click="self && $wire.closeRoleModal()" @endif
                 role="presentation">
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="roleFormTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="roleFormTitle">Change Role</h2>
                    <button type="button" class="modal-close" wire:click="closeRoleModal" aria-label="Close" title="Close">×</button>
                </div>

                <form wire:submit="updateRole" class="modal-form">
                    <p class="role-modal-description">
                        Choose a new role for <strong>{{ $roleUsername }}</strong>.
                    </p>

                    <div class="field">
                        <label>Role</label>
                        <div class="role-picker" x-cloak>
                            <label class="role-option">
                                <input type="radio" name="selectedRole" value="user" wire:model="selectedRole">
                                <span class="role-option-body">
                                    <strong>SH</strong>
                                    <small>Standard access, no admin rights</small>
                                </span>
                            </label>

                            <label class="role-option">
                                <input type="radio" name="selectedRole" value="viewer" wire:model="selectedRole">
                                <span class="role-option-body">
                                    <strong>SDS Viewer</strong>
                                    <small>View users only, no account changes</small>
                                </span>
                            </label>

                            <label class="role-option">
                                <input type="radio" name="selectedRole" value="administrator" wire:model="selectedRole">
                                <span class="role-option-body">
                                    <strong>Administrator</strong>
                                    <small>Full access to administrative pages and user management</small>
                                </span>
                            </label>

                            <label class="role-option">
                                <input type="radio" name="selectedRole" value="superadmin" wire:model="selectedRole">
                                <span class="role-option-body">
                                    <strong>Super Admin</strong>
                                    <small>Full system access, can manage accounts</small>
                                </span>
                            </label>
                        </div>
                        @error('selectedRole') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeRoleModal">Cancel</button>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="updateRole">
                            <span wire:loading.remove wire:target="updateRole">Save Role</span>
                            <span wire:loading wire:target="updateRole">Saving…</span>
                        </button>
                    </div>
                </form>
                </div>
            </div>
        @endif

        @if ($showDeleteModal)
            <div class="modal-backdrop is-open"
                 @click="self && $wire.closeDeleteModal()"
                 role="presentation">
                <div class="modal modal--confirmation" role="dialog" aria-modal="true" aria-labelledby="deleteFormTitle" @click.stop>
                    <div class="modal-head">
                        <h2 id="deleteFormTitle">Delete Account</h2>
                        <button type="button" class="modal-close" wire:click="closeDeleteModal" aria-label="Close" title="Close">×</button>
                    </div>

                    <div class="modal-form">
                        <p class="delete-modal-description">
                            Are you sure you want to delete <strong>{{ $deleteUsername }}</strong>?
                            This action cannot be undone.
                        </p>

                        <div class="modal-actions">
                            <button type="button" class="btn-ghost" wire:click="closeDeleteModal">Cancel</button>
                            <button type="button" class="btn-danger" wire:click="confirmDelete" wire:loading.attr="disabled" wire:target="confirmDelete">
                                <span wire:loading.remove wire:target="confirmDelete">Delete Account</span>
                                <span wire:loading wire:target="confirmDelete">Deleting…</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

    </div>

    {{-- ADD / EDIT USER MODAL (one modal, two modes) --}}
    <div class="modal-backdrop {{ $showCreateModal ? 'is-open' : '' }}"
         @if ($showCreateModal) @click="self && $wire.closeCreateModal()" @endif
         role="presentation">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="userFormTitle" @click.stop>
            <div class="modal-head">
                <h2 id="userFormTitle">{{ $editingUserId ? 'Edit User' : 'Add User' }}</h2>
                <button type="button" class="modal-close" wire:click="closeCreateModal" aria-label="Close" title="Close">×</button>
            </div>

            <form wire:submit="saveUser" class="modal-form">
                @if (auth()->user()?->hasAdminAccess())
                    {{-- ROLE FIRST: it decides the shape of the rest of the
                         form. Picking SH reveals the Division → School →
                         School ID cascade below; the other roles have no
                         school, so those fields disappear. --}}
                    <div class="field">
                        <label>Role</label>
                        <div class="role-picker" x-cloak>
                            <label class="role-option">
                                <input
                                    type="radio"
                                    name="role"
                                    value="user"
                                    wire:model.live="role"
                                >
                                <span class="role-option-body">
                                    <strong>SH</strong>
                                    <small>Standard access, no admin rights</small>
                                </span>
                            </label>

                            <label class="role-option">
                                <input
                                    type="radio"
                                    name="role"
                                    value="viewer"
                                    wire:model.live="role"
                                >
                                <span class="role-option-body">
                                    <strong>SDS Viewer</strong>
                                    <small>View users only, no account changes</small>
                                </span>
                            </label>

                            <label class="role-option">
                                <input
                                    type="radio"
                                    name="role"
                                    value="superadmin"
                                    wire:model.live="role"
                                >
                                <span class="role-option-body">
                                    <strong>Super Admin</strong>
                                    <small>Full system access, can manage accounts</small>
                                </span>
                            </label>
                        </div>

                        {{-- Server-side truth: the radio clicks dispatch $wire.set --}}
                        <input type="hidden" wire:model="isSuperadmin">
                    </div>
                @endif

                {{-- SCHOOL ASSIGNMENT (SH only): division → school → School ID
                     (read only — it comes from the selected school's record)
                     → the same ID fills the login below. --}}
                @if ($this->isSchoolHead())
                    <div class="field">
                        <label for="formSchoolDivision">School Division</label>
                        <select
                            id="formSchoolDivision"
                            wire:model="school_division"
                            @class(['error' => $errors->has('school_division')])
                        >
                            <option value="">Select School Division</option>
                            @foreach ($this->divisions as $division)
                                <option value="{{ $division->id }}">{{ $division->name }}</option>
                            @endforeach
                        </select>
                        @error('school_division') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="formSchool">School</label>
                        <select
                            id="formSchool"
                            wire:model.live="school_id_choice"
                            @class(['error' => $errors->has('school_id_choice')])
                            @disabled($this->divisionSchools->isEmpty())
                        >
                            <option value="">Select School</option>
                            @foreach ($this->divisionSchools as $school)
                                <option value="{{ $school->id }}">{{ $school->name }}</option>
                            @endforeach
                        </select>
                        @error('school_id_choice') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="formSchoolId">School ID</label>
                        <input
                            id="formSchoolId"
                            type="text"
                            value="{{ $this->selectedSchoolId }}"
                            placeholder="Select a school…"
                            readonly
                            class="is-readonly"
                        >
                    </div>
                @endif

                <div class="field">
                    <label for="formName">Name</label>
                    <input
                        id="formName"
                        type="text"
                        placeholder="e.g. Juan Dela Cruz"
                        autocomplete="off"
                        wire:model="name"
                        @class(['error' => $errors->has('name')])
                        required
                    >
                    <div class="field-hint">
                        The employee's real name, exactly as it should appear on their OPCRF.
                        Uploads are matched against this, so it has to be their actual name — not an
                        employee ID or a login.
                    </div>
                    @error('name') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="formUsername">Username</label>
                    <input
                        id="formUsername"
                        type="text"
                        placeholder="e.g. jane"
                        autocomplete="off"
                        wire:model="username"
                        @class(['error' => $errors->has('username')])
                        required
                    >
                    @if ($this->isSchoolHead() && $this->school_id_choice !== '')
                        @if ($this->selectedSchoolId !== '')
                            <div class="field-hint">Defaults to {{ $this->selectedSchoolId }} — the selected school's ID. Change it if you want a different login.</div>
                            @if ($this->editingUserId === null)
                                <div class="field-hint">The password is set to the same ID automatically, so the SH can sign in straight away.</div>
                            @endif
                        @else
                            {{-- A school with no School ID on record has nothing to
                                 default the login to — say so instead of leaving
                                 the superadmin wondering why it stayed blank. --}}
                            <div class="field-hint">This school has no School ID on record, so the login can't be filled in for you — type one.</div>
                        @endif
                    @endif
                    @error('username') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="formEmail">Email</label>
                    <input
                        id="formEmail"
                        type="email"
                        placeholder="e.g. jane@example.com"
                        autocomplete="off"
                        wire:model="email"
                        @class(['error' => $errors->has('email')])
                        required
                    >
                    @error('email') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                {{-- PASSWORD: skipped entirely when the form can derive one.
                     An SH's starting password is their school's ID — the same
                     value the login already defaults to — so there is nothing
                     to type. Editing keeps the field: blank means "unchanged",
                     and that is how an admin resets a forgotten password. --}}
                @unless ($this->hasAutoPassword())
                    <div class="field">
                        <label for="formPassword">Password</label>
                        <div class="password-wrap">
                            <input
                                id="formPassword"
                                type="password"
                                placeholder="{{ $editingUserId ? 'Leave blank to keep current password' : 'Minimum 8 characters' }}"
                                autocomplete="new-password"
                                wire:model="password"
                                @class(['error' => $errors->has('password')])
                                {{ $editingUserId ? '' : 'required' }}
                            >
                            <button
                                type="button"
                                class="password-toggle"
                                x-on:click="revealed = !revealed"
                                x-data="{ revealed: false }"
                                x-bind:type="'button'"
                                :aria-pressed="revealed.toString()"
                                :aria-label="revealed ? 'Hide password' : 'Show password'"
                                :title="revealed ? 'Hide password' : 'Show password'"
                                x-bind:class="revealed && 'revealed'"
                                x-effect="document.getElementById('formPassword').type = revealed ? 'text' : 'password'"
                            >
                                <x-icon name="eye" :size="18" class="icon-eye" />
                                <x-icon name="eye-off" :size="18" class="icon-eye-off" />
                            </button>
                        </div>
                        @error('password') <div class="error-text">{{ $message }}</div> @enderror
                    </div>
                @endunless

                <div class="modal-actions">
                    <button type="button" class="btn-ghost" wire:click="closeCreateModal">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="saveUser">
                        <span wire:loading.remove wire:target="saveUser">{{ $editingUserId ? 'Save Changes' : 'Create User' }}</span>
                        <span wire:loading wire:target="saveUser">{{ $editingUserId ? 'Saving…' : 'Creating…' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
