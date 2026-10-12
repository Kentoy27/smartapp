<?php

namespace App\Livewire;

use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Models\WfpItem;
use App\Models\WfpPlan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Monthly Expenditure Matrix index: the School Head's own school's
 * matrices, one per month. An SH creates a matrix, opens it to edit its lines,
 * and deletes one that is no longer needed.
 *
 * Scope is the SH's own school — every query is filtered by `school_id`, and
 * every action re-checks ownership. A create is refused (with a notice) when
 * the account has no school assigned, because a matrix with no school could be
 * owned by nobody.
 */
#[Title('Monthly Expenditure Matrix — SmartApp')]
class MemMatrixList extends Component
{
    use WithPagination;

    public string $year = '';

    public string $status = 'all';

    public int $perPage = 10;

    /** Create-matrix modal state. */
    public bool $showCreate = false;

    public string $createYear = '';

    public string $createMonth = '';

    public ?string $createError = null;

    /** Delete confirmation state. */
    public bool $showConfirm = false;

    public ?int $confirmId = null;

    public string $confirmTitle = '';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);
    }

    /* -------------------------------------------------------------- guards */

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessWfp(), 404);

        return $user;
    }

    /* -------------------------------------------------------------- create */

    public function openCreate(): void
    {
        $user = $this->user();

        $this->resetValidation();
        $this->createError = $user->school_id === null
            ? 'Your account has no school assigned, so it cannot own a matrix. Ask an administrator to set your school first.'
            : null;

        $this->createYear = (string) ($this->createYear !== '' ? $this->createYear : now()->year);
        $this->createMonth = (string) ($this->createMonth !== '' ? $this->createMonth : now()->month);
        $this->showCreate = true;
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
        $this->createError = null;
        $this->resetValidation();
    }

    public function create(): void
    {
        $user = $this->user();

        if ($user->school_id === null) {
            $this->createError = 'Your account has no school assigned, so it cannot own a matrix.';

            return;
        }

        $this->validate([
            'createYear' => ['required', 'integer', 'min:2000', 'max:2100'],
            'createMonth' => ['required', 'integer', 'min:1', 'max:12'],
        ], [], [
            'createYear' => 'year',
            'createMonth' => 'month',
        ]);

        $exists = MonthlyExpenditureMatrix::query()
            ->where('school_id', $user->school_id)
            ->where('year', (int) $this->createYear)
            ->where('month', (int) $this->createMonth)
            ->exists();

        if ($exists) {
            $this->addError('createMonth', 'A matrix for that month already exists.');

            return;
        }

        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $user->school_id,
            'year' => (int) $this->createYear,
            'month' => (int) $this->createMonth,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->showCreate = false;

        $this->redirect(route('wfp.matrices.edit', ['matrix' => $matrix->id]), navigate: true);
    }

    /* ---------------------------------------------------------------- open */

    public function open(MonthlyExpenditureMatrix $matrix)
    {
        abort_unless($matrix->managedBy(Auth::user()), 404);

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
        abort_unless($matrix->managedBy(Auth::user()), 404);

        return $this->redirect(route('wfp.matrices.export', ['matrix' => $matrix->id]));
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

        // The items cascade with the matrix (FK cascadeOnDelete). Any generated
        // WFP line keeps its (now dangling) source line id but loses its
        // source_matrix_id, so the next regeneration removes it.
        $matrix->delete();

        $this->closeConfirmDelete();
        $this->resetPage();
        $this->successMessage = "Matrix deleted — “{$title}” and its lines were removed.";
    }

    /**
     * Does a finalized plan depend on this matrix? A finalized plan's rows must
     * not be silently orphaned by a delete.
     */
    private function usedByFinalizedPlan(MonthlyExpenditureMatrix $matrix): bool
    {
        return WfpItem::query()
            ->where('source_matrix_id', $matrix->id)
            ->whereHas('plan', fn ($query) => $query->where('status', WfpPlan::STATUS_FINALIZED))
            ->exists();
    }

    /* --------------------------------------------------------------- state */

    public function updatedYear(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
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
     * The available years to filter by: every year the school has a matrix for,
     * plus the current year so a fresh school still sees a filter.
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
                ->distinct()
                ->orderByDesc('year')
                ->pluck('year')
                ->map(fn ($year) => (string) $year)
                ->all();

        $current = (string) now()->year;

        if (! in_array($current, $years, true)) {
            $years[] = $current;
        }

        rsort($years);

        return $years;
    }

    #[Computed]
    public function rows()
    {
        $schoolId = Auth::user()?->school_id;

        return MonthlyExpenditureMatrix::query()
            ->withCount('items')
            ->when(
                $schoolId === null,
                fn ($query) => $query->whereRaw('1 = 0'),
                fn ($query) => $query->where('school_id', $schoolId),
            )
            ->when($this->year !== '', fn ($query) => $query->where('year', (int) $this->year))
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->paginate($this->perPage);
    }

    public function render()
    {
        return view('livewire.mem.matrix-list', [
            'matrices' => $this->rows,
        ]);
    }
}
