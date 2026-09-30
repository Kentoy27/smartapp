<?php

namespace App\Livewire;

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
        abort_unless(Auth::user()?->is_superadmin, 404);
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

    public string $name = '';

    public string $employee_id = '';

    public string $username = '';

    public string $email = '';

    public string $password = '';

    /**
     * Role selection in the add/edit modal. Only superadmins may set or
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

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'employee_id' => [
                'nullable', 'string', 'max:50',
                Rule::unique('users', 'employee_id')->ignore($this->editingUserId),
            ],
            'username' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($this->editingUserId),
            ],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->editingUserId),
            ],
            // Required when creating; optional when editing (blank = keep).
            'password' => $editing
                ? ['nullable', 'string', 'min:8']
                : ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['user', 'viewer', 'superadmin'])],
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
            'name' => 'real name',
            'employee_id' => 'employee ID',
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
        $this->name = (string) $user->name;
        $this->employee_id = (string) $user->employee_id;
        $this->username = (string) $user->username;
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
            'selectedRole' => ['required', Rule::in(['user', 'viewer', 'superadmin'])],
        ]);

        $user = User::findOrFail($this->roleUserId);
        abort_if($user->is($this->authenticatedUser()), 403);

        $user->forceFill([
            'role' => $validated['selectedRole'],
            'is_superadmin' => $validated['selectedRole'] === 'superadmin',
        ])->save();

        $username = $user->username;
        $role = $validated['selectedRole'] === 'superadmin'
            ? 'Super Admin'
            : ($validated['selectedRole'] === 'viewer' ? 'SDS Viewer' : 'SH');

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
        $this->name = '';
        $this->employee_id = '';
        $this->username = '';
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
        if (! in_array($role, ['user', 'viewer', 'superadmin'], true)) {
            return;
        }

        $this->role = $role;
        $this->isSuperadmin = $role === 'superadmin';
    }

    public function updatedRole(string $role): void
    {
        $this->isSuperadmin = $role === 'superadmin';
    }

    /**
     * The signed-in user may grant/revoke the superadmin role only if they
     * hold it themselves. Guarded server-side — the checkbox is a UI
     * convenience, never the security boundary.
     */
    private function canAssignRoles(): bool
    {
        return (bool) $this->authenticatedUser()?->is_superadmin;
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

        $user = User::create([
            'name' => $validated['name'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => $validated['password'], // hashed by the model cast
            'role' => $selectedRole,
            'is_superadmin' => $selectedRole === 'superadmin',
        ]);

        $this->showCreateModal = false;
        $this->editingUserId = null;
        $this->resetForm();
        $this->resetValidation();

        // Jump to page 1 (newest first) so the fresh account is visible.
        $this->resetPage();

        $role = $user->is_superadmin ? 'super admin' : ($user->role === 'viewer' ? 'viewer' : 'user');
        $this->successMessage = "Account created — {$user->username} was added as a {$role}.";

        $this->announce();
    }

    /**
     * Update the account (password only when provided). Role changes go
     * through the same superadmin-only guard; a superadmin can never demote
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

        $user->name = $validated['name'] ?? null;
        $user->employee_id = $validated['employee_id'] ?? null;
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

    #[Computed]
    public function rows()
    {
        return User::query()
            ->when($this->search !== '', function ($query) {
                $term = '%' . str_replace('%', '\%', $this->search) . '%';

                $query->where(function ($query) use ($term) {
                    $query->where('username', 'like', $term)
                        ->orWhere('employee_id', 'like', $term)
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
