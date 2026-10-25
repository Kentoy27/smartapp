<?php

namespace App\Livewire;

use App\Support\WfpLookupImporter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The WFP Setup lookup table: one component serving every lookup category
 * (funding sources, units, resource kinds, organizational classifications),
 * driven by the category spec in config/wfp_lookups.php.
 *
 * The component is Administrator-only (the same gate as the Account
 * Classifications module — a superadmin gets the same 404 a staff member
 * would), re-checked in mount() and at the top of every mutating action,
 * because the hidden menu item is a convenience, never the security boundary.
 *
 * Records are retired, never hard-deleted: "Delete" sets `is_active = false`
 * so a historical line or template that points at one stays intact, and an
 * "Activate" action puts a retired one back. Every create/update/activate/
 * deactivate writes an entry to the shared WFP lookup audit trail.
 *
 * Bulk import is two-phase and mode-aware: the file is analyzed into a
 * preview first (nothing is written), and only then committed inside a
 * transaction (see WfpLookupImporter). The file is re-analyzed at commit
 * time, so rows are never trusted from the browser.
 */
#[Title('WFP Setup — SmartApp')]
class WfpLookupTable extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** How many analysed rows are sent to the browser for the preview. */
    private const IMPORT_PREVIEW_LIMIT = 200;

    /** The category slug from the route ('funding-sources', 'units', …). */
    #[Locked]
    public string $categorySlug = '';

    /** Search term, matched against every text column of the category. */
    public string $search = '';

    /** Status filter: 'all', 'active' or 'inactive'. */
    public string $status = 'all';

    /** Sort order: 'name', 'code' or 'recent'. */
    public string $sort = 'name';

    public int $perPage = 10;

    /**
     * "Add / Edit" modal state. One modal serves both modes: a null
     * $editingId creates a record, otherwise the record with that id is
     * updated. `$form` holds the category's own columns (code, name, …).
     */
    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $form = [];

    /** The status picked in the form: 'active' or 'inactive'. */
    public string $formStatus = 'active';

    /** Deactivate/Delete confirmation state. */
    public bool $showConfirm = false;

    public ?int $confirmId = null;

    public string $confirmTitle = '';

    /** Success toast, popped as a SweetAlert then cleared via clearSuccess(). */
    public ?string $successMessage = null;

    /* Bulk import state. */

    public $importFile;

    public bool $showImport = false;

    /** 'insert' | 'update' | 'upsert'. */
    public string $importMode = 'insert';

    /** @var array<int, array<string, mixed>> */
    public array $importRows = [];

    public int $importTotalRows = 0;

    public int $importValidCount = 0;

    public int $importInvalidCount = 0;

    public int $importSkippedCount = 0;

    public ?string $importError = null;

    /** Whether the import has been committed — the modal then shows the report. */
    public bool $importCommitted = false;

    public int $lastInserted = 0;

    public int $lastUpdated = 0;

    public int $lastSkipped = 0;

    public int $lastFailed = 0;

    public ?int $lastImportLogId = null;

    public function mount(string $category): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->categorySlug = $category;
        $this->spec(); // aborts 404 for an unknown category

        $this->resetForm();
    }

    /**
     * The category spec from config, or a 404 for an unknown slug.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function spec(): array
    {
        $spec = config('wfp_lookups.categories.'.$this->categorySlug);

        abort_unless(is_array($spec) && isset($spec['model']), 404);

        return $spec;
    }

    private function guard(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);
        abort_unless(is_array(config('wfp_lookups.categories.'.$this->categorySlug)), 404);
    }

    /**
     * The editable (non-boolean) columns of this category, in spec order.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function formFields(): array
    {
        return array_keys(array_filter(
            $this->spec['columns'],
            static fn (array $columnSpec): bool => empty($columnSpec['boolean'])
        ));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $rules = [];

        foreach ($this->spec['columns'] as $field => $columnSpec) {
            if (! empty($columnSpec['boolean'])) {
                continue;
            }

            $rules['form.'.$field] = [
                ($columnSpec['required'] ?? false) ? 'required' : 'nullable',
                'string',
                'max:'.($columnSpec['max'] ?? 255),
            ];
        }

        $rules['formStatus'] = ['required', Rule::in(['active', 'inactive'])];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [];

        foreach ($this->spec['columns'] as $field => $columnSpec) {
            if (! empty($columnSpec['boolean'])) {
                continue;
            }

            $attributes['form.'.$field] = mb_strtolower((string) $columnSpec['label']);
        }

        return $attributes;
    }

    /** Trim every text field before it is validated or saved. */
    private function prepare(): void
    {
        foreach ($this->form as $field => $value) {
            $this->form[$field] = trim((string) $value);
        }
    }

    private function resetForm(): void
    {
        $this->form = array_fill_keys($this->formFields, '');
        $this->formStatus = 'active';
    }

    public function openCreate(): void
    {
        $this->guard();

        $this->resetForm();
        $this->resetValidation();
        $this->editingId = null;
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $this->guard();

        $model = $this->spec['model'];
        $record = $model::findOrFail($id);

        $this->resetForm();
        $this->resetValidation();
        $this->editingId = $record->id;

        foreach ($this->formFields as $field) {
            $this->form[$field] = (string) ($record->{$field} ?? '');
        }

        $this->formStatus = $record->is_active ? 'active' : 'inactive';
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
        $this->guard();

        $this->prepare();
        $this->validate();

        $spec = $this->spec;
        $model = $spec['model'];

        $attributes = [];

        foreach ($this->formFields as $field) {
            $value = (string) ($this->form[$field] ?? '');
            $attributes[$field] = $value === '' ? null : $value;
        }

        if ($model::duplicateExists($attributes, $this->editingId)) {
            $this->addError(
                'form.name',
                'An active '.mb_strtolower($spec['singular']).' with this name or code already exists.'
            );

            return;
        }

        $user = Auth::user();
        $isActive = $this->formStatus === 'active';

        if ($this->editingId === null) {
            $record = $model::create([
                ...$attributes,
                'is_active' => $isActive,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            $record->recordLookupAudit($model::AUDIT_CREATE, $user, null, $record->auditSnapshot());

            $this->successMessage = "{$spec['singular']} created — “{$record->name}” was added.";
            $this->resetPage();
        } else {
            $record = $model::findOrFail($this->editingId);
            $before = $record->auditSnapshot();

            $record->fill($attributes);
            $record->is_active = $isActive;
            $record->updated_by = $user?->id;
            $record->save();

            $record->recordLookupAudit($model::AUDIT_UPDATE, $user, $before, $record->auditSnapshot());

            $this->successMessage = "{$spec['singular']} updated — “{$record->name}” was saved.";
        }

        $this->closeForm();
    }

    public function openConfirmDelete(int $id): void
    {
        $this->guard();

        $model = $this->spec['model'];
        $record = $model::findOrFail($id);

        $this->confirmId = $record->id;
        $this->confirmTitle = (string) $record->name;
        $this->showConfirm = true;
    }

    public function closeConfirmDelete(): void
    {
        $this->showConfirm = false;
        $this->confirmId = null;
        $this->confirmTitle = '';
    }

    /**
     * Retire a record: `is_active = false` rather than a hard delete, so
     * historical lines and templates that point at it stay valid.
     */
    public function confirmDelete(): void
    {
        $this->guard();

        $model = $this->spec['model'];
        $record = $model::findOrFail($this->confirmId);
        $before = $record->auditSnapshot();

        $record->is_active = false;
        $record->updated_by = Auth::id();
        $record->save();

        $record->recordLookupAudit($model::AUDIT_DEACTIVATE, Auth::user(), $before, $record->auditSnapshot());

        $name = $record->name;
        $this->closeConfirmDelete();
        $this->successMessage = "{$this->spec['singular']} deleted — “{$name}” was deactivated and hidden from new selections.";
    }

    /** Put a retired record back into circulation. */
    public function activate(int $id): void
    {
        $this->guard();

        $model = $this->spec['model'];
        $record = $model::findOrFail($id);

        if ($record->is_active) {
            return;
        }

        $before = $record->auditSnapshot();

        $record->is_active = true;
        $record->updated_by = Auth::id();
        $record->save();

        $record->recordLookupAudit($model::AUDIT_ACTIVATE, Auth::user(), $before, $record->auditSnapshot());

        $this->successMessage = "{$this->spec['singular']} activated — “{$record->name}” is available again.";
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
        $this->guard();

        $this->resetImport();
        $this->showImport = true;
    }

    public function closeImport(): void
    {
        $this->showImport = false;
        $this->resetImport();
    }

    /**
     * A CSV was picked: validate the upload and analyze every row under the
     * chosen mode. Nothing is written here — the preview is what the
     * administrator confirms.
     */
    public function updatedImportFile(): void
    {
        $this->guard();

        $this->resetImportState();

        if ($this->importFile === null) {
            return;
        }

        $maxKb = (int) config('wfp_lookups.import.max_size_kb', 5120);

        $this->validate([
            'importFile' => [
                'required', 'file', 'max:'.$maxKb,
                'extensions:'.(string) config('wfp_lookups.import.extensions', 'csv,txt'),
            ],
        ], [
            'importFile.required' => 'Choose a CSV file to import.',
            'importFile.extensions' => 'The import must be a .csv file.',
            'importFile.max' => 'The file is too large ('.round($maxKb / 1024, 1).' MB maximum).',
        ]);

        $this->analyzeImport();
    }

    /** The import mode changed: re-analyze so the preview shows the new plan. */
    public function updatedImportMode(): void
    {
        $this->guard();

        if ($this->importFile === null || $this->importCommitted) {
            return;
        }

        $this->analyzeImport();
    }

    private function analyzeImport(): void
    {
        try {
            $analysis = WfpLookupImporter::analyzeCsv($this->importFile->getRealPath(), $this->categorySlug, $this->importMode);
        } catch (RuntimeException $exception) {
            $this->importFile = null;
            $this->importError = $exception->getMessage();

            return;
        }

        $this->importTotalRows = $analysis['totalCount'];
        $this->importRows = array_slice($analysis['rows'], 0, self::IMPORT_PREVIEW_LIMIT);
        $this->importValidCount = $analysis['validCount'];
        $this->importInvalidCount = $analysis['invalidCount'];
        $this->importSkippedCount = $analysis['skippedCount'];

        if ($this->importTotalRows === 0) {
            $this->importError = 'The file has no data rows.';
        }
    }

    /**
     * Commit the import. The file is re-analyzed here — its rows are never
     * trusted from the client — and written inside a transaction, so a
     * failure leaves no partially imported catalogue.
     */
    public function commitImport(): void
    {
        $this->guard();

        if ($this->importFile === null) {
            $this->importError = 'Choose a CSV file to import.';

            return;
        }

        try {
            $analysis = WfpLookupImporter::analyzeCsv($this->importFile->getRealPath(), $this->categorySlug, $this->importMode);
        } catch (RuntimeException $exception) {
            $this->importError = $exception->getMessage();

            return;
        }

        if ($analysis['validCount'] === 0 && $analysis['invalidCount'] === 0) {
            $this->importError = 'There are no valid rows to import.';

            return;
        }

        $report = WfpLookupImporter::commit(
            $analysis,
            $this->categorySlug,
            $this->importMode,
            Auth::user(),
            $this->importFile->getClientOriginalName(),
        );

        $this->importCommitted = true;
        $this->lastInserted = $report['inserted'];
        $this->lastUpdated = $report['updated'];
        $this->lastSkipped = $report['skipped'];
        $this->lastFailed = $report['failed'];
        $this->lastImportLogId = $report['log']->id;
        $this->importRows = [];

        $this->resetPage();

        $this->successMessage = sprintf(
            'Import complete — %d inserted, %d updated, %d skipped, %d failed.',
            $report['inserted'],
            $report['updated'],
            $report['skipped'],
            $report['failed'],
        );
    }

    private function resetImport(): void
    {
        $this->importFile = null;
        $this->resetImportState();
        $this->importMode = 'insert';
        $this->importCommitted = false;
        $this->lastInserted = 0;
        $this->lastUpdated = 0;
        $this->lastSkipped = 0;
        $this->lastFailed = 0;
        $this->lastImportLogId = null;
    }

    private function resetImportState(): void
    {
        $this->importRows = [];
        $this->importTotalRows = 0;
        $this->importValidCount = 0;
        $this->importInvalidCount = 0;
        $this->importSkippedCount = 0;
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
        $model = $this->spec['model'];

        return $model::query()
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(function ($query) use ($term) {
                    foreach ($this->formFields as $field) {
                        $query->orWhere($field, 'like', $term);
                    }
                });
            })
            ->when($this->status === 'active', fn ($query) => $query->active())
            ->when($this->status === 'inactive', fn ($query) => $query->inactive())
            ->when($this->sort === 'name', fn ($query) => $query->orderBy('name')->orderBy('id'))
            ->when($this->sort === 'code', fn ($query) => $query->orderBy('code')->orderBy('name'))
            ->when($this->sort === 'recent', fn ($query) => $query->orderByDesc('updated_at')->orderByDesc('id'))
            ->paginate($this->perPage);
    }

    public function render()
    {
        return view('livewire.wfp.lookup-table', [
            'records' => $this->rows,
            'spec' => $this->spec,
            'categorySlug' => $this->categorySlug,
            'formFields' => $this->formFields,
        ]);
    }
}
