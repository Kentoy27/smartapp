<?php

namespace App\Livewire;

use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Models\WfpItem;
use App\Models\WfpPlan;
use App\Models\WfpReportCheck;
use App\Support\MemWorkbookImporter;
use App\Support\MemYearInitializer;
use App\Support\MemYearXlsxExporter;
use App\Support\WfpAllotment;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The Monthly Expenditure Matrix index: the School Head's own school's
 * matrices, one per month of a planning year.
 *
 * The year is the primary organising key. A year's twelve months
 * (January–December, in calendar order) are created only when the SH adds
 * that year with the "Add year" control; opening the page or filtering by a
 * year never writes. Existing months are preserved untouched; only the
 * missing ones are inserted, as blank Drafts — see {@see MemYearInitializer}.
 *
 * Scope is the SH's own school — every query is filtered by `school_id`, and
 * every action re-checks ownership. Without a school nothing is created (and
 * the page says so), because a matrix with no school could be owned by nobody.
 */
#[Title('Monthly Expenditure Matrix — SmartApp')]
class MemMatrixList extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** How many analysed rows are previewed in the workbook-import modal. */
    private const WORKBOOK_PREVIEW_LIMIT = 100;

    /** How many months are listed per page. */
    private const PER_PAGE = 25;

    /**
     * The selected year, or '' for "All". Lives in the URL so a refresh (or a
     * bookmark) keeps the SH on the year they were working in rather than
     * snapping back to the current calendar year.
     */
    #[Url]
    public string $year = '';

    /** The year the "Add year" field targets. Defaults to next year. */
    public string $newYear = '';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    /* Workbook import (a single .xlsx whose sheets are JAN..DEC). */
    public $workbookFile;

    public bool $showWorkbookImport = false;

    /** @var array<int, array<string, mixed>> */
    public array $workbookMonths = [];

    /** @var array<int, array<string, mixed>> */
    public array $workbookRows = [];

    public int $workbookValidCount = 0;

    public int $workbookInvalidCount = 0;

    /** @var array<int, string> */
    public array $workbookWarnings = [];

    public ?string $workbookError = null;

    /** Delete-confirmation state. */
    public bool $showConfirm = false;

    public ?int $confirmId = null;

    public string $confirmTitle = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);

        // The year is the primary control and it lives in the URL, so a
        // refresh keeps the SH on the year they were working in. When no year
        // is given — e.g. arriving from a sidebar link, which carries no query
        // string — open on the latest planning year the school has, so a year
        // the SH added is not "forgotten" by simply switching tabs. The twelve
        // months are still ensured by the view, as a POST — never as a side
        // effect of this GET.
        if ($this->filterYear() === null) {
            $this->year = (string) $this->defaultYear();
        }

        // The "Add year" field pre-fills with next year — the usual next
        // planning cycle — so adding one is a single click.
        $this->newYear = (string) (now()->year + 1);
    }

    /* -------------------------------------------------------------- guards */

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessWfp(), 404);

        return $user;
    }

    /**
     * The page's WRITE gate: creating a year, initialising its months,
     * importing a workbook, deleting a matrix. The SH's alone.
     */
    private function manager(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageWfp(), 404);

        return $user;
    }

    /** The financial division this page is working in. */
    private function division(): string
    {
        return $this->user()->activeDivision();
    }

    /**
     * Whether the signed-in account may edit the school's work on this page.
     * The Blade hides the write controls for a read-only reader; every
     * action re-checks the same gate server-side.
     */
    public function canEdit(): bool
    {
        return Auth::user()?->canManageWfp() === true;
    }

    #[Computed]
    public function signatoryStatuses(): array
    {
        $year = $this->filterYear() ?? (int) now()->year;

        return WfpAllotment::signatoryStatuses(
            Auth::user()?->school_id,
            $year,
            $this->division(),
        );
    }

    /* ------------------------------------------------------------ the year */

    /**
     * Add a planning year the school does not have yet.
     *
     * This control is what brings a planning year into being: opening the
     * page, or filtering by a year, is a read. The year's twelve months are
     * ensured and the filter switches to the new year so its months show
     * immediately.
     */
    public function addYear(): void
    {
        $user = $this->manager();

        if ($user->school_id === null) {
            $this->errorMessage = 'Your account has no school assigned, so it cannot own a matrix. Ask an administrator to set your school first.';

            return;
        }

        $raw = trim($this->newYear);

        if (
            !ctype_digit($raw)
            || (int) $raw < MemYearInitializer::MIN_YEAR
            || (int) $raw > MemYearInitializer::MAX_YEAR
        ) {
            $this->errorMessage = 'Enter a planning year between ' . MemYearInitializer::MIN_YEAR . ' and ' . MemYearInitializer::MAX_YEAR . ' to add.';

            return;
        }

        $year = (int) $raw;

        $created = $this->ensureYear($year);

        $this->year = (string) $year;
        $this->newYear = (string) min($year + 1, MemYearInitializer::MAX_YEAR);
        $this->resetPage();
        unset($this->years, $this->rows);

        $this->successMessage = $created > 0
            ? "{$created} missing " . ($created === 1 ? 'month was' : 'months were') . " created for CY {$year}. The rest were left as they were."
            : "All twelve months already exist for CY {$year}.";
    }

    /**
     * The year the page opens on when none is in the URL: the latest year the
     * school has a matrix for, but never older than the current calendar year
     * — so a school that only has past data still lands on the current year (as
     * before), while one that has planned ahead lands on its newest year.
     */
    private function defaultYear(): int
    {
        $schoolId = Auth::user()?->school_id;

        $latest = $schoolId === null
            ? null
            : MonthlyExpenditureMatrix::query()
                ->where('school_id', $schoolId)
                ->where('school_division', $this->division())
                ->max('year');

        return max((int) ($latest ?? 0), (int) now()->year);
    }

    /**
     * The concrete year the filter points at, or null for "All"/out of range.
     */
    private function filterYear(): ?int
    {
        if ($this->year === '') {
            return null;
        }

        $year = (int) $this->year;

        if ($year < MemYearInitializer::MIN_YEAR || $year > MemYearInitializer::MAX_YEAR) {
            return null;
        }

        return $year;
    }

    /**
     * Create the missing months for the year and return how many were made.
     * Scoped to the caller's own school; a caller with no school creates
     * nothing.
     */
    private function ensureYear(int $year): int
    {
        $schoolId = Auth::user()?->school_id;

        if ($schoolId === null) {
            return 0;
        }

        $division = $this->division();

        $before = MonthlyExpenditureMatrix::query()
            ->where('school_id', $schoolId)
            ->where('school_division', $division)
            ->where('year', $year)
            ->count();

        $matrices = (new MemYearInitializer)->initialize((int) $schoolId, $year, Auth::id(), $division);

        return $matrices->count() - $before;
    }

    /* ---------------------------------------------------------------- open */

    public function open(MonthlyExpenditureMatrix $matrix)
    {
        abort_unless($matrix->canBeViewedBy(Auth::user()), 404);

        return $this->redirect(route('wfp.matrices.edit', ['matrix' => $matrix->id]), navigate: true);
    }

    /* -------------------------------------------------------------- export */

    /**
     * The printed DepEd form for one matrix, without opening it first.
     * A plain redirect — not wire:navigate: the target is a file download,
     * and there is no page to morph into.
     */
    public function export(MonthlyExpenditureMatrix $matrix)
    {
        abort_unless($matrix->canBeViewedBy(Auth::user()), 404);

        return $this->redirect(route('wfp.matrices.export', ['matrix' => $matrix->id]));
    }

    /**
     * The whole selected year as ONE workbook: twelve worksheets (JAN…DEC),
     * each the month's printed form as it stands now. `with` stamps the
     * workflow signers' registered profile signatures; `without` leaves the
     * signature bands blank for wet signing. The workbook is generated in
     * this action, so the button's loading state covers the work and a
     * generation failure can be reported in-page instead of leaving the
     * browser on an error page. The download itself is the Livewire file
     * effect; the authenticated route is the same workbook by URL.
     */
    public function exportYear(string $signatureMode = 'with')
    {
        abort_unless(in_array($signatureMode, ['with', 'without'], true), 404);

        $user = $this->user();

        if ($user->school_id === null) {
            $this->errorMessage = 'Your account has no school assigned, so there is no workbook to export. Ask an administrator to set your school first.';

            return null;
        }

        $year = $this->filterYear();

        if ($year === null) {
            $this->errorMessage = 'Choose a specific year to export. “All” has no single year to put on the workbook.';

            return null;
        }

        $includeSignatures = $signatureMode === 'with';

        try {
            $bytes = MemYearXlsxExporter::bytes(
                (int) $user->school_id,
                $year,
                $this->division(),
                $user,
                $includeSignatures,
            );
        } catch (\Throwable $exception) {
            report($exception);
            $this->errorMessage = 'The Excel workbook could not be generated. Please try again.';

            return null;
        }

        $filename = MemYearXlsxExporter::downloadName($year, $includeSignatures);

        // Bytes are built BEFORE the response: a streamed callback must echo
        // its output (its return value is discarded).
        return response()->streamDownload(
            static function () use ($bytes): void {
                echo $bytes;
            },
            $filename,
            [
                'Content-Type' => MemYearXlsxExporter::CONTENT_TYPE,
                'Content-Length' => (string) strlen($bytes),
            ],
        );
    }

    /* -------------------------------------------------------------- delete */

    public function openConfirmDelete(MonthlyExpenditureMatrix $matrix): void
    {
        abort_unless($matrix->managedBy(Auth::user()), 404);

        $this->confirmId = $matrix->id;
        $this->confirmTitle = $matrix->periodLabel();
        $this->showConfirm = true;
    }

    public function closeConfirmDelete(): void
    {
        $this->showConfirm = false;
        $this->confirmId = null;
        $this->confirmTitle = '';
    }

    public function confirmDelete(): void
    {
        $matrix = MonthlyExpenditureMatrix::findOrFail($this->confirmId);
        abort_unless($matrix->managedBy(Auth::user()), 404);

        if ($this->usedByFinalizedPlan($matrix)) {
            $this->closeConfirmDelete();
            $this->errorMessage = 'This matrix is used by a finalized WFP plan, so it cannot be deleted.';

            return;
        }

        $title = $matrix->periodLabel();

        $checkCleared = WfpReportCheck::query()
            ->where('school_id', $matrix->school_id)
            ->where('school_division', $matrix->schoolDivision())
            ->where('year', $matrix->year)
            ->exists();

        // The items cascade with the matrix (FK cascadeOnDelete). Any generated
        // WFP line keeps its (now dangling) source line id but loses its
        // source_matrix_id, so the next regeneration removes it.
        $matrix->delete();

        // The deleted month's lines were part of the year's report the
        // Bookkeeper may have checked; the check no longer describes what is
        // left, so it is cleared.
        WfpReportCheck::clearFor((int) $matrix->school_id, (int) $matrix->year, $matrix->schoolDivision());

        $this->closeConfirmDelete();
        $this->resetPage();
        $this->successMessage = "Matrix deleted — “{$title}” and its lines were removed."
            . ($checkCleared ? " The Bookkeeper's check of account titles and codes was cleared because the lines changed." : '');
    }

    /**
     * Does a finalized plan depend on this matrix? A finalized plan's rows must
     * not be silently orphaned by a delete.
     */
    private function usedByFinalizedPlan(MonthlyExpenditureMatrix $matrix): bool
    {
        return WfpItem::query()
            ->where('source_matrix_id', $matrix->id)
            ->whereHas('plan', fn($query) => $query->where('status', WfpPlan::STATUS_FINALIZED))
            ->exists();
    }

    /* ----------------------------------------------------- workbook import */

    /**
     * Open the workbook importer. The whole-year workbook is imported from
     * THIS page rather than a single matrix: it spans all twelve months, which
     * is exactly what the year-driven page manages.
     */
    public function openWorkbookImport(): void
    {
        $this->manager();

        $this->resetWorkbookImport();
        $this->showWorkbookImport = true;
    }

    public function closeWorkbookImport(): void
    {
        $this->showWorkbookImport = false;
        $this->resetWorkbookImport();
    }

    /**
     * A file was chosen: analyse it (writing nothing) and show the per-month
     * preview, so the SH confirms before a whole-month replace.
     */
    public function updatedWorkbookFile(): void
    {
        $user = $this->manager();

        $this->resetWorkbookState();

        if ($user->school_id === null) {
            $this->workbookError = 'Your account has no school assigned, so it cannot own a matrix.';

            return;
        }

        $maxKb = (int) config('mem.workbook.max_size_kb', 10240);

        $this->validate([
            'workbookFile' => ['required', 'file', 'max:' . $maxKb, 'extensions:' . (string) config('mem.workbook.extensions', 'xlsx')],
        ], [
            'workbookFile.required' => 'Choose a workbook (.xlsx or .xlsm) to import.',
            'workbookFile.extensions' => 'The matrix workbook must be an Excel file (.xlsx or .xlsm).',
            'workbookFile.max' => 'The file is too large (' . round($maxKb / 1024, 1) . ' MB maximum).',
        ]);

        try {
            $analysis = MemWorkbookImporter::analyzeWorkbook(
                $this->workbookFile->getRealPath(),
                (int) $user->school_id,
                $this->importYear(),
            );
        } catch (RuntimeException $exception) {
            $this->workbookFile = null;
            $this->workbookError = $exception->getMessage();

            return;
        }

        $this->populateWorkbookPreview($analysis);
    }

    public function commitWorkbookImport(): void
    {
        $user = $this->manager();

        if ($this->workbookFile === null) {
            $this->workbookError = 'Choose a .xlsx workbook to import.';

            return;
        }

        if ($user->school_id === null) {
            $this->workbookError = 'Your account has no school assigned, so it cannot own a matrix.';

            return;
        }

        try {
            $analysis = MemWorkbookImporter::analyzeWorkbook(
                $this->workbookFile->getRealPath(),
                (int) $user->school_id,
                $this->importYear(),
            );
        } catch (RuntimeException $exception) {
            $this->workbookError = $exception->getMessage();

            return;
        }

        if ($analysis['validCount'] === 0) {
            $this->workbookError = 'There are no valid rows to import.';

            return;
        }

        $year = $this->importYear();

        $division = $this->division();

        $checkCleared = WfpReportCheck::query()
            ->where('school_id', $user->school_id)
            ->where('school_division', $division)
            ->where('year', $year)
            ->exists();

        $result = MemWorkbookImporter::commit($analysis['valid'], (int) $user->school_id, $year, $user, $division);

        // The import replaced lines the report's check signed off on, so the
        // check no longer describes them: clear it (see WfpReportCheck).
        WfpReportCheck::clearFor((int) $user->school_id, $year, $division);

        $this->showWorkbookImport = false;
        $this->resetWorkbookImport();
        $this->resetPage();

        $this->successMessage = "Imported {$result['imported']} line(s) across {$result['months']} month(s) for CY {$year}."
            . ($checkCleared ? " The Bookkeeper's check of account titles and codes was cleared because the lines changed." : '');
    }

    /** The year the import targets: the filter's year, or the current one. */
    private function importYear(): int
    {
        return $this->filterYear() ?? (int) now()->year;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function populateWorkbookPreview(array $analysis): void
    {
        $months = [];
        $rows = [];

        foreach ($analysis['months'] as $month) {
            $months[] = [
                'month' => $month['month'],
                'name' => $month['name'],
                'valid' => $month['validCount'],
                'invalid' => $month['invalidCount'],
                'total' => $month['total'],
            ];

            foreach ($month['rows'] as $row) {
                if (count($rows) >= self::WORKBOOK_PREVIEW_LIMIT) {
                    continue 2;
                }

                $rows[] = [
                    'month' => $month['name'],
                    'line' => $row['line'],
                    'section' => (string) ($row['data']['section'] ?? ''),
                    'activity' => (string) ($row['data']['activity_description'] ?? ''),
                    'quantity' => $row['data']['quantity'] ?? null,
                    'unit_cost' => $row['data']['unit_cost'] ?? null,
                    'valid' => (bool) $row['valid'],
                    'errors' => $row['errors'] ?? [],
                ];
            }
        }

        $this->workbookMonths = $months;
        $this->workbookRows = $rows;
        $this->workbookValidCount = $analysis['validCount'];
        $this->workbookInvalidCount = $analysis['invalidCount'];
        $this->workbookWarnings = $analysis['warnings'];

        if ($analysis['months'] === []) {
            $this->workbookError = 'The workbook has no monthly sheets (JAN…DEC).';
        }
    }

    private function resetWorkbookImport(): void
    {
        $this->workbookFile = null;
        $this->resetWorkbookState();
    }

    private function resetWorkbookState(): void
    {
        $this->workbookMonths = [];
        $this->workbookRows = [];
        $this->workbookValidCount = 0;
        $this->workbookInvalidCount = 0;
        $this->workbookWarnings = [];
        $this->workbookError = null;
        $this->resetValidation('workbookFile');
    }

    public function clearWorkbookError(): void
    {
        $this->workbookError = null;
    }

    /* --------------------------------------------------------------- state */

    public function updatedYear(): void
    {
        // Filtering is a read: it never creates the year's months. Those are
        // created only when the SH adds the year.
        $this->resetPage();
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    /* ----------------------------------------------------------- computed */

    public function canCreate(): bool
    {
        return Auth::user()?->school_id !== null;
    }

    #[Computed]
    public function school(): ?School
    {
        return Auth::user()?->school;
    }

    /**
     * The available years to filter by: every year the school has a matrix
     * for, plus the current year so a fresh school still sees a filter.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function years(): array
    {
        $schoolId = Auth::user()?->school_id;

        $years = $schoolId === null
            ? []
            : MonthlyExpenditureMatrix::query()
                ->where('school_id', $schoolId)
                ->where('school_division', $this->division())
                ->distinct()
                ->orderByDesc('year')
                ->pluck('year')
                ->map(fn($year) => (string) $year)
                ->all();

        $current = (string) now()->year;

        if (!in_array($current, $years, true)) {
            $years[] = $current;
        }

        // The selected year must always be selectable, even if a matrix was
        // deleted out from under it.
        if ($this->year !== '' && !in_array($this->year, $years, true)) {
            $years[] = $this->year;
        }

        rsort($years);

        return $years;
    }

    #[Computed]
    public function rows()
    {
        $schoolId = Auth::user()?->school_id;
        $division = $this->division();

        return MonthlyExpenditureMatrix::query()
            ->withCount('items')
            ->when(
                $schoolId === null,
                fn($query) => $query->whereRaw('1 = 0'),
                fn($query) => $query->where('school_id', $schoolId),
            )
            ->where('school_division', $division)
            ->when($this->year !== '', fn($query) => $query->where('year', (int) $this->year))
            // Months read January → December; "All" puts the newest year first.
            ->orderByDesc('year')
            ->orderBy('month')
            ->paginate(self::PER_PAGE);
    }

    public function render()
    {
        return view('livewire.mem.matrix-list', [
            'matrices' => $this->rows,
        ]);
    }
}
