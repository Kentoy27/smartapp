<?php

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "Update My Profile" modal behind the account menu.
 *
 * Every signed-in account — user or superadmin — edits only its OWN record:
 * the id never comes from the browser, and role, school and superadmin rights
 * are not fields here at all, so a crafted request cannot promote itself or
 * move itself to another school. Those stay with the administrator who
 * assigns them.
 *
 * Username and email are the two ways in, so they stay unique across the
 * system. The password is optional and only changes when the account proves
 * it knows the current one — leaving the new password blank keeps it as it is,
 * which is what a form that always shows the field should do.
 */
class UpdateProfile extends Component
{
    /**
     * Whether the modal is on screen. Opening is driven from the account menu
     * with the `open-profile-modal` event, so the layout owns the trigger and
     * this component owns the form.
     */
    public bool $showModal = false;

    public string $name = '';

    public string $position = '';

    public string $username = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Confirmation shown inside the modal after a successful save, cleared by
     * the dismiss button or when the modal is reopened.
     */
    public ?string $successMessage = null;

    public function mount(): void
    {
        // The modal is chrome for a signed-in account only.
        abort_unless(Auth::check(), 404);
    }

    /**
     * The account this modal edits — always the signed-in one.
     */
    protected function account()
    {
        return Auth::user();
    }

    /**
     * Fill the form from the account and show the modal. Called from the
     * account menu, so a stale form from a previous visit is never shown.
     */
    #[On('open-profile-modal')]
    public function openModal(): void
    {
        $account = $this->account();

        $this->name = (string) ($account->name ?? '');
        $this->position = (string) ($account->position ?? '');
        $this->username = (string) $account->username;
        $this->email = (string) ($account->email ?? '');

        $this->resetPasswordFields();

        $this->successMessage = null;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->successMessage = null;
        $this->resetPasswordFields();
        $this->resetValidation();
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    /**
     * Write the account's own details back to its own row.
     */
    public function save(): void
    {
        $account = $this->account();

        // Last save's confirmation is not this save's outcome: an attempt that
        // fails below must not sit under a "saved" banner.
        $this->successMessage = null;

        $validated = $this->validate();

        $account->name = $validated['name'];
        $account->position = $validated['position'] ?: null;
        $account->username = $validated['username'];
        $account->email = $validated['email'];

        // Blank means "keep the current password" — the field is on screen
        // only so the account can change it, not as a required step.
        if (filled($validated['password'])) {
            $account->password = $validated['password'];
        }

        $account->save();

        $this->resetPasswordFields();
        $this->resetValidation();

        $this->successMessage = 'Your profile has been updated.';

        // The account menu spells the username and its initials, so it has to
        // hear about a rename instead of waiting for the next page load.
        $this->dispatch('smartapp-profile-updated', username: $account->username, email: $account->email);
    }

    /**
     * Validation rules for the account's own form.
     *
     * Role, school and superadmin rights are deliberately absent: they are not
     * this form's to change, so there is nothing for a request to smuggle in.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $account = $this->account();

        return [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($account->getKey()),
            ],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($account->getKey()),
            ],
            // Only asked for when a new password is actually being set.
            'current_password' => [
                Rule::requiredIf(fn () => filled($this->password)),
                'string',
                'current_password',
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * Human-friendly field names for the error messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'name',
            'position' => 'position',
            'username' => 'username',
            'email' => 'email',
            'current_password' => 'current password',
            'password' => 'new password',
        ];
    }

    /**
     * Password fields are never pre-filled: the form always starts empty and
     * only what was typed in this visit can be sent.
     */
    protected function resetPasswordFields(): void
    {
        $this->current_password = '';
        $this->password = '';
        $this->password_confirmation = '';
    }

    public function render()
    {
        return view('livewire.profile.update-profile', [
            // Read-only context for the form: the role and school the account
            // cannot change here.
            'account' => $this->account(),
        ]);
    }
}
