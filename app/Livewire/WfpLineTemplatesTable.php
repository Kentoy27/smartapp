<?php

namespace App\Livewire;

use App\Models\AccountClassification;
use App\Models\WfpFundingSource;
use App\Models\WfpImportLog;
use App\Models\WfpLineTemplate;
use App\Models\WfpOrgClassification;
use App\Models\WfpResourceKind;
use App\Models\WfpUnit;
use App\Support\WfpLineTemplateImporter;
use App\Support\WfpSetupExporter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The Activity / Expenditure Templates manager: the reusable combinations of
 * fields a School Head selects in the Add a Line form.
 *
 * Administrator-only (the same gate as the Account Classifications module),
 * re-checked in mount() and at the top of every mutating action, because the
 * hidden menu item is a convenience, never the security boundary.
 *
 * Templates reference the lookup library by id (a unit, a funding source,
 * the RCA / UACS account), never a copy of it. Editing a template never
 * touches a saved line: the line keeps its own snapshot of every value.
 *
 * Templates are retired with `is_active = false`, never hard-deleted, so a
 * saved WFP line that was encoded from one keeps its provenance.
 */
#[Title('Activity Templates — WFP Setup — SmartApp')]
class WfpLineTemplatesTable extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** How many analysed import rows are sent to the browser for the preview. */
    private const IMPORT_PREVIEW_LIMIT = 200;

    /** Search term, matched against name, activity, tags and account. */
    public string $search = '';

    /** Status filter: 'all', 'active' or 'inactive'. */
    public string $status = 'all';

    /** Sort order: 'name' or 'recent'. */
    public string $sort = 'name';

    public int $perPage = 10;

    /** Add / Edit modal state (one modal, two modes). */
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    /** 'operations' | 'project'. */
    public string $lineType = WfpLineTemplate::LINE_TYPE_OPERATIONS;

    public string $objective = '';

    public string $activityExpenditure = '';

    public ?int $resourceKindId = null;

    public ?int $unitId = null;

    public string $suggestedQuantity = '';

    public ?int $accountClassificationId = null;

    public ?int $fundingSourceId = null;

    public ?int $orgClassificationId = null;

    public string $suggestedUnitCost = '';

    public string $majorOutput = '';

    public string $performanceIndicator = '';

    public string $activityMovs = '';

    public string $tags = '';

    /** The status picked in the form: 'active' or 'inactive'. */
    public string $formStatus = 'active';

    /** Account combobox state: a live search over account classifications. */
    public string $accountSearch = '';

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

    public function mount(): void
    {
        abort_unless(Auth::user()?->canManageUsersAndSchools(), 404);

        $this->resetForm();
    }

    /** Trim every text field before it is validated or saved. */
    private function prepare(): void    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);
        $this->objective = trim($this->objective);
        $this->activityExpenditure = trim($this->activityExpenditure);
        $this->suggestedQuantity = trim($this->suggestedQuantity);
        $this->suggestedUnitCost = trim($this->suggestedUnitCost);
        $this->majorOutput = trim($this->majorOutput);
        $this->performanceIndicator = trim($this->performanceIndicator);
        $this->activityMovs = trim($this->activityMovs);
        $this->tags = trim($this->tags);
    }

    private function resetForm(): void
    {
        $this->name = '';
        $this->description = '';
        $this->lineType = WfpLineTemplate::LINE_TYPE_OPERATIONS;
        $this->objective = '';
        $this->activityExpenditure = '';
        $this->resourceKindId = null;
        $this->unitId = null;
        $this->suggestedQuantity = '';
        $this->accountClassificationId = null;
        $this->fundingSourceId = null;
        $this->orgClassificationId = null;
        $this->suggestedUnitCost = '';
        $this->majorOutput = '';
        $this->performanceIndicator = '';
        $this->activityMovs = '';
        $this->tags = '';
        $this->accountSearch = '';
        $this->formStatus = 'active';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'lineType' => ['required', Rule::in([WfpLineTemplate::LINE_TYPE_OPERATIONS, WfpLineTemplate::LINE_TYPE_PROJECT])],
            'objective' => ['nullable', 'string', 'max:2000'],
            'activityExpenditure' => ['required', 'string', 'max:2000'],
            'resourceKindId' => ['nullable', 'integer', Rule::exists('wfp_resource_kinds', 'id')],
            'unitId' => ['nullable', 'integer', Rule::exists('wfp_units', 'id')],
            'suggestedQuantity' => ['nullable', 'numeric', 'min:0'],
            'accountClassificationId' => ['nullable', 'integer', Rule::exists('account_classifications', 'id')],
            'fundingSourceId' => ['nullable', 'integer', Rule::exists('wfp_funding_sources', 'id')],
            'orgClassificationId' => ['nullable', 'integer', Rule::exists('wfp_org_classifications', 'id')],
            'suggestedUnitCost' => ['nullable', 'numeric', 'min:0'],
            'majorOutput' => ['nullable', 'string', 'max:2000'],
            'performanceIndicator' => ['nullable', 'string', 'max:2000'],
            'activityMovs' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'string', 'max:255'],
            'formStatus' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'template name',
            'activityExpenditure' => 'project/activity/expenditure',
            'resourceKindId' => 'resource kind',
            'unitId' => 'unit',
            'suggestedQuantity' => 'suggested quantity',
            'accountClassificationId' => 'account',
            'fundingSourceId' => 'funding source',
            'orgClassificationId' => 'organizational classification',
            'suggestedUnitCost' => 'suggested unit cost',
            'formStatus' => 'status',
        ];
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

        $template = WfpLineTemplate::query()
            ->with(['resourceKind', 'unit', 'accountClassification', 'fundingSource', 'orgClassification'])
            ->findOrFail($id);

        $this->resetForm();
        $this->resetValidation();
        $this->editingId = $template->id;
        $this->name = (string) $template->name;
        $this->description = (string) ($template->description ?? '');
        $this->lineType = (string) $template->line_type;
        $this->objective = (string) ($template->objective ?? '');
        $this->activityExpenditure = (string) $template->activity_expenditure;
        $this->resourceKindId = $template->resource_kind_id;
        $this->unitId = $template->unit_id;
        $this->suggestedQuantity = $template->suggested_quantity !== null ? (string) (float) $template->suggested_quantity : '';
        $this->accountClassificationId = $template->account_classification_id;
        $this->fundingSourceId = $template->funding_source_id;
        $this->orgClassificationId = $template->org_classification_id;
        $this->suggestedUnitCost = $template->suggested_unit_cost !== null ? (string) (float) $template->suggested_unit_cost : '';
        $this->majorOutput = (string) ($template->major_output ?? '');
        $this->performanceIndicator = (string) ($template->performance_indicator ?? '');
        $this->activityMovs = (string) ($template->activity_movs ?? '');
        $this->tags = (string) ($template->tags ?? '');
        $this->accountSearch = $template->accountClassification?->account_title ?? '';
        $this->formStatus = $template->is_active ? 'active' : 'inactive';
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->resetForm();
        $this->resetValidation();
    }

    /** Choose an account classification from the combobox. */
    public function chooseAccount(int $classificationId): void
    {
        $this->guard();

        $classification = AccountClassification::findOrFail($classificationId);

        $this->accountClassificationId = $classification->id;
        $this->accountSearch = (string) $classification->account_title;

        $this->resetValidation('lineForm.account_title');
    }

    /** Clear the line's account — the combobox's × button. */
    public function clearAccount(): void
    {
        $this->guard();

        $this->accountClassificationId = null;
        $this->accountSearch = '';

        $this->resetValidation('accountSearch');
    }

    /** The user edited the search box. Drop the link once it no longer matches the chosen title. */
    public function updatedAccountSearch(): void
    {
        if (trim($this->accountSearch) !== '') {
            $classification = AccountClassification::query()
                ->whereRaw('LOWER(TRIM(account_title)) = ?', [mb_strtolower($this->accountSearch)])
                ->first();

            if ($classification === null || $classification->id !== $this->accountClassificationId) {
                $this->accountClassificationId = null;
            }

            return;
        }

        $this->accountClassificationId = null;
    }

    public function save(): void
    {
        $this->guard();

        $this->prepare();
        $this->validate();

        $existing = WfpLineTemplate::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($this->name)])
            ->where('line_type', $this->lineType)
            ->when($this->editingId !== null, fn ($query) => $query->whereKeyNot($this->editingId))
            ->first();

        if ($existing !== null) {
            $this->addError(
                'name',
                'A template with this name and line type already exists.'
            );

            $this->showForm = true;

            return;
        }

        $user = Auth::user();
        $attributes = [
            'name' => $this->name,
            'description' => $this->description === '' ? null : $this->description,
            'line_type' => $this->lineType,
            'objective' => $this->objective === '' ? null : $this->objective,
            'activity_expenditure' => $this->activityExpenditure,
            'resource_kind_id' => $this->resourceKindId,
            'unit_id' => $this->unitId,
            'suggested_quantity' => $this->suggestedQuantity === '' ? null : (float) $this->suggestedQuantity,
            'account_classification_id' => $this->accountClassificationId,
            'funding_source_id' => $this->fundingSourceId,
            'org_classification_id' => $this->orgClassificationId,
            'suggested_unit_cost' => $this->suggestedUnitCost === '' ? null : (float) $this->suggestedUnitCost,
            'major_output' => $this->majorOutput === '' ? null : $this->majorOutput,
            'performance_indicator' => $this->performanceIndicator === '' ? null : $this->performanceIndicator,
            'activity_movs' => $this->activityMovs === '' ? null : $this->activityMovs,
            'tags' => $this->tags === '' ? null : $this->tags,
            'is_active' => $this->formStatus === 'active',
            'updated_by' => $user?->id,
        ];

        if ($this->editingId === null) {
            $attributes['created_by'] = $user?->id;
        }

        $inactive = $this->inactiveReferenceErrors();

        if ($inactive !== []) {
            foreach ($inactive as $field => $message) {
                $this->addError($field, $message);
            }

            $this->showForm = true;

            return;
        }

        if ($this->editingId === null) {
            $template = WfpLineTemplate::create($attributes);

            $template->recordLookupAudit(WfpLineTemplate::AUDIT_CREATE, $user, null, $template->auditSnapshot());

            $this->successMessage = 'Template created — “'.$template->name.'” was added.';
            $this->resetPage();
        } else {
            $template = WfpLineTemplate::findOrFail($this->editingId);
            $before = $template->auditSnapshot();

            $template->fill($attributes);
            $template->updated_by = $user?->id;
            $template->save();

            $template->recordLookupAudit(WfpLineTemplate::AUDIT_UPDATE, $user, $before, $template->auditSnapshot());

            $this->successMessage = 'Template updated — “'.$template->name.'” was saved.';
        }

        $this->closeForm();
    }

    /**
     * Reject references to retired records, unless the reference already
     * points at the same inactive record the template currently holds.
     *
     * @return array<string, string>
     */
    private function inactiveReferenceErrors(): array
    {
        $errors = [];

        $checks = [
            'resourceKindId' => ['WfpResourceKind', 'Resource Kind'],
            'unitId' => ['WfpUnit', 'Unit'],
            'fundingSourceId' => ['WfpFundingSource', 'Funding Source'],
            'orgClassificationId' => ['WfpOrgClassification', 'Organizational Classification'],
            'accountClassificationId' => ['AccountClassification', 'Account'],
        ];

        foreach ($checks as $field => $info) {
            [$model, $label] = $info;
            $id = $this->{$field} ?? null;

            if ($id === null) {
                continue;
            }

            $record = $model::query()->find($id);

            if ($record === null || $record->is_active) {
                continue;
            }

            $current = $this->editingId === null ? null : WfpLineTemplate::find($this->editingId)?->{$field};

            if ($current !== null && (int) $current === (int) $id) {
                continue; // unchanged reference to an already-retired record: keep it.
            }

            $errors[$field] = $label.' “'.$record->name.'” is inactive and cannot be attached to this template.';
        }

        return $errors;
    }

    public function openConfirmDelete(int $id): void
    {
        $this->guard();

        $template = WfpLineTemplate::findOrFail($id);

        $this->confirmId = $template->id;
        $this->confirmTitle = (string) $template->name;
        $this->showConfirm = true;
    }

    public function closeConfirmDelete(): void
    {
        $this->showConfirm = false;
        $this->confirmId = null;
        $this->confirmTitle = '';
    }

    /** Retire a template: `is_active = false` rather than a hard delete. */
    public function confirmDelete(): void
    {
        $this->guard();

        $template = WfpLineTemplate::findOrFail($this->confirmId);
        $before = $template->auditSnapshot();

        $template->is_active = false;
        $template->updated_by = Auth::id();
        $template->save();

        $template->recordLookupAudit(WfpLineTemplate::AUDIT_DEACTIVATE, Auth::user(), $before, $template->auditSnapshot());

        $name = $template->name;
        $this->closeConfirmDelete();
        $this->successMessage = 'Template deactivated — “'.$name.'” is hidden from new selections but stays readable on historical lines.';
    }

    /** Put a retired template back into circulation. */
    public function activate(int $id): void
    {
        $this->guard();

        $template = WfpLineTemplate::findOrFail($id);

        if ($template->is_active) {
            return;
        }

        $before = $template->auditSnapshot();

        $template->is_active = true;
        $template->updated_by = Auth::id();
        $template->save();

        $template->recordLookupAudit(WfpLineTemplate::AUDIT_ACTIVATE, Auth::user(), $before, $template->auditSnapshot());

        $this->successMessage = 'Template activated — “'.$template->name.'” is available again.';
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

        try {
            $analysis = WfpLineTemplateImporter::analyzeCsv($this->importFile->getRealPath(), $this->importMode);
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
            $analysis = WfpLineTemplateImporter::analyzeCsv($this->importFile->getRealPath(), $this->importMode);
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
    }

    /** Commit the import. The file is re-analyzed here — rows are never trusted from the client. */
    public function commitImport(): void
    {
        $this->guard();

        if ($this->importFile === null) {
            $this->importError = 'Choose a CSV file to import.';

            return;
        }

        try {
            $analysis = WfpLineTemplateImporter::analyzeCsv($this->importFile->getRealPath(), $this->importMode);
        } catch (RuntimeException $exception) {
            $this->importError = $exception->getMessage();

            return;
        }

        if ($analysis['validCount'] === 0 && $analysis['invalidCount'] === 0) {
            $this->importError = 'There are no valid rows to import.';

            return;
        }

        $report = WfpLineTemplateImporter::commit(
            $analysis,
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

    /** The error report CSV for the last committed import. */
    public function importErrorCsv(): string
    {
        $log = WfpImportLog::find($this->lastImportLogId);

        if ($log === null) {
            return '';
        }

        return WfpSetupExporter::errorReportCsv((array) ($log->errors ?? []));
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

    /** Match the app's own table look instead of Tailwind's. (Must be public: Livewire resolves it via __call.) */
    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    /** Options for the four lookup dropdowns, active rows only. */
    #[Computed]
    public function resourceKindOptions(): array
    {
        return WfpResourceKind::query()->active()->orderBy('name')->limit(50)->get()->all();
    }

    #[Computed]
    public function unitOptions(): array
    {
        return WfpUnit::query()->active()->orderBy('name')->limit(50)->get()->all();
    }

    #[Computed]
    public function fundingSourceOptions(): array
    {
        return WfpFundingSource::query()->active()->orderBy('name')->limit(50)->get()->all();
    }

    #[Computed]
    public function orgClassificationOptions(): array
    {
        return WfpOrgClassification::query()->active()->orderBy('name')->limit(50)->get()->all();
    }

    /** Account classifications matching the combobox search. */
    #[Computed]
    public function accountOptions(): array
    {
        $term = trim($this->accountSearch);

        return AccountClassification::query()
            ->active()
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(function ($query) use ($like): void {
                    $query->where('account_title', 'like', $like)
                        ->orWhere('uacs_object_code', 'like', $like)
                        ->orWhere('rca_code', 'like', $like);
                });
            })
            ->orderBy('account_title')
            ->limit(50)
            ->get()
            ->all();
    }

    #[Computed]
    public function rows()
    {
        return WfpLineTemplate::query()
            ->with(['resourceKind', 'unit', 'accountClassification', 'fundingSource', 'orgClassification'])
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('activity_expenditure', 'like', $term)
                        ->orWhere('tags', 'like', $term)
                        ->orWhere('major_output', 'like', $term)
                        ->orWhere('performance_indicator', 'like', $term)
                        ->orWhereHas('accountClassification', function ($query) use ($term): void {
                            $query->where('account_title', 'like', $term)
                                ->orWhere('uacs_object_code', 'like', $term);
                        });
                });
            })
            ->when($this->status === 'active', fn ($query) => $query->active())
            ->when($this->status === 'inactive', fn ($query) => $query->inactive())
            ->when($this->sort === 'name', fn ($query) => $query->orderBy('name')->orderBy('id'))
            ->when($this->sort === 'recent', fn ($query) => $query->orderByDesc('updated_at')->orderByDesc('id'))
            ->paginate($this->perPage);
    }

    public function render()
    {
        return view('livewire.wfp.line-templates-table', [
            'records' => $this->rows,
        ]);
    }
}
