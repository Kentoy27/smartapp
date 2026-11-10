<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesAssignedSchools;
use App\Models\District;
use App\Models\School;
use App\Models\User;
use App\Support\SchoolDivision;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users — SmartApp')]
class UsersTable extends Component
{
    use ManagesAssignedSchools;
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

    public bool $showResetPasswordModal = false;

    public ?int $resetPasswordUserId = null;

    public string $resetPasswordUsername = '';

    public string $resetPasswordDefault = '';

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
     * school within it — the school's own School ID fills the login by
     * default. Null school_id = no school (superadmins typically).
     */
    public string $school_division = '';

    public string $school_id_choice = '';

    /**
     * The divisions this school-side account may work in — one, two, or all
     * three of the divisions its selected school operates. Only an
     * administrator sets this; the payload is re-validated against the
     * school's own divisions on save and never trusted.
     *
     * @var array<int, string>
     */
    public array $division_access = [];

    public string $username = '';

    /**
     * The School ID this form last filled into $username for the administrator.
     *
     * Picking a school prefills the login with that school's ID. Keeping
     * track of what we wrote is what lets a later change of school refresh
     * the default while leaving a login the administrator typed by hand alone.
     */
    public string $usernamePrefill = '';

    public string $email = '';

    public string $password = '';

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
        $belongsToSchool = $this->belongsToSchool();
        $passwordRules = $editing
            ? ($this->isSchoolHead()
                ? ['nullable', Password::min(12)->mixedCase()->numbers()->symbols()]
                : ['nullable', 'string', 'min:8'])
            : ($this->isSchoolHead()
                ? ['required', Password::min(12)->mixedCase()->numbers()->symbols()]
                : ['required', 'string', 'min:8']);

        $rules = [
            // Only the school-side roles (SH and Bookkeeper) belong to a
            // school. For the other roles the cascade is not even rendered,
            // so it must not be required here either — and a crafted request
            // cannot smuggle a school in.
            'school_division' => $belongsToSchool ? ['required', 'string'] : ['nullable', 'string'],
            // The school must belong to the selected division (the dropdown
            // only offers those, but the rule is the real boundary).
            'school_id_choice' => $belongsToSchool ? [
                'required', 'string',
                Rule::in(School::query()
                    ->where('district_id', $this->school_division)
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->push('')
                    ->all()),
            ] : ['nullable', 'string'],
            // Division Access is the authoritative list. A school-side
            // account must work in at least one division, and every named
            // division must be one the selected school actually operates —
            // an integrated school may therefore carry all three.
            'division_access' => $belongsToSchool
                ? ['required', 'array', 'min:1']
                : ['nullable', 'array'],
            'division_access.*' => $belongsToSchool
                ? [Rule::in($this->availableDivisions())]
                : ['nullable'],
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($this->editingUserId),
            ],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->editingUserId),
            ],
            // School Head passwords must be private, strong, and entered
            // explicitly; a public school identifier is never a password.
            'password' => $passwordRules,
            'role' => ['required', Rule::in(['user', 'viewer', 'bookkeeper', 'budget', 'administrator', 'superadmin'])],
        ];

        // The Bookkeeper's roster is edited in this same modal, so its rules
        // are part of the form exactly while the form IS a Bookkeeper's — for
        // the other roles a crafted list is ignored rather than saved.
        if ($this->role === 'bookkeeper') {
            $rules = array_merge($rules, $this->assignedSchoolRules());
        }

        return $rules;
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
            'division_access' => 'division access',
            'name' => 'name',
            'username' => 'username',
            'email' => 'email',
            'password' => 'password',
        ] + $this->assignedSchoolAttributes();
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

        // The Bookkeeper's roster, read from the account's own assignment
        // rows. For every other role the picker is left empty — it is not
        // rendered for them, and an empty list is what their save ignores.
        $this->loadAssignedSchools($user);

        // Show what the account can actually work in. A row written before
        // the explicit list existed is displayed through its classification;
        // anything the school no longer offers is not offered back.
        $assigned = SchoolDivision::validAccessList($user->division_access);

        if ($assigned === []) {
            $assigned = $user->authorizedDivisions();
        }

        $this->division_access = array_values(array_intersect(
            $assigned,
            $user->school?->availableDivisions() ?? [],
        ));
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
            'selectedRole' => ['required', Rule::in(['user', 'viewer', 'bookkeeper', 'budget', 'administrator', 'superadmin'])],
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
            'bookkeeper' => 'Bookkeeper',
            'budget' => 'Budget',
            'viewer' => 'SDS Viewer / Validator',
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

    public function openResetPasswordModal(User $user): void
    {
        abort_unless($this->canResetPassword($user), 403);

        $this->resetPasswordUserId = $user->id;
        $this->resetPasswordUsername = $user->username;
        $this->resetPasswordDefault = $this->schoolIdForPasswordReset($user) ?? '';
        $this->showResetPasswordModal = true;
    }

    public function closeResetPasswordModal(): void
    {
        $this->showResetPasswordModal = false;
        $this->resetPasswordUserId = null;
        $this->resetPasswordUsername = '';
        $this->resetPasswordDefault = '';
    }

    public function confirmResetPassword(): void
    {
        abort_unless($this->authenticatedUser()?->canManageUsersAndSchools(), 403);

        $user = User::with('school')->findOrFail($this->resetPasswordUserId);
        abort_unless($this->canResetPassword($user), 403);

        $schoolId = $this->schoolIdForPasswordReset($user);
        abort_unless($schoolId !== null, 403);

        $user->forceFill(['password' => $schoolId])->save();
        $username = $user->username;

        $this->closeResetPasswordModal();
        $this->successMessage = "Password reset for {$username}. The password is now the School ID.";
    }

    private function resetForm(): void
    {
        $this->school_division = '';
        $this->school_id_choice = '';
        $this->division_access = [];
        $this->resetAssignedSchools();
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
        if (! in_array($role, ['user', 'viewer', 'bookkeeper', 'budget', 'administrator', 'superadmin'], true)) {
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

    /**
     * Does the form's role carry a school? The School Head and the Bookkeeper
     * are both attached to one school (each one's whole job is that school's
     * WFP work), so both render the Division → School cascade and both must
     * pick a school to save. The School-ID-as-login default stays an SH-only
     * convenience — see isSchoolHead().
     */
    public function belongsToSchool(): bool
    {
        return in_array($this->role, ['user', 'bookkeeper'], true);
    }

    /**
     * The divisions the selected school operates — the only choices the
     * Division Access section may offer.
     *
     * @return array<int, string>
     */
    public function availableDivisions(): array
    {
        return $this->selectedSchool?->availableDivisions() ?? [];
    }

    /**
     * The Division Access options: exactly the divisions the selected school
     * operates, so an integrated school offers all three.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function divisionAccessOptions(): array
    {
        return array_map(
            fn (string $division): array => [
                'value' => $division,
                'label' => SchoolDivision::label($division),
            ],
            $this->availableDivisions(),
        );
    }

    /**
     * Checking a division fixes a missing-selection error immediately, so the
     * old message does not sit under a field the administrator just filled.
     */
    public function updatedDivisionAccess(): void
    {
        $this->resetValidation(['division_access', 'division_access.*']);
    }

    public function updatedRole(string $role): void
    {
        $this->isSuperadmin = $role === 'superadmin';

        // Switching to a role that carries no school hides the cascade, so
        // drop whatever it held — otherwise a hidden selection would silently
        // attach the account to a school on save. The assignment picker is
        // dropped with it: a role with no roster must not carry one into a
        // save from behind a hidden field.
        if (! in_array($role, ['user', 'bookkeeper'], true)) {
            $this->school_division = '';
            $this->school_id_choice = '';
            $this->usernamePrefill = '';
            $this->division_access = [];
            $this->resetAssignedSchools();
        }
    }

    /**
     * The server-side classification written on save: the explicit access
     * list, plus the broad level and default sublevel derived from it for
     * the parts of the app that still read the classification.
     *
     * Only divisions the selected school operates survive this method. The
     * form's rules and this method guard the same boundary, and only this
     * one is trusted.
     *
     * @return array{school_level: string, school_sublevel: string, division_access: ?array<int, string>}
     */
    private function classificationFor(string $role, ?string $preferredSublevel = null): array
    {
        if (! in_array($role, ['user', 'bookkeeper'], true) || ! $this->canAssignRoles()) {
            return [
                'school_level' => SchoolDivision::LEVEL_ELEMENTARY,
                'school_sublevel' => SchoolDivision::SUBLEVEL_NONE,
                'division_access' => null,
            ];
        }

        $access = array_values(array_intersect(
            SchoolDivision::validAccessList($this->division_access),
            $this->availableDivisions(),
        ));

        if ($access === []) {
            return [
                'school_level' => SchoolDivision::LEVEL_ELEMENTARY,
                'school_sublevel' => SchoolDivision::SUBLEVEL_NONE,
                'division_access' => null,
            ];
        }

        $high = array_values(array_intersect($access, SchoolDivision::highSchoolDivisions()));

        // The default division: keep the account's existing high-school
        // sublevel when it is still assigned, otherwise the first one.
        $sublevel = in_array($preferredSublevel, $high, true)
            ? $preferredSublevel
            : ($high[0] ?? SchoolDivision::SUBLEVEL_NONE);

        return [
            'school_level' => $high !== []
                ? SchoolDivision::LEVEL_HIGH_SCHOOL
                : SchoolDivision::LEVEL_ELEMENTARY,
            'school_sublevel' => $sublevel,
            'division_access' => $access,
        ];
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

        $classification = $this->classificationFor($selectedRole);

        $user = new User;
        $user->forceFill([
            // SH and Bookkeeper are both school-side roles; for the others a
            // crafted school choice is dropped rather than attached.
            'school_id' => in_array($selectedRole, ['user', 'bookkeeper'], true) && $validated['school_id_choice'] !== ''
                ? (int) $validated['school_id_choice']
                : null,
            'school_level' => $classification['school_level'],
            'school_sublevel' => $classification['school_sublevel'],
            'division_access' => $classification['division_access'],
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $selectedRole,
            'is_superadmin' => $selectedRole === 'superadmin',
        ])->save();

        // The model seeds the account's base school into its roster at
        // creation; the administrator's own list — which already holds the
        // picked base school, because updatedSchoolIdChoice() puts it there —
        // replaces that seed when a list was actually chosen. Only schools
        // that exist survive the sync (the model re-checks), so a crafted
        // payload cannot reach the pivot.
        if ($selectedRole === 'bookkeeper') {
            $chosen = array_map(intval(...), $validated['assigned_schools'] ?? []);

            if ($chosen !== []) {
                $user->syncAssignedSchools($chosen);
            }
        }

        $this->showCreateModal = false;
        $this->editingUserId = null;
        $this->resetForm();
        $this->resetValidation();

        // Jump to page 1 (newest first) so the fresh account is visible.
        $this->resetPage();

        $roleDescription = match ($user->role) {
            'superadmin' => 'a super admin',
            'administrator' => 'an administrator',
            'bookkeeper' => 'a bookkeeper',
            'budget' => 'a Budget user',
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

        $classification = $this->classificationFor($selectedRole, $user->schoolSublevel());

        $user->school_id = in_array($selectedRole, ['user', 'bookkeeper'], true) && $validated['school_id_choice'] !== ''
            ? (int) $validated['school_id_choice']
            : null;
        $user->school_level = $classification['school_level'];
        $user->school_sublevel = $classification['school_sublevel'];
        $user->division_access = $classification['division_access'];
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

        // The school-side roster follows the save. A School Head works
        // nowhere but their own school, so their roster is exactly that; a
        // Bookkeeper's roster is exactly the modal's list — the base school
        // is added to it the moment it is picked, so an empty list is a
        // deliberate revocation, not a field the administrator never saw.
        // Roles that carry no school keep whatever they had: nothing reads
        // their roster.
        if ($selectedRole === 'user') {
            $user->syncAssignedSchools($user->school_id !== null ? [(int) $user->school_id] : []);
        } elseif ($selectedRole === 'bookkeeper') {
            $user->syncAssignedSchools(array_map(intval(...), $validated['assigned_schools'] ?? []));
        }

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
     * An SH may use their school's ID as a username, so picking the school is
     * enough to fill the Username field in. The field stays editable — the administrator
     * can type another login over it — and a login they already typed survives
     * a later change of school: the prefill only takes the field back while it
     * still holds the default we wrote (or nothing at all).
     *
     * The default is an SH convenience alone: a Bookkeeper is attached to the
     * school but signs in with their own login, so their Username field is
     * never touched here.
     */
    public function updatedSchoolIdChoice(): void
    {
        $this->resetValidation(['username', 'division_access', 'division_access.*']);

        // The new school's menu is the boundary: keep only choices it still
        // offers. A single-division school preselects its only division; a
        // multi-division school leaves the choice explicit.
        $available = $this->availableDivisions();

        $this->division_access = array_values(array_intersect(
            SchoolDivision::validAccessList($this->division_access),
            $available,
        ));

        if ($this->division_access === [] && count($available) === 1) {
            $this->division_access = $available;
        }

        // A Bookkeeper's picked school is where they are stationed, so it
        // joins the assignment list the moment it is picked: the list on
        // screen is then exactly what a save writes. The administrator can
        // still take it off the list like any other school.
        if ($this->role === 'bookkeeper' && $this->school_id_choice !== '') {
            $this->addAssignedSchool((int) $this->school_id_choice);
        }

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

        if ($this->isSchoolHead() && ($this->username === '' || $this->username === $this->usernamePrefill)) {
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

    public function canResetPassword(User $user): bool
    {
        return (bool) $this->authenticatedUser()?->canManageUsersAndSchools()
            && $user->role === 'user'
            && $user->school_id !== null
            && $this->schoolIdForPasswordReset($user) !== null;
    }

    private function schoolIdForPasswordReset(User $user): ?string
    {
        $schoolId = trim((string) $user->school?->school_id);

        if ($schoolId !== '') {
            return $schoolId;
        }

        if (preg_match('/^([0-9]+)@deped\.gov\.ph$/i', trim((string) $user->email), $matches) === 1) {
            return $matches[1];
        }

        return null;
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
