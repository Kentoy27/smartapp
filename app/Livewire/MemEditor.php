<?php

namespace App\Livewire;

use App\Models\AccountClassification;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\User;
use App\Models\WfpPlan;
use App\Support\MemImporter;
use App\Support\WfpGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * The inline grid editor for one Monthly Expenditure Matrix — the app's first
 * array-bound form.
 *
 * The lines live in `public array $items`; each row carries a stable `_key` so
 * re-rendering never loses a cell. Wildcard rules are validated only in the
 * mutating actions (never on every keystroke), and line/monthly totals are
 * recomputed on every render (a #[Computed] `totals()`), so the grid always
 * shows the truth even before a save. The PERSISTED `calculated_total` is
 * recomputed server-side on save, never trusted from the browser.
 *
 * Saving is guarded by an optimistic lock: `loadedVersion` is compared to the
 * matrix's current version, and a save is refused with a "changed elsewhere"
 * message when they differ.
 */
#[Title('Monthly Expenditure Matrix — SmartApp')]
class MemEditor extends Component
{
    use WithFileUploads;

    /** How many analysed import rows are sent to the browser for the preview. */
    private const IMPORT_PREVIEW_LIMIT = 200;

    #[Locked]
    public int $matrixId = 0;

    #[Locked]
    public int $loadedVersion = 0;

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    /** @var array<int, int> */
    public array $removedItemIds = [];

    public string $matrixStatus = MonthlyExpenditureMatrix::STATUS_DRAFT;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    /* Add-line modal. */
    public bool $showLineModal = false;

    /** The section the modal will append to ('operations' | 'project'). */
    public string $lineSection = 'operations';

    /** The fields of one line being encoded in the modal. */
    public array $lineForm = [];

    /** The _key of the line being edited in the modal, or null when adding one. */
    public ?string $editingKey = null;

    /** The live query behind the account combobox (title or object code). */
    public string $accountSearch = '';

    /* CSV import. */
    public $importFile;

    public bool $showImport = false;

    /** @var array<int, array<string, mixed>> */
    public array $importRows = [];

    public int $importTotalRows = 0;

    public int $importValidCount = 0;

    public int $importInvalidCount = 0;

    public ?string $importError = null;

    public function mount(?MonthlyExpenditureMatrix $matrix = null): void
    {
        abort_unless($matrix !== null && $matrix->managedBy(Auth::user()), 404);

        $this->matrixId = $matrix->id;
        $this->loadedVersion = (int) $matrix->version;
        $this->matrixStatus = $matrix->status;
        $this->loadItems($matrix);
    }

    /* -------------------------------------------------------------- guards */

    private function guard(): MonthlyExpenditureMatrix
    {
        $matrix = MonthlyExpenditureMatrix::findOrFail($this->matrixId);
        abort_unless($matrix->managedBy(Auth::user()), 404);

        return $matrix;
    }

    private function loadItems(MonthlyExpenditureMatrix $matrix): void
    {
        $this->items = $matrix->items()
            ->get()
            ->map(fn (MonthlyExpenditureItem $item): array => self::rowFromModel($item))
            ->all();
        $this->removedItemIds = [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowFromModel(MonthlyExpenditureItem $item): array
    {
        return [
            '_key' => 'item-'.$item->id,
            'id' => $item->id,
            'section' => $item->section,
            'objective' => (string) ($item->objective ?? ''),
            'activity_description' => (string) $item->activity_description,
            'resource_kind' => (string) ($item->resource_kind ?? ''),
            'quantity' => $item->quantity !== null ? (string) (float) $item->quantity : '',
            'unit' => (string) ($item->unit ?? ''),
            'unit_cost' => $item->unit_cost !== null ? (string) (float) $item->unit_cost : '',
            'account_title' => (string) ($item->account_title ?? ''),
            'account_code' => (string) ($item->account_code ?? ''),
            'account_classification_id' => $item->account_classification_id,
            'funding_source' => (string) ($item->funding_source ?? ''),
            'organizational_classification' => (string) ($item->organizational_classification ?? ''),
            'activity_movs' => (string) ($item->activity_movs ?? ''),
        ];
    }

    private function blankRow(string $section): array
    {
        return [
            '_key' => 'new-'.bin2hex(random_bytes(8)),
            'id' => null,
            'section' => $section,
            'objective' => '',
            'activity_description' => '',
            'resource_kind' => '',
            'quantity' => '',
            'unit' => '',
            'unit_cost' => '',
            'account_title' => '',
            'account_code' => '',
            'account_classification_id' => null,
            'funding_source' => '',
            'organizational_classification' => '',
            'activity_movs' => '',
        ];
    }

    /* --------------------------------------------------------------- rules */

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'items' => ['array'],
            'items.*.section' => ['required', Rule::in(['operations', 'project'])],
            'items.*.activity_description' => ['required', 'string', 'max:2000'],
            'items.*.objective' => ['nullable', 'string', 'max:2000'],
            'items.*.resource_kind' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:64'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.account_title' => ['nullable', 'string', 'max:255'],
            'items.*.account_code' => ['nullable', 'string', 'max:64'],
            'items.*.funding_source' => ['nullable', 'string', 'max:255'],
            'items.*.organizational_classification' => ['nullable', 'string', 'max:255'],
            'items.*.activity_movs' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'items.*.section' => 'section',
            'items.*.activity_description' => 'activity description',
            'items.*.quantity' => 'quantity',
            'items.*.unit' => 'unit',
            'items.*.unit_cost' => 'unit cost',
            'items.*.account_title' => 'account title',
            'items.*.account_code' => 'account code',
        ];
    }

    /* -------------------------------------------------------------- editing */

    public function addRow(string $section = 'operations'): void
    {
        $this->guard();

        if (! in_array($section, ['operations', 'project'], true)) {
            $section = 'operations';
        }

        $this->items[] = $this->blankRow($section);
    }

    /* ---------------------------------------------------------- line modal */

    /**
     * Open the add-line modal for a section. Encoding a line happens here,
     * in one focused form, rather than as a half-empty row dropped into a
     * wide grid — the row appears once it is filled in.
     */
    public function openLineModal(string $section = 'operations'): void
    {
        $this->guard();

        $this->editingKey = null;
        $this->lineSection = in_array($section, ['operations', 'project'], true) ? $section : 'operations';
        $this->lineForm = $this->blankRow($this->lineSection);
        $this->accountSearch = '';
        $this->showLineModal = true;
        $this->resetValidation();
    }

    /**
     * Open the SAME modal pre-filled with an existing line. The grid is a
     * read-only list, so this is how a line is changed: the row's pencil
     * loads it into the one focused form.
     */
    public function openEditModal(string $key): void
    {
        $this->guard();

        foreach ($this->items as $row) {
            if (($row['_key'] ?? null) !== $key) {
                continue;
            }

            $this->editingKey = $key;
            $this->lineSection = in_array($row['section'] ?? '', ['operations', 'project'], true)
                ? $row['section']
                : 'operations';
            $this->lineForm = $row;
            $this->accountSearch = (string) ($row['account_title'] ?? '');
            $this->showLineModal = true;
            $this->resetValidation();

            return;
        }
    }

    public function closeLineModal(): void
    {
        $this->showLineModal = false;
        $this->editingKey = null;
        $this->lineForm = [];
        $this->accountSearch = '';
        $this->resetValidation();
    }

    /**
     * Validate the modal's line and either append it to the grid (add) or
     * write it back over the row it was opened for (edit). Either way the
     * change joins the others unsaved, so it still needs Save Draft — same
     * as a duplicated or imported line.
     */
    public function saveLineFromModal(): void
    {
        $this->guard();

        $this->lineForm['section'] = $this->lineSection;

        // The combobox doubles as a free-text title: when no classification was
        // chosen, whatever was typed becomes the line's account title.
        if (trim((string) ($this->lineForm['account_title'] ?? '')) === '' && trim($this->accountSearch) !== '') {
            $this->lineForm['account_title'] = $this->accountSearch;
        }

        $this->validate($this->lineRules(), $this->lineAttributes());

        if ($this->editingKey !== null) {
            foreach ($this->items as $index => $row) {
                if (($row['_key'] ?? null) !== $this->editingKey) {
                    continue;
                }

                // Keep the row's identity; the form only carries its fields.
                $updated = array_merge($row, $this->lineForm);
                $updated['_key'] = $row['_key'];
                $updated['id'] = $row['id'];

                $this->items[$index] = $updated;

                break;
            }

            $this->closeLineModal();

            return;
        }

        $row = array_merge($this->blankRow($this->lineSection), $this->lineForm);
        $row['_key'] = 'new-'.bin2hex(random_bytes(8));
        $row['id'] = null;

        $this->items[] = $row;

        $this->closeLineModal();
    }

    /**
     * The rules for the modal's single line — the grid's own rules with the
     * `items.*` prefix swapped for `lineForm`, so the two can never drift
     * apart, and a message reads "activity description" rather than an
     * index.
     *
     * @return array<string, array<int, mixed>>
     */
    private function lineRules(): array
    {
        $rules = [];

        foreach ($this->rules() as $key => $rule) {
            if (str_starts_with($key, 'items.*.')) {
                $rules['lineForm.'.substr($key, 8)] = $rule;
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function lineAttributes(): array
    {
        $attributes = [];

        foreach ($this->validationAttributes() as $key => $label) {
            if (str_starts_with($key, 'items.*.')) {
                $attributes['lineForm.'.substr($key, 8)] = $label;
            }
        }

        return $attributes;
    }

    public function removeRow(string $key): void
    {
        $this->guard();

        $this->items = array_values(array_filter(
            $this->items,
            function (array $row) use ($key): bool {
                if (($row['_key'] ?? null) !== $key) {
                    return true;
                }

                if (! empty($row['id'])) {
                    $this->removedItemIds[] = (int) $row['id'];
                }

                return false;
            }
        ));
    }

    /**
     * Copy a line into a fresh unsaved one right below itself — most lines
     * repeat an account, unit and cost, so the copy carries them and the SH
     * edits what differs.
     */
    public function duplicateRow(string $key): void
    {
        $this->guard();

        foreach ($this->items as $index => $row) {
            if (($row['_key'] ?? null) !== $key) {
                continue;
            }

            $copy = $row;
            $copy['_key'] = 'new-'.bin2hex(random_bytes(8));
            $copy['id'] = null;

            array_splice($this->items, $index + 1, 0, [$copy]);

            break;
        }
    }

    /**
     * Move a line to the other section (Operations ↔ Project). The grid
     * groups by section rather than carrying a per-row dropdown, so this is
     * how a line changes section — one click, no list to open.
     */
    public function moveRow(string $key): void
    {
        $this->guard();

        foreach ($this->items as $index => $row) {
            if (($row['_key'] ?? null) !== $key) {
                continue;
            }

            $this->items[$index]['section'] = ($row['section'] ?? 'operations') === 'project'
                ? 'operations'
                : 'project';

            break;
        }
    }

    /**
     * The lines of one section for the grid, each carrying its index in
     * `items` — the inputs bind to that original position, so grouping the
     * view by section never shifts what a cell writes to.
     *
     * @return array<int, array{index: int, row: array<string, mixed>}>
     */
    public function rowsForSection(string $section): array
    {
        $rows = [];

        foreach ($this->items as $index => $row) {
            if (($row['section'] ?? 'operations') === $section) {
                $rows[] = ['index' => $index, 'row' => $row];
            }
        }

        return $rows;
    }

    /* ---------------------------------------------------------------- total */

    /**
     * Monthly subtotals and the grand total, recomputed on every render from the
     * grid's current values (qty × unit cost). The persisted total is separate:
     * this is what the SH sees before saving.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function totals(): array
    {
        return [
            'operations' => round($this->sectionTotal('operations'), 2),
            'project' => round($this->sectionTotal('project'), 2),
            'grand' => round($this->sectionTotal('operations') + $this->sectionTotal('project'), 2),
        ];
    }

    private function sectionTotal(string $section): float
    {
        $total = 0.0;

        foreach ($this->items as $row) {
            if (($row['section'] ?? '') !== $section) {
                continue;
            }

            $total += self::lineTotal($row);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function lineTotal(array $row): float
    {
        $quantity = is_numeric($row['quantity'] ?? null) ? (float) $row['quantity'] : 0.0;
        $unitCost = is_numeric($row['unit_cost'] ?? null) ? (float) $row['unit_cost'] : 0.0;

        return round($quantity * $unitCost, 2);
    }

    /* ---------------------------------------------------------------- save */

    public function saveDraft(): void
    {
        if ($this->persist(MonthlyExpenditureMatrix::STATUS_DRAFT)) {
            $this->successMessage = 'Draft saved.';
        }
    }

    public function validateMatrix(): void
    {
        if ($this->persist(MonthlyExpenditureMatrix::STATUS_VALIDATED)) {
            $this->successMessage = 'Matrix validated.';
        }
    }

    public function finalizeMatrix(): void
    {
        if ($this->persist(MonthlyExpenditureMatrix::STATUS_FINALIZED)) {
            $this->successMessage = 'Matrix finalized.';
        }
    }

    /**
     * Write the grid to the database. Returns false (with an error message) only
     * when the optimistic lock fails; validation failures throw and are handled
     * by Livewire.
     */
    private function persist(string $status): bool
    {
        $matrix = $this->guard();

        // Drop rows the SH added but never filled, then validate what remains.
        $this->items = array_values(array_filter($this->items, fn (array $row): bool => ! self::isBlank($row)));

        $this->validate();

        // Optimistic lock: only save if nobody else has written this matrix.
        $currentVersion = (int) MonthlyExpenditureMatrix::whereKey($matrix->id)->value('version');

        if ($currentVersion !== $this->loadedVersion) {
            $this->errorMessage = 'This matrix changed elsewhere — reload the page before saving.';

            return false;
        }

        DB::transaction(function () use ($matrix, $status): void {
            $position = 0;
            $keptIds = [];

            foreach ($this->items as $index => $row) {
                $position++;

                $item = ! empty($row['id'])
                    ? MonthlyExpenditureItem::findOrFail((int) $row['id'])
                    : new MonthlyExpenditureItem(['matrix_id' => $matrix->id]);

                if ($item->exists && (int) $item->matrix_id !== (int) $matrix->id) {
                    continue; // never let a crafted call move a line across matrices
                }

                $item->fill([
                    'matrix_id' => $matrix->id,
                    'position' => $position,
                    'section' => (string) ($row['section'] ?? 'operations'),
                    'objective' => self::nullIfBlank($row['objective'] ?? null),
                    'activity_description' => (string) ($row['activity_description'] ?? ''),
                    'resource_kind' => self::nullIfBlank($row['resource_kind'] ?? null),
                    'quantity' => self::nullIfBlank($row['quantity'] ?? null) !== null ? (float) $row['quantity'] : null,
                    'unit' => self::nullIfBlank($row['unit'] ?? null),
                    'unit_cost' => self::nullIfBlank($row['unit_cost'] ?? null) !== null ? (float) $row['unit_cost'] : null,
                    'account_title' => self::nullIfBlank($row['account_title'] ?? null),
                    'account_code' => self::nullIfBlank($row['account_code'] ?? null),
                    'account_classification_id' => ! empty($row['account_classification_id']) ? (int) $row['account_classification_id'] : null,
                    'funding_source' => self::nullIfBlank($row['funding_source'] ?? null),
                    'organizational_classification' => self::nullIfBlank($row['organizational_classification'] ?? null),
                    'activity_movs' => self::nullIfBlank($row['activity_movs'] ?? null),
                ]);
                $item->syncTotal();
                $item->save();

                $this->items[$index]['id'] = $item->id;
                $this->items[$index]['_key'] = 'item-'.$item->id;
                $keptIds[] = $item->id;
            }

            $matrix->items()->whereNotIn('id', $keptIds ?: [0])->delete();

            $matrix->status = $status;
            $matrix->updated_by = Auth::id();
            $matrix->save();
            $matrix->bumpVersion();
        });

        $fresh = MonthlyExpenditureMatrix::findOrFail($matrix->id);
        $this->loadedVersion = (int) $fresh->version;
        $this->matrixStatus = $fresh->status;
        $this->loadItems($fresh);

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function isBlank(array $row): bool
    {
        foreach (['activity_description', 'objective', 'resource_kind', 'quantity', 'unit', 'unit_cost', 'account_title', 'account_code', 'funding_source', 'organizational_classification', 'activity_movs'] as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /* ------------------------------------------------------------ generate */

    public function generateWfp()
    {
        $matrix = $this->guard();

        // Save what is on screen first, so the plan is generated from it.
        if (! $this->persist($matrix->status)) {
            return null;
        }

        $matrix = $this->guard();

        if ($matrix->items()->count() === 0) {
            $this->errorMessage = 'Add at least one line before generating a WFP.';

            return null;
        }

        $user = $this->requireSchoolOwner($matrix);

        $plan = WfpPlan::firstOrCreate(
            ['school_id' => $matrix->school_id, 'year' => $matrix->year],
            ['status' => WfpPlan::STATUS_DRAFT],
        );

        $plan->generated_by = $user->id;
        $plan->save();

        WfpGenerator::generate($plan, $matrix);

        return $this->redirect(route('wfp.plans.edit', ['plan' => $plan->id]), navigate: true);
    }

    private function requireSchoolOwner(MonthlyExpenditureMatrix $matrix): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $matrix->managedBy($user) && $user->school_id !== null, 404);

        return $user;
    }

    /* ------------------------------------------------------ account combobox */

    /**
     * Pick an account from the combobox into the line being encoded. The
     * title, object code and the classification link are all set, and the
     * search box is left showing the chosen title.
     */
    public function chooseAccount(int $classificationId): void
    {
        $this->guard();

        $classification = AccountClassification::findOrFail($classificationId);

        $this->lineForm['account_title'] = (string) $classification->account_title;
        $this->lineForm['account_code'] = (string) $classification->uacs_object_code;
        $this->lineForm['account_classification_id'] = $classification->id;
        $this->accountSearch = (string) $classification->account_title;

        $this->resetValidation('lineForm.account_title');
        $this->resetValidation('lineForm.account_code');
    }

    /** Clear the line's account — the combobox's × button. */
    public function clearAccount(): void
    {
        $this->guard();

        $this->lineForm['account_title'] = '';
        $this->lineForm['account_code'] = '';
        $this->lineForm['account_classification_id'] = null;
        $this->accountSearch = '';

        $this->resetValidation('lineForm.account_title');
        $this->resetValidation('lineForm.account_code');
    }

    /**
     * The user edited the search box. Once it no longer reads as the chosen
     * title the line is no longer bound to a specific classification, so drop
     * the link — a stale id must not be saved under a hand-typed title.
     */
    public function updatedAccountSearch(): void
    {
        if (trim($this->accountSearch) !== trim((string) ($this->lineForm['account_title'] ?? ''))) {
            $this->lineForm['account_classification_id'] = null;
        }
    }

    /**
     * @return array<int, AccountClassification>
     */
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
        $matrix = $this->guard();

        $this->resetImportState();

        $maxKb = (int) config('mem.import.max_size_kb', 5120);

        $this->validate([
            'importFile' => ['required', 'file', 'max:'.$maxKb, 'extensions:'.(string) config('mem.import.extensions', 'csv,txt')],
        ], [
            'importFile.required' => 'Choose a CSV file to import.',
            'importFile.extensions' => 'The import must be a .csv file.',
            'importFile.max' => 'The file is too large ('.round($maxKb / 1024, 1).' MB maximum).',
        ]);

        try {
            $analysis = MemImporter::analyzeCsv($this->importFile->getRealPath(), $matrix);
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

    public function commitImport(): void
    {
        $matrix = $this->guard();

        if ($this->importFile === null) {
            $this->importError = 'Choose a CSV file to import.';

            return;
        }

        try {
            $analysis = MemImporter::analyzeCsv($this->importFile->getRealPath(), $matrix);
        } catch (RuntimeException $exception) {
            $this->importError = $exception->getMessage();

            return;
        }

        if ($analysis['validCount'] === 0) {
            $this->importError = 'There are no valid rows to import.';

            return;
        }

        $report = MemImporter::commit($analysis['valid'], $matrix, Auth::user());

        $this->showImport = false;
        $this->resetImport();

        $fresh = MonthlyExpenditureMatrix::findOrFail($matrix->id);
        $this->loadedVersion = (int) $fresh->version;
        $this->loadItems($fresh);

        $this->successMessage = "Import complete — {$report['imported']} line(s) added.";
    }

    private function resetImport(): void
    {
        $this->importFile = null;
        $this->resetImportState();
    }

    private function resetImportState(): void
    {
        $this->importRows = [];
        $this->importTotalRows = 0;
        $this->importValidCount = 0;
        $this->importInvalidCount = 0;
        $this->importError = null;
        $this->resetValidation('importFile');
    }

    /* --------------------------------------------------------------- misc */

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    #[Computed]
    public function matrix(): MonthlyExpenditureMatrix
    {
        return MonthlyExpenditureMatrix::findOrFail($this->matrixId);
    }

    /**
     * The twelve months of this matrix's planning year for the SH's own school,
     * in calendar order — the month tab strip at the top of the editor. The
     * month being edited is flagged (`current`); a month whose matrix is missing
     * (deleted) carries no id and renders inactive. One query for the whole
     * year, so drawing the strip costs nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function months(): array
    {
        $matrix = $this->matrix;

        $siblings = MonthlyExpenditureMatrix::query()
            ->where('school_id', $matrix->school_id)
            ->where('year', $matrix->year)
            ->withCount('items')
            ->get()
            ->keyBy('month');

        $months = [];

        foreach (MonthlyExpenditureMatrix::MONTH_ABBREVIATIONS as $number => $abbreviation) {
            $sibling = $siblings->get($number);

            $months[] = [
                'month' => $number,
                'abbr' => strtoupper($abbreviation),
                'label' => $sibling instanceof MonthlyExpenditureMatrix
                    ? $sibling->periodLabel()
                    : $abbreviation.' '.$matrix->year,
                'id' => $sibling instanceof MonthlyExpenditureMatrix ? (int) $sibling->id : null,
                'status' => $sibling instanceof MonthlyExpenditureMatrix ? $sibling->status : null,
                'current' => $number === (int) $matrix->month,
            ];
        }

        return $months;
    }

    public function render()
    {
        return view('livewire.mem.editor', [
            'matrix' => $this->matrix,
        ]);
    }
}
