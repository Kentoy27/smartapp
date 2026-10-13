<?php

namespace App\Livewire;

use App\Models\AccountClassification;
use App\Support\AccountClassificationImporter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The Account Classifications admin module: a searchable, filterable table of
 * the RCA / UACS reference data with create, edit, and activate/deactivate.
 *
 * This module belongs to the Administrator side of the app, not to superadmins:
 * only an account that manages users and schools may reach it — checked in
 * mount() and re-checked at the top of every mutating action, because the
 * hidden menu item is a convenience, never the security boundary.
 *
 * Classifications are retired, never hard-deleted: "Delete" sets
 * `is_active = false` so a classification a historical record points at stays
 * intact, and an "Activate" action puts a retired one back.
 */
#[Title('Account Classifications — SmartApp')]
class AccountClassificationsTable extends Component
{
    use WithPagination;
    use WithFileUploads;

    /** How many analysed rows are shown in the import preview (all are still
     *  validated and committed — this only caps what is sent to the browser). */
    private const IMPORT_PREVIEW_LIMIT = 200;

    /** Search term, matched against the title, every code and the PPAS. */
    public string $search = '';

    /** Status filter: 'all', 'active' or 'inactive'. */
    public string $status = 'all';

    /** Sort order: 'title', 'rca', 'object' or 'recent'. */
    public string $sort = 'title';

    public int $perPage = 10;

    /**
     * "Add / Edit" modal state. One modal serves both modes: a null
     * $editingId creates a record, otherwise the record with that id is
     * updated.
     */
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $account_title = '';

    public string $rca_code = '';

    public string $uacs_sub_object_code = '';

    public string $uacs_object_code = '';

    public string $covering_ppas = '';

    /**
     * The status picked in the form: 'active' or 'inactive'. A string (not a
     * bool) so the <select>'s option values bind and preselect correctly.
     */
    public string $formStatus = 'active';

    /**
     * Deactivate/Delete confirmation state.
     */
    public bool $showConfirm = false;

    public ?int $confirmId = null;

    public string $confirmTitle = '';

    /** Success toast, popped as a SweetAlert then cleared via clearSuccess(). */
    public ?string $successMessage = null;

    /**
     * Bulk CSV import state: the chosen file, and the analysed preview shown
     * before anything is committed.
     */
    public $importFile;

    public bool $showImport = false;

    /** @var array<int, array<string, mixed>> */
    public array $importRows = [];

    public int $importTotalRows = 0;

    public int $importValidCount = 0;

    public int $importInvalidCount = 0;

    public ?string $importError = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            // Trim runs before validation (see prepare()), so a whitespace-only
            // value is caught as missing rather than saved as a blank title.
            'account_title' => ['required', 'string', 'max:255'],
            'rca_code' => ['required', 'string', 'max:32'],
            'uacs_sub_object_code' => ['required', 'string', 'max:32'],
            'uacs_object_code' => ['required', 'string', 'max:32'],
            // Optional: the source reference does not always provide a PPAS.
            'covering_ppas' => ['nullable', 'string', 'max:255'],
            'formStatus' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'account_title' => 'account title',
            'rca_code' => 'RCA code',
            'uacs_sub_object_code' => 'UACS sub-object code',
            'uacs_object_code' => 'UACS object code',
            'covering_ppas' => 'covering PPAS',
        ];
    }

    /**
     * Trim every text field before it is validated or saved. The codes are
     * only trimmed — never reformatted — so what the administrator typed is
     * what gets stored.
     */
    private function prepare(): void
    {
        $this->account_title = trim($this->account_title);
        $this->rca_code = trim($this->rca_code);
        $this->uacs_sub_object_code = trim($this->uacs_sub_object_code);
        $this->uacs_object_code = trim($this->uacs_object_code);
        $this->covering_ppas = trim($this->covering_ppas);
    }

    public function openCreate(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->resetForm();
        $this->resetValidation();
        $this->editingId = null;
        $this->formStatus = 'active';
        $this->showForm = true;
    }

    public function openEdit(AccountClassification $classification): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->resetForm();
        $this->resetValidation();
        $this->editingId = $classification->id;
        $this->account_title = (string) $classification->account_title;
        $this->rca_code = (string) $classification->rca_code;
        $this->uacs_sub_object_code = (string) $classification->uacs_sub_object_code;
        $this->uacs_object_code = (string) $classification->uacs_object_code;
        $this->covering_ppas = (string) ($classification->covering_ppas ?? '');
        $this->formStatus = $classification->is_active ? 'active' : 'inactive';
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->resetForm();
        $this->resetValidation();
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->prepare();
        $this->validate();

        $attributes = [
            'account_title' => $this->account_title,
            'rca_code' => $this->rca_code,
            'uacs_sub_object_code' => $this->uacs_sub_object_code,
            'uacs_object_code' => $this->uacs_object_code,
            'covering_ppas' => $this->covering_ppas !== '' ? $this->covering_ppas : null,
        ];

        if (AccountClassification::duplicateExists($attributes, $this->editingId)) {
            $this->addError(
                'account_title',
                'An active classification with this title and these codes already exists.'
            );

            return;
        }

        $user = Auth::user();

        $isActive = $this->formStatus === 'active';

        if ($this->editingId === null) {
            $attributes['is_active'] = $isActive;
            $classification = AccountClassification::create($attributes);
            $classification->recordAudit(AccountClassification::AUDIT_CREATE, $user, null, $classification->auditSnapshot());

            $this->successMessage = "Classification created — “{$classification->account_title}” was added.";
            $this->resetPage();
        } else {
            $classification = AccountClassification::findOrFail($this->editingId);
            $before = $classification->auditSnapshot();

            $classification->fill($attributes);
            $classification->is_active = $isActive;
            $classification->save();

            $classification->recordAudit(AccountClassification::AUDIT_UPDATE, $user, $before, $classification->auditSnapshot());

            $this->successMessage = "Classification updated — “{$classification->account_title}” was saved.";
        }

        $this->closeForm();
    }

    public function openConfirmDelete(AccountClassification $classification): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->confirmId = $classification->id;
        $this->confirmTitle = (string) $classification->account_title;
        $this->showConfirm = true;
    }

    public function closeConfirmDelete(): void
    {
        $this->showConfirm = false;
        $this->confirmId = null;
        $this->confirmTitle = '';
    }

    /**
     * Retire a classification: `is_active = false` rather than a hard delete,
     * so historical records that reference it stay valid.
     */
    public function confirmDelete(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $classification = AccountClassification::findOrFail($this->confirmId);
        $before = $classification->auditSnapshot();

        $classification->is_active = false;
        $classification->save();

        $classification->recordAudit(AccountClassification::AUDIT_DEACTIVATE, Auth::user(), $before, $classification->auditSnapshot());

        $title = $classification->account_title;
        $this->closeConfirmDelete();
        $this->successMessage = "Classification deleted — “{$title}” was deactivated and hidden from new selections.";
    }

    /**
     * Put a retired classification back into circulation.
     */
    public function activate(AccountClassification $classification): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        if ($classification->is_active) {
            return;
        }

        $before = $classification->auditSnapshot();

        $classification->is_active = true;
        $classification->save();

        $classification->recordAudit(AccountClassification::AUDIT_ACTIVATE, Auth::user(), $before, $classification->auditSnapshot());

        $this->successMessage = "Classification activated — “{$classification->account_title}” is available again.";
    }

    private function resetForm(): void
    {
        $this->account_title = '';
        $this->rca_code = '';
        $this->uacs_sub_object_code = '';
        $this->uacs_object_code = '';
        $this->covering_ppas = '';
        $this->formStatus = 'active';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
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

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    /* ---------------------------------------------------------------- import */

    public function openImport(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->resetImport();
        $this->showImport = true;
    }

    public function closeImport(): void
    {
        $this->showImport = false;
        $this->resetImport();
    }

    /**
     * A CSV was picked: validate the upload and analyze every row. Nothing is
     * written here — the preview is what the administrator confirms.
     */
    public function updatedImportFile(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->importError = null;
        $this->importRows = [];
        $this->importTotalRows = 0;
        $this->importValidCount = 0;
        $this->importInvalidCount = 0;

        $maxKb = AccountClassificationImporter::MAX_SIZE_KB;

        $this->validate([
            'importFile' => [
                'required', 'file', 'max:'.$maxKb,
                'extensions:'.AccountClassificationImporter::EXTENSIONS,
            ],
        ], [
            'importFile.required' => 'Choose a CSV file to import.',
            'importFile.extensions' => 'The import must be a .csv file.',
            'importFile.max' => 'The file is too large ('.round($maxKb / 1024, 1).' MB maximum).',
        ]);

        try {
            $analysis = AccountClassificationImporter::analyzeCsv($this->importFile->getRealPath());
        } catch (RuntimeException $exception) {
            $this->importFile = null;
            $this->importError = $exception->getMessage();

            return;
        }

        $this->importTotalRows = count($analysis['rows']);
        $this->importRows = array_slice($analysis['rows'], 0, self::IMPORT_PREVIEW_LIMIT);
        $this->importValidCount = $analysis['validCount'];
        $this->importInvalidCount = $analysis['invalidCount'];

        if ($this->importTotalRows === 0) {
            $this->importError = 'The file has no data rows.';
        }
    }

    /**
     * Commit the valid rows. The file is re-analyzed here — its rows are never
     * trusted from the client — so only rows that pass the same validation are
     * inserted.
     */
    public function commitImport(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        if ($this->importFile === null) {
            $this->importError = 'Choose a CSV file to import.';

            return;
        }

        try {
            $analysis = AccountClassificationImporter::analyzeCsv($this->importFile->getRealPath());
        } catch (RuntimeException $exception) {
            $this->importError = $exception->getMessage();

            return;
        }

        if ($analysis['validCount'] === 0) {
            $this->importError = 'There are no valid rows to import.';

            return;
        }

        $report = AccountClassificationImporter::commit($analysis['valid'], Auth::user());

        $this->showImport = false;
        $this->resetImport();
        $this->resetPage();

        $skipped = $report['skipped'] > 0
            ? " {$report['skipped']} skipped (already existed)."
            : '';

        $this->successMessage = "Import complete — {$report['imported']} classification(s) added.".$skipped;
    }

    private function resetImport(): void
    {
        $this->importFile = null;
        $this->importRows = [];
        $this->importTotalRows = 0;
        $this->importValidCount = 0;
        $this->importInvalidCount = 0;
        $this->importError = null;
        $this->resetValidation('importFile');
    }

    /**
     * Match the app's own table look instead of Tailwind's. (Must be public:
     * Livewire's pagination feature resolves it via __call.)
     */
    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    #[Computed]
    public function rows()
    {
        return AccountClassification::query()
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(function ($query) use ($term) {
                    $query->where('account_title', 'like', $term)
                        ->orWhere('rca_code', 'like', $term)
                        ->orWhere('uacs_sub_object_code', 'like', $term)
                        ->orWhere('uacs_object_code', 'like', $term)
                        ->orWhere('covering_ppas', 'like', $term);
                });
            })
            ->when($this->status === 'active', fn ($query) => $query->active())
            ->when($this->status === 'inactive', fn ($query) => $query->inactive())
            ->when($this->sort === 'title', fn ($query) => $query->orderBy('account_title'))
            ->when($this->sort === 'rca', fn ($query) => $query->orderBy('rca_code'))
            ->when($this->sort === 'object', fn ($query) => $query->orderBy('uacs_object_code'))
            ->when($this->sort === 'recent', fn ($query) => $query->orderByDesc('updated_at')->orderByDesc('id'))
            ->paginate($this->perPage);
    }

    public function render()
    {
        return view('livewire.account-classifications.table', [
            'classifications' => $this->rows,
        ]);
    }
}
