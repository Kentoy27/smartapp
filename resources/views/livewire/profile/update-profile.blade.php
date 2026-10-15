{{-- The "Update My Profile" modal, opened from the account menu.

     It edits the signed-in account's own details and nothing else: the role,
     school and superadmin rights are shown read-only because an administrator
     assigns them, and the id is never on the wire. The password is optional on
     purpose — a blank new-password field means "leave it alone", so the form
     can be saved for a name or email change without touching the login.

     The wrapper is `display: contents` so the always-present Livewire root
     adds no box to the header's flex row while the modal is closed. --}}
<div class="profile-modal-host">
    @if ($showModal)
        <div class="modal-backdrop is-open"
             @click="self && $wire.closeModal()"
             x-on:keydown.escape.window="$wire.closeModal()"
             role="presentation">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="profileFormTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="profileFormTitle">Update My Profile</h2>
                    <button type="button" class="modal-close" wire:click="closeModal" aria-label="Close" title="Close">×</button>
                </div>

                <form wire:submit="save" class="modal-form">
                    @if ($successMessage)
                        <div class="profile-modal-alert profile-modal-alert--ok" role="status">
                            <x-icon name="check-circle" :size="16" />
                            <span>{{ $successMessage }}</span>
                            <button type="button" class="sig-alert-close" wire:click="clearSuccess" aria-label="Dismiss message" title="Dismiss">×</button>
                        </div>
                    @endif

                    @if ($errors->has('current_password') || $errors->has('password'))
                        <div class="profile-modal-alert profile-modal-alert--error" role="alert">
                            <x-icon name="alert-triangle" :size="16" />
                            <span>{{ $errors->first('password') ?: $errors->first('current_password') }}</span>
                        </div>
                    @endif

                    {{-- WHO YOU ARE --}}
                    <div class="field">
                        <label for="profileName">Name</label>
                        <input
                            id="profileName"
                            type="text"
                            placeholder="e.g. Juan Dela Cruz"
                            autocomplete="name"
                            wire:model="name"
                            @class(['error' => $errors->has('name')])
                            required
                        >
                        <div class="field-hint">
                            Your real name as it should appear on the documents SmartApp prints for you.
                        </div>
                        @error('name') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="profilePosition">Position</label>
                        <input
                            id="profilePosition"
                            type="text"
                            placeholder="e.g. School Head"
                            autocomplete="organization-title"
                            wire:model="position"
                            @class(['error' => $errors->has('position')])
                        >
                        @error('position') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    {{-- HOW YOU SIGN IN --}}
                    <div class="field">
                        <label for="profileUsername">Username</label>
                        <input
                            id="profileUsername"
                            type="text"
                            autocomplete="username"
                            wire:model="username"
                            @class(['error' => $errors->has('username')])
                            required
                        >
                        <div class="field-hint">
                            One of the two things you sign in with. Letters, numbers, dashes and underscores;
                            it has to stay unique across the system.
                        </div>
                        @error('username') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="profileEmail">Email</label>
                        <input
                            id="profileEmail"
                            type="email"
                            autocomplete="email"
                            wire:model="email"
                            @class(['error' => $errors->has('email')])
                            required
                        >
                        <div class="field-hint">Also a way in, and where the system reaches you.</div>
                        @error('email') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    {{-- ASSIGNED, NOT EDITABLE: kept visible so the form says who
                         decides them instead of leaving the question open. --}}
                    <div class="field">
                        <label for="profileRole">Role</label>
                        <input
                            id="profileRole"
                            type="text"
                            value="{{ $account->roleLabel() }}"
                            class="is-readonly"
                            readonly
                        >
                        <div class="field-hint">Your role is assigned by an administrator.</div>
                    </div>

                    @if ($account->school)
                        <div class="field">
                            <label for="profileSchool">School</label>
                            <input
                                id="profileSchool"
                                type="text"
                                value="{{ $account->school->name }}"
                                class="is-readonly"
                                readonly
                            >
                            <div class="field-hint">Your school assignment is managed by an administrator.</div>
                        </div>
                    @endif

                    {{-- PASSWORD: optional, and never shown again once saved. --}}
                    <div class="field">
                        <label for="profileCurrentPassword">Current password</label>
                        <input
                            id="profileCurrentPassword"
                            type="password"
                            autocomplete="current-password"
                            wire:model="current_password"
                            @class(['error' => $errors->has('current_password')])
                        >
                        <div class="field-hint">Only needed when you are setting a new password.</div>
                    </div>

                    <div class="field">
                        <label for="profileNewPassword">New password</label>
                        <input
                            id="profileNewPassword"
                            type="password"
                            autocomplete="new-password"
                            wire:model="password"
                            @class(['error' => $errors->has('password')])
                        >
                        <div class="field-hint">At least 8 characters. Leave this blank to keep your current password.</div>
                    </div>

                    <div class="field">
                        <label for="profilePasswordConfirmation">Confirm new password</label>
                        <input
                            id="profilePasswordConfirmation"
                            type="password"
                            autocomplete="new-password"
                            wire:model="password_confirmation"
                        >
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeModal"
                                wire:loading.attr="disabled" wire:target="save">
                            Cancel
                        </button>
                        <button type="submit" class="btn-primary"
                                wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">
                                <x-icon name="check" :size="16" /> Save changes
                            </span>
                            <span wire:loading wire:target="save">Saving…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
