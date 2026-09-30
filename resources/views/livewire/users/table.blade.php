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
                                <span class="cell-sub">ID: {{ $account->employee_id ?: 'Not assigned' }}</span>
                                <span class="cell-sub">{{ $account->email }}</span>
                            </td>
                            <td>
                                <span @class(['badge', 'badge-muted' => ! $account->is_superadmin])>
                                    {{ $account->is_superadmin ? 'Super Admin' : ($account->role === 'viewer' ? 'SDS Viewer' : 'SH') }}
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
                                <div class="users-actions-row">
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

                                    @if ($this->canChangeRoles() && ! $account->is(auth()->user()))
                                        <button
                                            type="button"
                                            class="users-action"
                                            wire:click="openRoleModal({{ $account->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openRoleModal"
                                            title="Change role for {{ $account->username }}"
                                        >
                                            <span class="users-action-inner">
                                                <x-icon name="shield" :size="14" />
                                                <span>Change Role</span>
                                            </span>
                                        </button>
                                    @endif

                                    @unless ($account->is(auth()->user()))
                                        <button
                                            type="button"
                                            class="users-action users-action--danger"
                                            wire:click="openDeleteModal({{ $account->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openDeleteModal"
                                            title="Delete {{ $account->username }}"
                                        >
                                            <span class="users-action-inner">
                                                <x-icon name="trash" :size="14" />
                                                <span>Delete</span>
                                            </span>
                                        </button>
                                    @endunless
                                </div>
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
                <div class="field">
                    <label for="formName">Real Name</label>
                    <input
                        id="formName"
                        type="text"
                        placeholder="e.g. Jane Doe"
                        autocomplete="off"
                        wire:model="name"
                        @class(['error' => $errors->has('name')])
                    >
                    @error('name') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="formEmployeeId">Employee ID</label>
                    <input
                        id="formEmployeeId"
                        type="text"
                        placeholder="e.g. EMP-001"
                        autocomplete="off"
                        wire:model="employee_id"
                        @class(['error' => $errors->has('employee_id')])
                    >
                    @error('employee_id') <div class="error-text">{{ $message }}</div> @enderror
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

                @if (auth()->user()?->is_superadmin)
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
