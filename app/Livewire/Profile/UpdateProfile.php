<?php

namespace App\Livewire\Profile;

use App\Livewire\Concerns\ManagesAssignedSchools;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "Update My Profile" modal behind the account menu.
 *
 * Every signed-in account — user or superadmin — edits only its OWN record:
 * the id never comes from the browser, and role and superadmin rights are not
 * fields here at all, so a crafted request cannot promote itself. The base
 * school stays with the administrator too; the ONE school decision an account
 * makes here is the Bookkeeper's own assignment list, which is the roster its
 * checking pages are scoped to (User::canAccessSchool()). Every other role's
 * school assignment is untouched by this form.
 *
 * Username and email are the two ways in, so they stay unique across the
 * system. The password is optional and only changes when the account proves
 * it knows the current one — leaving the new password blank keeps it as it is,
 * which is what a form that always shows the field should do.
 */
class UpdateProfile extends Component
{
    use ManagesAssignedSchools;

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

        // The Bookkeeper's roster, read fresh from the database: the modal
        // always opens on what is actually saved, never on a selection a
        // previous visit left behind.
        $this->loadAssignedSchools($account);

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
        $passwordChanged = filled($validated['password']);

        // The details and the roster are one save: a failure in either must
        // not leave the other half-written.
        DB::transaction(function () use ($account, $validated, $passwordChanged): void {
            $account->name = $validated['name'];
            $account->position = $validated['position'] ?: null;
            $account->username = $validated['username'];
            $account->email = $validated['email'];

            // Blank means "keep the current password" — the field is on screen
            // only so the account can change it, not as a required step.
            if ($passwordChanged) {
                $account->password = $validated['password'];
            }

            $account->save();

            // The Bookkeeper's assignment list is the one school decision this
            // form owns: the list on screen replaces the stored one whole, so
            // removing a school revokes the access with it. For every other
            // role the rules never carry the field, so a crafted payload is
            // not even validated — and can never reach this line.
            if ($account->isBookkeeperRole()) {
                $account->syncAssignedSchools($validated['assigned_schools'] ?? []);
            }
        });

        $this->resetPasswordFields();
        $this->resetValidation();

        // Keep the account menu in sync when the displayed real name changes.
        $this->dispatch(
            'smartapp-profile-updated',
            name: $account->name,
            username: $account->username,
            email: $account->email,
        );

        if ($passwordChanged) {
            // A changed password ends this session: the account signs in again
            // with the new one, so the modal does not stay open behind the
            // redirect.
            $this->showModal = false;

            Auth::logout();

            // The session is resolved through the helper rather than
            // request()->session(): a Livewire update request has no session
            // store attached under the test harness, while both paths resolve
            // the same store in a real request.
            session()->invalidate();
            session()->regenerateToken();
            session()->flash('success', 'Your password has been updated. Please sign in again.');

            $this->redirect(route('login'), navigate: false);

            return;
        }

        // A details-only save keeps the modal open under its confirmation.
        $this->successMessage = 'Your profile has been updated.';
    }

    /**
     * Validation rules for the account's own form.
     *
     * Role, the base school and superadmin rights are deliberately absent:
     * they are not this form's to change, so there is nothing for a request
     * to smuggle in. The Bookkeeper's assignment list is the exception — it
     * is the account's own roster to keep current — and its rules exist only
     * for a Bookkeeper, so for every other role a crafted list is ignored
     * rather than saved.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $account = $this->account();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('users', 'username')->ignore($account->getKey()),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($account->getKey()),
            ],
            // Only asked for when a new password is actually being set.
            'current_password' => [
                Rule::requiredIf(fn() => filled($this->password)),
                'string',
                'current_password',
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ];

        if ($account->isBookkeeperRole()) {
            $rules = array_merge($rules, $this->assignedSchoolRules());
        }

        return $rules;
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
        ] + $this->assignedSchoolAttributes();
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
