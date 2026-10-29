<?php

namespace App\Livewire;

use App\Models\AccountClassification;
use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\User;
use App\Models\WfpFundingSource;
use App\Models\WfpLineTemplate;
use App\Models\WfpOrgClassification;
use App\Models\WfpPlan;
use App\Models\WfpReportCheck;
use App\Models\WfpResourceKind;
use App\Models\WfpUnit;
use App\Support\MemImporter;
use App\Support\WfpAllotment;
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

    /* Template picker for the Add a Line form. */

    /** Search term for the template picker (name / activity / account). */
    public string $templateSearch = '';

    /**
     * The template last applied to the line being encoded, or null. Locked:
     * only chooseTemplate()/applyTemplate() may set it, so a crafted request
     * cannot claim a line was filled from a template it never applied.
     */
    #[Locked]
    public ?int $appliedTemplateId = null;

    #[Locked]
    public string $appliedTemplateName = '';

    /**
     * The values the last applied template contributed, keyed by lineForm
     * field — used to decide which fields are still "template-owned" and
     * therefore replaceable when a new template is chosen.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $appliedTemplateSnapshot = [];

    /** A template chosen while the user has edits: awaits replace or keep. */
    public ?int $pendingTemplateId = null;

    /**
     * Per-request memo for the master records normalisation looks up, so
     * saving a matrix with many lines does not re-query the same account or
     * lookup row once per line.
     *
     * @var array<string, mixed>
     */
    private array $normalisationCache = [];

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
        // The page OPENS for a read-only reader; every write below goes
        // through guard(), which keeps the SH-only managedBy() gate.
        abort_unless($matrix !== null && $matrix->canBeViewedBy(Auth::user()), 404);

        $this->matrixId = $matrix->id;
        $this->loadedVersion = (int) $matrix->version;
        $this->loadItems($matrix);
    }

    /* -------------------------------------------------------------- guards */

    private function guard(): MonthlyExpenditureMatrix
    {
        $matrix = MonthlyExpenditureMatrix::findOrFail($this->matrixId);
        abort_unless($matrix->managedBy(Auth::user()), 404);

        return $matrix;
    }

    /**
     * Whether the signed-in account may edit this matrix. The Blade hides
     * every write control for a read-only reader; each action re-checks the
     * same gate server-side (see guard()).
     */
    public function canEdit(): bool
    {
        return Auth::user()?->canManageWfp() === true;
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
            'major_output' => (string) ($item->major_output ?? ''),
            'performance_indicator' => (string) ($item->performance_indicator ?? ''),
            'wfp_line_template_id' => $item->wfp_line_template_id,
            'resource_kind_id' => $item->resource_kind_id,
            'unit_id' => $item->unit_id,
            'funding_source_id' => $item->funding_source_id,
            'org_classification_id' => $item->org_classification_id,
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
            'major_output' => '',
            'performance_indicator' => '',
            'wfp_line_template_id' => null,
            'resource_kind_id' => null,
            'unit_id' => null,
            'funding_source_id' => null,
            'org_classification_id' => null,
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
            'items.*.major_output' => ['nullable', 'string', 'max:2000'],
            'items.*.performance_indicator' => ['nullable', 'string', 'max:2000'],
            'items.*.wfp_line_template_id' => ['nullable', 'integer'],
            'items.*.resource_kind_id' => ['nullable', 'integer'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.funding_source_id' => ['nullable', 'integer'],
            'items.*.org_classification_id' => ['nullable', 'integer'],
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
            'items.*.major_output' => 'major output',
            'items.*.performance_indicator' => 'performance indicator',
            'items.*.activity_movs' => 'activity MOVs',
            'items.*.resource_kind' => 'resource kind',
            'items.*.funding_source' => 'funding source',
            'items.*.organizational_classification' => 'organizational classification',
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

        $this->persist();
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
        $this->templateSearch = '';
        $this->appliedTemplateId = null;
        $this->appliedTemplateName = '';
        $this->appliedTemplateSnapshot = [];
        $this->pendingTemplateId = null;
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
            $this->templateSearch = '';

            // If this line was encoded from a template, remember it so the
            // form can show "filled from template" and guard edits on switch.
            // A retired template is loaded too: it is provenance for this
            // saved line, and editing the line must not quietly erase it.
            if (! empty($row['wfp_line_template_id'] ?? null)) {
                $template = WfpLineTemplate::find((int) $row['wfp_line_template_id']);

                if ($template !== null) {
                    $this->appliedTemplateId = $template->id;
                    $this->appliedTemplateName = (string) $template->name;
                    $this->appliedTemplateSnapshot = array_intersect_key($row, array_flip([
                        'objective', 'activity_description', 'resource_kind', 'unit',
                        'quantity', 'unit_cost', 'account_title', 'account_code',
                        'account_classification_id', 'funding_source',
                        'organizational_classification', 'major_output',
                        'performance_indicator', 'activity_movs',
                        'resource_kind_id', 'unit_id', 'funding_source_id',
                        'org_classification_id',
                    ]));
                }
            } else {
                $this->appliedTemplateId = null;
                $this->appliedTemplateName = '';
                $this->appliedTemplateSnapshot = [];
            }

            $this->pendingTemplateId = null;
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
        $this->templateSearch = '';
        $this->appliedTemplateId = null;
        $this->appliedTemplateName = '';
        $this->appliedTemplateSnapshot = [];
        $this->pendingTemplateId = null;
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
        $this->lineForm['wfp_line_template_id'] = $this->appliedTemplateId;

        // The combobox doubles as a free-text title: when no classification was
        // chosen, whatever was typed becomes the line's account title.
        if (trim((string) ($this->lineForm['account_title'] ?? '')) === '' && trim($this->accountSearch) !== '') {
            $this->lineForm['account_title'] = $this->accountSearch;
        }

        $existing = ! empty($this->lineForm['id'])
            ? MonthlyExpenditureItem::query()
                ->where('matrix_id', $this->matrixId)
                ->find((int) $this->lineForm['id'])
            : null;

        // Account title/code and lookup links are derived on the server, never
        // trusted from the browser: a crafted request cannot pair one account
        // title with another account's code.
        $this->lineForm = $this->normaliseRow($this->lineForm, $existing);

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
        } else {
            $row = array_merge($this->blankRow($this->lineSection), $this->lineForm);
            $row['_key'] = 'new-'.bin2hex(random_bytes(8));
            $row['id'] = null;

            $this->items[] = $row;

            $this->closeLineModal();
        }

        $this->persist();
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

        $this->persist();
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

        $this->persist();
    }

    /**
     * Put a line at a new position inside its own section, after the SH
     * drags it. The list on screen is one section, so `position` is the
     * row's zero-based index among that section's lines; the section is
     * derived from the line itself rather than trusted from the browser.
     * Only that section's slots move — the other section keeps its rows,
     * and their places, untouched.
     *
     * The new order is written straight away (the same write Save Draft
     * uses), because a sorted list that is lost the moment the SH opens
     * another month is not sorted at all.
     */
    public function reorderRow(string $key, int $position): void
    {
        $this->guard();

        $section = null;
        foreach ($this->items as $row) {
            if (($row['_key'] ?? null) === $key) {
                $section = (string) ($row['section'] ?? 'operations');
                break;
            }
        }

        if ($section === null) {
            return; // a crafted or stale key: nothing to move
        }

        $slots = [];
        $rows = [];

        foreach ($this->items as $index => $row) {
            if ((string) ($row['section'] ?? 'operations') === $section) {
                $slots[] = $index;
                $rows[] = $row;
            }
        }

        $from = null;
        foreach ($rows as $index => $row) {
            if (($row['_key'] ?? null) === $key) {
                $from = $index;
                break;
            }
        }

        if ($from === null) {
            return;
        }

        $position = max(0, min($position, count($rows) - 1));

        if ($position === $from) {
            return;
        }

        $moved = array_splice($rows, $from, 1);
        array_splice($rows, $position, 0, $moved);

        foreach ($slots as $slot => $index) {
            $this->items[$index] = $rows[$slot];
        }

        $this->persist();
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

        $this->persist();
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

    /**
     * Write the grid to the database. Returns false (with an error message) only
     * when the optimistic lock fails; validation failures throw and are handled
     * by Livewire.
     */
    private function persist(): bool
    {
        $matrix = $this->guard();

        // Drop rows the SH added but never filled, then validate what remains.
        $this->items = array_values(array_filter($this->items, fn (array $row): bool => ! self::isBlank($row)));

        // Re-derive every linked account and lookup from its id (or match a
        // typed name to an active lookup) before validating. The stored line
        // therefore always carries the master record's wording, even if the
        // request body was edited.
        foreach ($this->items as $index => $row) {
            $existing = ! empty($row['id'])
                ? MonthlyExpenditureItem::query()
                    ->where('matrix_id', $matrix->id)
                    ->find((int) $row['id'])
                : null;

            $this->items[$index] = $this->normaliseRow($row, $existing);
        }

        $this->validate();

        // Optimistic lock: only save if nobody else has written this matrix.
        $currentVersion = (int) MonthlyExpenditureMatrix::whereKey($matrix->id)->value('version');

        if ($currentVersion !== $this->loadedVersion) {
            $this->errorMessage = 'This matrix changed elsewhere — reload the page before saving.';

            return false;
        }

        // Whether the report's check has anything to clear: the lines are
        // about to be rewritten, so a check that covered them no longer
        // describes what the report will show.
        $checkExisted = WfpReportCheck::query()
            ->where('school_id', $matrix->school_id)
            ->where('year', $matrix->year)
            ->exists();

        DB::transaction(function () use ($matrix): void {
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
                    'major_output' => self::nullIfBlank($row['major_output'] ?? null),
                    'performance_indicator' => self::nullIfBlank($row['performance_indicator'] ?? null),
                    'wfp_line_template_id' => ! empty($row['wfp_line_template_id']) ? (int) $row['wfp_line_template_id'] : null,
                    'resource_kind_id' => ! empty($row['resource_kind_id']) ? (int) $row['resource_kind_id'] : null,
                    'unit_id' => ! empty($row['unit_id']) ? (int) $row['unit_id'] : null,
                    'funding_source_id' => ! empty($row['funding_source_id']) ? (int) $row['funding_source_id'] : null,
                    'org_classification_id' => ! empty($row['org_classification_id']) ? (int) $row['org_classification_id'] : null,
                ]);
                $item->syncTotal();
                $item->save();

                $this->items[$index]['id'] = $item->id;
                $this->items[$index]['_key'] = 'item-'.$item->id;
                $keptIds[] = $item->id;
            }

            $matrix->items()->whereNotIn('id', $keptIds ?: [0])->delete();

            $matrix->updated_by = Auth::id();
            $matrix->save();
            $matrix->bumpVersion();
        });

        // The year's lines changed, so the Bookkeeper's check of the account
        // titles and codes no longer stands for what the report shows; it is
        // cleared and must be given again (see WfpReportCheck).
        WfpReportCheck::clearFor((int) $matrix->school_id, (int) $matrix->year);

        $fresh = MonthlyExpenditureMatrix::findOrFail($matrix->id);
        $this->loadedVersion = (int) $fresh->version;
        $this->loadItems($fresh);

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function isBlank(array $row): bool
    {
        foreach (['activity_description', 'objective', 'resource_kind', 'quantity', 'unit', 'unit_cost', 'account_title', 'account_code', 'funding_source', 'organizational_classification', 'activity_movs', 'major_output', 'performance_indicator'] as $field) {
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

    /**
     * Re-derive a row's linked master values on the server:
     *
     * - a chosen account classification owns both the title and the code;
     * - a chosen lookup id owns the matching text;
     * - a typed lookup name is matched to one active lookup so it gains a link;
     * - a retired record may stay attached to the line it was already on, but
     *   can never be newly attached (or claimed from a crafted request).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row, ?MonthlyExpenditureItem $existing): array
    {
        $row = $this->normaliseAccount($row, $existing);
        $row = $this->normaliseLookup(
            $row,
            'resource_kind',
            'resource_kind_id',
            WfpResourceKind::class,
            $existing?->resource_kind_id,
        );
        $row = $this->normaliseLookup(
            $row,
            'unit',
            'unit_id',
            WfpUnit::class,
            $existing?->unit_id,
        );
        $row = $this->normaliseLookup(
            $row,
            'funding_source',
            'funding_source_id',
            WfpFundingSource::class,
            $existing?->funding_source_id,
        );
        $row = $this->normaliseLookup(
            $row,
            'organizational_classification',
            'org_classification_id',
            WfpOrgClassification::class,
            $existing?->org_classification_id,
        );

        // The template is provenance only. It may be retained on the line it
        // already belongs to after retirement, but a new line can never be
        // stamped with an inactive (or invented) template id.
        $templateId = ! empty($row['wfp_line_template_id']) ? (int) $row['wfp_line_template_id'] : null;

        if ($templateId !== null) {
            $template = WfpLineTemplate::find($templateId);
            $alreadyOnLine = $existing !== null
                && (int) $existing->wfp_line_template_id === $templateId;

            if ($template === null || (! $template->is_active && ! $alreadyOnLine)) {
                $templateId = null;
            }
        }

        $row['wfp_line_template_id'] = $templateId;

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normaliseAccount(array $row, ?MonthlyExpenditureItem $existing): array
    {
        $id = ! empty($row['account_classification_id']) ? (int) $row['account_classification_id'] : null;
        $title = trim((string) ($row['account_title'] ?? ''));
        $code = trim((string) ($row['account_code'] ?? ''));

        if ($id !== null) {
            $classification = $this->findAccount($id);

            if ($classification === null) {
                $id = null;
            } elseif ($classification->is_active) {
                // One authority for title and code: they cannot drift apart.
                $title = (string) $classification->account_title;
                $code = (string) $classification->uacs_object_code;
            } elseif ((int) $existing?->account_classification_id !== $id) {
                $id = null; // a retired account cannot be newly attached
            }
            // An unchanged retired link keeps the line's own saved wording.
        }

        if ($id === null && $title !== '') {
            $classification = $this->findAccountByTitle($title);

            if ($classification !== null) {
                $id = (int) $classification->getKey();
                $title = (string) $classification->account_title;
                $code = (string) $classification->uacs_object_code;
            }
        }

        $row['account_classification_id'] = $id;
        $row['account_title'] = $title;
        $row['account_code'] = $code;

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  class-string  $modelClass
     * @return array<string, mixed>
     */
    private function normaliseLookup(
        array $row,
        string $nameField,
        string $idField,
        string $modelClass,
        ?int $existingId,
    ): array {
        $id = ! empty($row[$idField]) ? (int) $row[$idField] : null;
        $name = trim((string) ($row[$nameField] ?? ''));

        if ($id !== null) {
            $record = $this->findLookup($modelClass, $id);

            if ($record === null) {
                $id = null;
            } elseif ($record->is_active) {
                $name = (string) $record->name;
            } elseif ($existingId !== $id) {
                $id = null; // a retired lookup cannot be newly attached
            }
            // An unchanged retired link keeps the line's own saved wording.
        }

        if ($id === null && $name !== '') {
            $record = $this->findLookupByName($modelClass, $name);

            if ($record !== null) {
                $id = (int) $record->getKey();
                $name = (string) $record->name;
            }
        }

        $row[$nameField] = $name;
        $row[$idField] = $id;

        return $row;
    }

    private function findAccount(int $id): ?AccountClassification
    {
        $key = 'account#'.$id;

        if (! array_key_exists($key, $this->normalisationCache)) {
            $this->normalisationCache[$key] = AccountClassification::find($id);
        }

        return $this->normalisationCache[$key];
    }

    private function findAccountByTitle(string $title): ?AccountClassification
    {
        $key = 'account-title@'.mb_strtolower($title);

        if (! array_key_exists($key, $this->normalisationCache)) {
            $this->normalisationCache[$key] = AccountClassification::query()
                ->active()
                ->whereRaw('LOWER(TRIM(account_title)) = ?', [mb_strtolower($title)])
                ->orderBy('id')
                ->first();
        }

        return $this->normalisationCache[$key];
    }

    /**
     * @param  class-string  $modelClass
     */
    private function findLookup(string $modelClass, int $id): mixed
    {
        $key = $modelClass.'#'.$id;

        if (! array_key_exists($key, $this->normalisationCache)) {
            $this->normalisationCache[$key] = $modelClass::query()->find($id);
        }

        return $this->normalisationCache[$key];
    }

    /**
     * @param  class-string  $modelClass
     */
    private function findLookupByName(string $modelClass, string $name): mixed
    {
        $key = $modelClass.'@'.mb_strtolower($name);

        if (! array_key_exists($key, $this->normalisationCache)) {
            $this->normalisationCache[$key] = $modelClass::resolveActive($name);
        }

        return $this->normalisationCache[$key];
    }

    /* ------------------------------------------------------------ generate */

    public function generateWfp()
    {
        $matrix = $this->guard();

        // Save what is on screen first, so the plan is generated from it.
        if (! $this->persist()) {
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

        // New selections may only come from the active master list; the line's
        // existing (possibly retired) link is preserved by normaliseAccount().
        abort_unless($classification->is_active, 404);

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

    /* ------------------------------------------------------------ templates */

    /**
     * Choose a template for the line being encoded. If the user already has
     * unsaved edits, ask whether to replace or keep them; otherwise apply it
     * straight away.
     */
    public function chooseTemplate(int $id): void
    {
        $this->guard();

        $template = WfpLineTemplate::query()->active()->findOrFail($id);

        // The picker only lists templates for the section being encoded; a
        // crafted call cannot pull a Project template into an Operations line.
        if ($template->line_type !== $this->lineSection) {
            $this->addError(
                'templateSearch',
                'That template belongs to the other expenditure type. Switch the section first.'
            );

            return;
        }

        if ($this->hasTemplateEdits()) {
            $this->pendingTemplateId = $id;

            return;
        }

        $this->applyTemplate($id, 'fill');
    }

    /**
     * The section changed: a template applied to the old section no longer
     * describes the line. Keep the values already on screen, but drop the
     * template link so the line is not saved as if it came from that template.
     */
    public function updatedLineSection(): void
    {
        $this->pendingTemplateId = null;

        if ($this->appliedTemplateId === null) {
            return;
        }

        $template = WfpLineTemplate::find($this->appliedTemplateId);

        if ($template !== null && $template->line_type === $this->lineSection) {
            return;
        }

        $this->appliedTemplateId = null;
        $this->appliedTemplateName = '';
        $this->appliedTemplateSnapshot = [];
    }

    /** Replace every template-owned field with the chosen template's values. */
    public function confirmTemplateReplace(): void
    {
        $this->guard();

        if ($this->pendingTemplateId === null) {
            return;
        }

        $this->applyTemplate($this->pendingTemplateId, 'replace');
        $this->pendingTemplateId = null;
    }

    /** Keep the user's edits; only refill fields still owned by the template. */
    public function keepTemplateEdits(): void
    {
        $this->guard();

        if ($this->pendingTemplateId === null) {
            return;
        }

        $this->applyTemplate($this->pendingTemplateId, 'fill');
        $this->pendingTemplateId = null;
    }

    /** Drop the pending template choice without applying anything. */
    public function dismissTemplate(): void
    {
        $this->pendingTemplateId = null;
    }

    /** Unlink the template from the line being encoded, keeping its values. */
    public function clearTemplate(): void
    {
        $this->guard();

        $this->appliedTemplateId = null;
        $this->appliedTemplateName = '';
        $this->appliedTemplateSnapshot = [];
    }

    /**
     * Whether the line's template-owned fields differ from the snapshot of
     * the last applied template — i.e. the user has edited them.
     *
     * @return bool
     */
    public function hasTemplateEdits(): bool
    {
        if ($this->appliedTemplateId === null || $this->appliedTemplateSnapshot === []) {
            return false;
        }

        foreach ($this->appliedTemplateSnapshot as $field => $value) {
            if ((string) ($this->lineForm[$field] ?? '') !== (string) $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply one template to the line form. `fill` keeps user-edited values;
     * `replace` overwrites every field the template maps.
     */
    private function applyTemplate(int $id, string $mode): void
    {
        $template = WfpLineTemplate::query()
            ->with(['resourceKind', 'unit', 'accountClassification', 'fundingSource', 'orgClassification'])
            ->active()
            ->findOrFail($id);

        // The picker is filtered to the current section; keep the property in
        // step for an edit opened from an older line whose section differed.
        $this->lineSection = $template->line_type;

        $snapshot = [];

        // Each mapped field: template value → lineForm field. Lookup links are
        // copied only when the referenced record is still active — an active
        // template that points at a since-retired lookup shows its wording but
        // does not re-attach the retired row to a new line.
        $resourceKind = $template->resourceKind;
        $unit = $template->unit;
        $fundingSource = $template->fundingSource;
        $orgClassification = $template->orgClassification;

        $mapping = [
            'objective' => $template->objective ?? '',
            'activity_description' => $template->activity_expenditure,
            'resource_kind' => $resourceKind?->name ?? '',
            'unit' => $unit?->name ?? '',
            'quantity' => $template->suggested_quantity !== null ? (string) (float) $template->suggested_quantity : '',
            'unit_cost' => $template->suggested_unit_cost !== null ? (string) (float) $template->suggested_unit_cost : '',
            'account_title' => $template->accountClassification?->account_title ?? '',
            'account_code' => $template->accountClassification?->uacs_object_code ?? '',
            'account_classification_id' => $template->accountClassification?->is_active ? $template->account_classification_id : null,
            'funding_source' => $fundingSource?->name ?? '',
            'organizational_classification' => $orgClassification?->name ?? '',
            'major_output' => $template->major_output ?? '',
            'performance_indicator' => $template->performance_indicator ?? '',
            'activity_movs' => $template->activity_movs ?? '',
            'resource_kind_id' => $resourceKind?->is_active ? $template->resource_kind_id : null,
            'unit_id' => $unit?->is_active ? $template->unit_id : null,
            'funding_source_id' => $fundingSource?->is_active ? $template->funding_source_id : null,
            'org_classification_id' => $orgClassification?->is_active ? $template->org_classification_id : null,
        ];

        foreach ($mapping as $field => $value) {
            $snapshot[$field] = $value;

            if ($mode === 'replace') {
                $this->lineForm[$field] = $value;
                continue;
            }

            // fill: only take the template value when the field is empty or
            // still holds the previous template's value (template-owned).
            $current = (string) ($this->lineForm[$field] ?? '');
            $previous = (string) ($this->appliedTemplateSnapshot[$field] ?? '');

            if ($current === '' || $current === $previous) {
                $this->lineForm[$field] = $value;
            }
        }

        // Wire the account combobox to show the chosen account's wording.
        $accountTitle = (string) ($template->accountClassification?->account_title ?? '');

        if ($accountTitle !== '') {
            $this->accountSearch = $accountTitle;
        }

        $this->appliedTemplateId = $template->id;
        $this->appliedTemplateName = (string) $template->name;
        $this->appliedTemplateSnapshot = $snapshot;

        foreach (array_keys($mapping) as $field) {
            $this->resetValidation('lineForm.'.$field);
        }
    }

    /** @return array<int, WfpLineTemplate> */
    #[Computed]
    public function templateOptions(): array
    {
        $term = trim($this->templateSearch);

        return WfpLineTemplate::query()
            ->with(['resourceKind', 'unit', 'accountClassification', 'fundingSource', 'orgClassification'])
            ->active()
            // The line type is chosen first: only templates for that section
            // are offered, so a Project template can never fill an Operations
            // line (or vice versa).
            ->where('line_type', $this->lineSection)
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('activity_expenditure', 'like', $like)
                        ->orWhere('objective', 'like', $like)
                        ->orWhere('tags', 'like', $like)
                        ->orWhereHas('accountClassification', function ($query) use ($like): void {
                            $query->where('account_title', 'like', $like)
                                ->orWhere('uacs_object_code', 'like', $like);
                        });
                });
            })
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->all();
    }

    /**
     * Whether a line field still holds the value the applied template put
     * there — the view tags those fields as auto-filled. Once the user edits
     * one (and the form re-renders), its tag disappears.
     */
    public function templateFieldIsFilled(string $field): bool
    {
        if ($this->appliedTemplateId === null || $this->appliedTemplateSnapshot === []) {
            return false;
        }

        $value = trim((string) ($this->lineForm[$field] ?? ''));
        $templateValue = trim((string) ($this->appliedTemplateSnapshot[$field] ?? ''));

        return $value !== '' && $value === $templateValue;
    }

    /**
     * The administrator-managed lookup lists the variable fields draw from.
     *
     * @return array<int, WfpResourceKind>
     */
    #[Computed]
    public function resourceKindOptions(): array
    {
        return WfpResourceKind::query()->active()->orderBy('name')->limit(50)->get()->all();
    }

    /** @return array<int, WfpUnit> */
    #[Computed]
    public function unitOptions(): array
    {
        return WfpUnit::query()->active()->orderBy('name')->limit(50)->get()->all();
    }

    /** @return array<int, WfpFundingSource> */
    #[Computed]
    public function fundingSourceOptions(): array
    {
        return WfpFundingSource::query()->active()->orderBy('name')->limit(50)->get()->all();
    }

    /** @return array<int, WfpOrgClassification> */
    #[Computed]
    public function orgClassificationOptions(): array
    {
        return WfpOrgClassification::query()->active()->orderBy('name')->limit(50)->get()->all();
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
     * The allotment budget the Budget office set for this matrix's school
     * and year, or null when none has been set. One query per render; the
     * matrix page and its month tabs read this same figure.
     */
    #[Computed]
    public function allotted(): ?float
    {
        $matrix = $this->matrix;

        return WfpAllotment::allotment((int) $matrix->school_id, (int) $matrix->year);
    }

    /**
     * The year's allotment read against the year's consumption: the running
     * balance the School Head needs while encoding a month. The month being
     * edited contributes its unsaved grid total, so adding or removing a line
     * moves the balance immediately; the other months read their persisted
     * totals. The shape is the one WfpLineItemAllotment shows, computed by
     * the same helper.
     *
     * @return array{allotted: ?float, consumed: float, remaining: float, percent: float, is_over: bool}
     */
    #[Computed]
    public function allocation(): array
    {
        $matrix = $this->matrix;

        $consumed = 0.0;

        foreach ($this->months as $month) {
            $consumed += (float) ($month['total'] ?? 0.0);
        }

        return WfpAllotment::balance($this->allotted, $consumed);
    }

    /**
     * The twelve months of this matrix's planning year for the SH's own school,
     * in calendar order — the month tab strip at the top of the editor. The
     * month being edited is flagged (`current`); a month whose matrix is missing
     * (deleted) carries no id and renders inactive. Each tab also carries that
     * month's total: the month being edited reads the unsaved grid (so it agrees
     * with the summary strip), the others read their persisted lines. A month
     * with no lines has no total (null) rather than a misleading zero.
     *
     * Each tab also carries the running balance after that month — the annual
     * allotment minus the cumulative monthly totals through it — when the
     * Budget office has set an allotment. A month with no matrix carries the
     * previous balance forward, so a gap in the year reads as unchanged.
     *
     * One query for the whole year, so drawing the strip costs nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function months(): array
    {
        $matrix = $this->matrix;
        $allotted = $this->allotted;

        $siblings = MonthlyExpenditureMatrix::query()
            ->where('school_id', $matrix->school_id)
            ->where('year', $matrix->year)
            ->withCount('items')
            ->withSum('items', 'calculated_total')
            ->get()
            ->keyBy('month');

        $months = [];
        $running = 0.0;

        foreach (MonthlyExpenditureMatrix::MONTH_ABBREVIATIONS as $number => $abbreviation) {
            $sibling = $siblings->get($number);
            $isCurrent = $number === (int) $matrix->month;

            $total = $isCurrent
                ? ($this->items === [] ? null : $this->totals['grand'])
                : ($sibling instanceof MonthlyExpenditureMatrix && $sibling->items_count > 0
                    ? (float) ($sibling->items_sum_calculated_total ?? 0)
                    : null);

            $running = round($running + (float) ($total ?? 0.0), 2);

            $months[] = [
                'month' => $number,
                'abbr' => strtoupper($abbreviation),
                'label' => $sibling instanceof MonthlyExpenditureMatrix
                    ? $sibling->periodLabel()
                    : $abbreviation.' '.$matrix->year,
                'id' => $sibling instanceof MonthlyExpenditureMatrix ? (int) $sibling->id : null,
                'status' => $sibling instanceof MonthlyExpenditureMatrix ? $sibling->status : null,
                'current' => $isCurrent,
                'total' => $total,
                'balance' => $allotted === null ? null : round($allotted - $running, 2),
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
