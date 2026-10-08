<?php

namespace App\Livewire;

use App\Models\District;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users — SmartApp')]
class UsersTable extends Component
{
    use WithPagination;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);
    }

    /**
     * Livewire v4 auto-persists public properties across requests and
     * (with the WithPagination trait) `paginators` is query-string bound.
     */

    /**
     * Live table settings: search term and rows per page.
     */
    public string $search = '';

    public int $perPage = 10;

    /**
     * "Add / Edit user" modal state. One modal serves both modes: when
     * $editingUserId is null the form creates an account, otherwise it
     * updates the account with that id (password optional = keep current).
     */
    public bool $showCreateModal = false;

    public ?int $editingUserId = null;

    public bool $showRoleModal = false;

    public ?int $roleUserId = null;

    public string $roleUsername = '';

    public string $selectedRole = 'user';

    public bool $showDeleteModal = false;

    public ?int $deleteUserId = null;

    public string $deleteUsername = '';

    /**
     * The employee's real name.
     *
     * Not decoration: an OPCRF upload is matched against this exact string,
     * so an account without one can never submit — and it is also what the
     * personalized template is written from. A superadmin can correct it in
     * the add/edit modal, which is the only route a wrong or missing name
     * ever gets fixed.
     */
    public string $name = '';

    /**
     * The account's school assignment: division picked first, then the
     * school within it — the school's own School ID fills the read-only
     * field. Null school_id = no school (superadmins typically).
     */
    public string $school_division = '';

    public string $school_id_choice = '';

    public string $username = '';

    /**
     * The School ID this form last filled into $username for the superadmin.
     *
     * Picking a school prefills the login with that school's ID. Keeping
     * track of what we wrote is what lets a later change of school refresh
     * the default while leaving a login the superadmin typed by hand alone.
     */
    public string $usernamePrefill = '';

    public string $email = '';

    public string $password = '';

    /**
     * Does this form set the password itself?
     *
     * An SH's account is keyed to their school: the school's DepEd ID is both
     * the login and the starting password, so the administrator never types one
     * and the field is not rendered at all. It is decided by the ROLE alone —
     * the field must not reappear just because no school has been picked yet.
     * (An SH whose school has no ID on record cannot be saved; createUser says
     * so instead of quietly making an account nobody can sign in to.)
     */
    public function hasAutoPassword(): bool
    {
        return $this->isSchoolHead();
    }

    /**
     * Role selection in the add/edit modal. Only administrators may set or
     * change it (enforced server-side); it renders only for them.
     */
    public bool $isSuperadmin = false;

    public string $role = 'user';

    /**
     * Success toast shown after a user is created, updated, or removed.
     * Popped as a SweetAlert by the view, then cleared via clearSuccess().
     */
    public ?string $successMessage = null;

    /**
     * Validation rules for the add/edit form.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $editing = $this->editingUserId !== null;
        $schoolHead = $this->isSchoolHead();
        $autoPassword = $this->hasAutoPassword();

        return [
            // Only a School Head belongs to a school. For the other roles the
            // cascade is not even rendered, so it must not be required here
            // either — and a crafted request cannot smuggle a school in.
            'school_division' => $schoolHead ? ['required', 'string'] : ['nullable', 'string'],
            // The school must belong to the selected division (the dropdown
            // only offers those, but the rule is the real boundary).
            'school_id_choice' => $schoolHead ? [
                'required', 'string',
                Rule::in(School::query()
                    ->where('district_id', $this->school_division)
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->push('')
                    ->all()),
            ] : ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($this->editingUserId),
            ],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->editingUserId),
            ],
            // Required when creating — unless the form derives it from the
            // school's School ID. Optional when editing (blank = keep).
            'password' => $editing || $autoPassword
                ? ['nullable', 'string', 'min:8']
                : ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['user', 'viewer', 'administrator', 'superadmin'])],
        ];
    }

    /**
     * Human-friendly field names for the form error messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'school_division' => 'school division',
            'school_id_choice' => 'school',
            'name' => 'name',
            'username' => 'username',
            'email' => 'email',
            'password' => 'password',
        ];
    }

    public function openCreateModal(): void
    {
        abort_if($this->isViewer(), 403);

        $this->resetForm();
        $this->resetValidation();
        $this->editingUserId = null;
        $this->isSuperadmin = false;
        $this->role = 'user';
        $this->showCreateModal = true;
    }

    public function openEditModal(User $user): void
    {
        abort_if($this->isViewer(), 403);

        $this->resetForm();
        $this->resetValidation();
        $this->editingUserId = $user->id;
        $this->school_division = (string) ($user->school?->district_id ?? '');
        $this->school_id_choice = (string) ($user->school_id ?? '');
        $this->name = (string) $user->name;
        $this->username = (string) $user->username;
        $this->usernamePrefill = ''; // the stored login is theirs, not our default
        $this->email = (string) $user->email;
        $this->password = ''; // blank = keep the current password
        $this->isSuperadmin = (bool) $user->is_superadmin;
        $this->role = $user->is_superadmin ? 'superadmin' : ($user->role ?: 'user');
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->editingUserId = null;
        $this->resetForm();
        $this->resetValidation();
    }

    public function openRoleModal(User $user): void
    {
        abort_unless($this->canAssignRoles(), 403);
        abort_if($user->is($this->authenticatedUser()), 403);

        $this->roleUserId = $user->id;
        $this->roleUsername = $user->username;
        $this->selectedRole = $user->is_superadmin ? 'superadmin' : ($user->role ?: 'user');
        $this->showRoleModal = true;
        $this->resetValidation();
    }

    public function closeRoleModal(): void
    {
        $this->showRoleModal = false;
        $this->roleUserId = null;
        $this->roleUsername = '';
        $this->selectedRole = 'user';
        $this->resetValidation();
    }

    public function updateRole(): void
    {
        abort_unless($this->canAssignRoles(), 403);

        $validated = $this->validate([
            'selectedRole' => ['required', Rule::in(['user', 'viewer', 'administrator', 'superadmin'])],
        ]);

        $user = User::findOrFail($this->roleUserId);
        abort_if($user->is($this->authenticatedUser()), 403);

        $user->forceFill([
            'role' => $validated['selectedRole'],
            'is_superadmin' => $validated['selectedRole'] === 'superadmin',
        ])->save();

        $username = $user->username;
        $role = match ($validated['selectedRole']) {
            'superadmin' => 'Super Admin',
            'administrator' => 'Administrator',
            'viewer' => 'SDS Viewer',
            default => 'SH',
        };

        $this->closeRoleModal();
        $this->successMessage = "Role updated — {$username} is now {$role}.";
        $this->announce();
    }

    public function openDeleteModal(User $user): void
    {
        abort_if($this->isViewer(), 403);
        abort_if($user->is($this->authenticatedUser()), 403);

        $this->deleteUserId = $user->id;
        $this->deleteUsername = $user->username;
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deleteUserId = null;
        $this->deleteUsername = '';
    }

    public function confirmDelete(): void
    {
        abort_if($this->isViewer(), 403);

        $user = User::findOrFail($this->deleteUserId);
        abort_if($user->is($this->authenticatedUser()), 403);

        $username = $user->username;
        $user->delete();

        $this->closeDeleteModal();
        $this->successMessage = "Account deleted — {$username} was removed.";
        $this->announce();
    }

    private function resetForm(): void
    {
        $this->school_division = '';
        $this->school_id_choice = '';
        $this->name = '';
        $this->username = '';
        $this->usernamePrefill = '';
        $this->email = '';
        $this->password = '';
        $this->isSuperadmin = false;
        $this->role = 'user';
    }

    /**
     * Save the modal: create when adding, update when editing.
     */
    public function saveUser(): void
    {
        $this->editingUserId === null
            ? $this->createUser()
            : $this->updateUser();
    }

    public function setRole(string $role): void
    {
        if (! in_array($role, ['user', 'viewer', 'administrator', 'superadmin'], true)) {
            return;
        }

        $this->role = $role;
        $this->isSuperadmin = $role === 'superadmin';
    }

    /**
     * Is the form building a School Head? That role is the only one with a
     * school, so it alone drives the Division → School → School ID cascade
     * (and the rule that the login defaults to the School ID).
     */
    public function isSchoolHead(): bool
    {
        return $this->role === 'user';
    }

    public function updatedRole(string $role): void
    {
        $this->isSuperadmin = $role === 'superadmin';

        // Switching away from SH hides the cascade, so drop whatever it held —
        // otherwise a hidden selection would silently attach the account to a
        // school on save.
        if ($role !== 'user') {
            $this->school_division = '';
            $this->school_id_choice = '';
            $this->usernamePrefill = '';
        }
    }

    /**
     * The signed-in user may grant/revoke administrative roles only if they
     * hold administrative access. Guarded server-side — the picker is a UI
     * convenience, never the security boundary.
     */
    private function canAssignRoles(): bool
    {
        return (bool) $this->authenticatedUser()?->canManageUsersAndSchools();
    }

    /**
     * Create the account and slide it into the live table.
     */
    public function createUser(): void
    {
        abort_if($this->isViewer(), 403);

        $validated = $this->validate();
        $selectedRole = $this->canAssignRoles()
            ? ($this->isSuperadmin ? 'superadmin' : $validated['role'])
            : 'user';

        // An SH's password IS their school's DepEd ID. A school without one
        // has nothing to hand out, so stop here rather than mint an account
        // nobody could sign in to.
        if ($selectedRole === 'user' && $this->selectedSchoolId === '') {
            $this->addError(
                'school_id_choice',
                "This school has no School ID on record, so it cannot be used as the account password. Set the school's ID first."
            );

            return;
        }

        $user = User::create([
            'school_id' => $selectedRole === 'user' && $validated['school_id_choice'] !== ''
                ? (int) $validated['school_id_choice']
                : null,
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            // No password typed (the SH case): the school's DepEd ID is the
            // starting password, matching the login it already defaults to.
            'password' => ($validated['password'] ?? '') !== ''
                ? $validated['password']
                : $this->selectedSchoolId, // hashed by the model cast
            'role' => $selectedRole,
            'is_superadmin' => $selectedRole === 'superadmin',
        ]);

        $this->showCreateModal = false;
        $this->editingUserId = null;
        $this->resetForm();
        $this->resetValidation();

        // Jump to page 1 (newest first) so the fresh account is visible.
        $this->resetPage();

        $roleDescription = match ($user->role) {
            'superadmin' => 'a super admin',
            'administrator' => 'an administrator',
            'viewer' => 'a viewer',
            default => 'a user',
        };
        $this->successMessage = "Account created — {$user->username} was added as {$roleDescription}.";

        $this->announce();
    }

    /**
     * Update the account (password only when provided). Role changes go
     * through the same administrator-only guard; an administrator can never demote
     * themselves, so a system always keeps at least one admin.
     */
    public function updateUser(): void
    {
        abort_if($this->isViewer(), 403);

        $user = User::findOrFail($this->editingUserId);

        $validated = $this->validate();
        $selectedRole = $this->canAssignRoles()
            ? $validated['role']
            : 'user';

        $user->school_id = $selectedRole === 'user' && $validated['school_id_choice'] !== ''
            ? (int) $validated['school_id_choice']
            : null;
        $user->name = $validated['name'];
        $user->username = $validated['username'];
        $user->email = $validated['email'];
        if ($this->canAssignRoles() && ! $user->is($this->authenticatedUser())) {
            $user->role = $selectedRole;
            $user->is_superadmin = $selectedRole === 'superadmin';
        }

        if (($validated['password'] ?? '') !== '') {
            $user->password = $validated['password']; // hashed by the model cast
        }

        $user->save();

        $this->showCreateModal = false;
        $this->editingUserId = null;
        $this->resetForm();
        $this->resetValidation();

        $this->successMessage = "Account updated — {$user->username} was saved.";

        $this->announce();
    }

    /**
     * Called by the view right after the SweetAlert has been popped, so the
     * message doesn't re-fire on subsequent renders/polls.
     */
    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    /**
     * Match the app's own table look instead of Tailwind's.
     * (Must be public: Livewire's pagination feature resolves it via __call.)
     */
    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    /**
     * The district list powering the School Division dropdown — straight
     * from the districts table (never hardcoded).
     */
    #[Computed]
    public function divisions()
    {
        return District::query()->orderBy('name')->get();
    }

    /**
     * The schools inside the selected division — the School dropdown's
     * options, each carrying its School ID for the read-only field.
     */
    #[Computed]
    public function divisionSchools()
    {
        if ($this->school_division === '') {
            return collect();
        }

        return School::query()
            ->where('district_id', $this->school_division)
            ->orderBy('name')
            ->get(['id', 'name', 'school_id']);
    }

    /**
     * The selected school's DepEd School ID, shown read-only in the form —
     * it always comes from the school record, never typed by hand.
     */
    #[Computed]
    public function selectedSchoolId(): string
    {
        if ($this->school_id_choice === '') {
            return '';
        }

        return (string) ($this->divisionSchools
            ->firstWhere('id', (int) $this->school_id_choice)
            ?->school_id ?? '');
    }

    /**
     * The school record behind the current selection (its School ID is
     * saved on the user; the choice field holds the school's id).
     */
    #[Computed]
    public function selectedSchool(): ?School
    {
        if ($this->school_id_choice === '') {
            return null;
        }

        return School::find((int) $this->school_id_choice);
    }

    /**
     * Division changed: drop the school choice (it belonged to the old
     * division) so the School dropdown starts fresh.
     */
    public function updatedSchoolDivision(): void
    {
        $this->school_id_choice = '';
        $this->resetValidation(['school_id_choice']);
    }

    /**
     * School changed: the school's DepEd School ID becomes the default login.
     *
     * An SH signs in with their school's ID, so picking the school is enough
     * to fill the Username field in. The field stays editable — the superadmin
     * can type another login over it — and a login they already typed survives
     * a later change of school: the prefill only takes the field back while it
     * still holds the default we wrote (or nothing at all).
     */
    public function updatedSchoolIdChoice(): void
    {
        $this->resetValidation(['username']);

        $schoolId = $this->selectedSchoolId;

        if ($schoolId === '') {
            // This school has no ID to default to, so the last school's ID
            // is now a stale prefill: drop it, but only the one we wrote
            // ourselves — a login the superadmin typed stays theirs.
            if ($this->username === $this->usernamePrefill) {
                $this->username = '';
            }

            $this->usernamePrefill = '';

            return;
        }

        if ($this->username === '' || $this->username === $this->usernamePrefill) {
            $this->username = $schoolId;
            $this->usernamePrefill = $schoolId;
        }
    }

    #[Computed]
    public function rows()
    {
        return User::query()
            ->when($this->search !== '', function ($query) {
                $term = '%'.str_replace('%', '\%', $this->search).'%';

                $query->where(function ($query) use ($term) {
                    $query->where('username', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('name', 'like', $term);
                });
            })
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($this->perPage);
    }

    /**
     * Keep the page pointer inside bounds when filters change.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    /**
     * Fired by `wire:poll` on the view (every 2s) so the table picks up rows
     * created elsewhere — Google sign-ins, other admins, seeders — live.
     */
    #[On('refresh-users')]
    public function refreshUsers(): void
    {
        // Recomputed on render; nothing else to do.
    }

    /**
     * Tell the live sidebar the dataset changed so its badge updates too.
     */
    private function announce(): void
    {
        $this->dispatch('users-refreshed')->to(Sidebar::class);
    }

    public function deleteUser(User $user): void
    {
        abort_if($this->isViewer(), 403);

        if ($user->is($this->authenticatedUser())) {
            // Refuse to lock yourself out of the system.
            return;
        }

        $username = $user->username;

        $user->delete();

        $this->successMessage = "Account deleted — {$username} was removed.";

        $this->announce();
    }

    private function authenticatedUser(): ?User
    {
        return Auth::user();
    }

    public function canManageUsers(): bool
    {
        return ! $this->isViewer();
    }

    public function canChangeRoles(): bool
    {
        return $this->canAssignRoles();
    }

    private function isViewer(): bool
    {
        return $this->authenticatedUser()?->role === 'viewer';
    }

    public function render()
    {
        return view('livewire.users.table', [
            'users' => $this->rows,
        ]);
    }
}
