<?php

namespace App\Livewire;

use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Support\MemYearInitializer;
use App\Support\WfpAllotment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Monthly Expenditure — the Budget office's read-only view of every school's
 * Monthly Expenditure Matrices.
 *
 * The page has two levels, like the School Head's matrix index:
 *
 *   · no school selected — the index of schools. Each row carries the school,
 *     its School Head account(s), and the selected year's line and matrix
 *     counts. A school with nothing encoded yet still appears, because the
 *     Budget office is checking the roster, not only the schools with data.
 *
 *   · one school selected — that school's detail: the year's allotted /
 *     consumed / remaining strip, the JAN–DEC tabs with each month's total and
 *     running balance, and the printed MEM's line columns.
 *
 * The page is READ-ONLY. A School Head owns these lines in the matrix editor;
 * the Budget office checks them, and never edits a school's plan from here.
 * There is nothing to save and nothing to generate: the rows are the matrices
 * as they stand, so a line entered this afternoon is on this page this
 * afternoon.
 *
 * The total on each row is quantity × unit cost, recomputed here — the same
 * rule the matrix editor, the printed MEM and the Per Line Item Expenditure
 * report follow. A stored `calculated_total` is never trusted as the figure
 * to check.
 *
 * Scope is the Budget role and the school Bookkeeper (canCheckLineItems());
 * every other role gets a 404 from the route and from mount(). The filters
 * (year, school, month, search) are read-only narrowing — they can never
 * reach a school's data outside the query they build here.
 */
#[Title('Monthly Expenditure — SmartApp')]
class BudgetLineItemChecker extends Component
{
    use WithPagination;

    /** The matrix year being checked. */
    #[Url]
    public string $year = '';

    /** One school's id, or '' for every school. */
    #[Url]
    public string $school = '';

    /** One month (1–12), or '' for the whole year. */
    #[Url]
    public string $month = '';

    /** Search across school, objective, activity and account. */
    public string $search = '';

    public int $perPage = 25;

    public function mount(): void
    {
        $this->user();

        // A crafted or out-of-range year is not a year to report on: open on
        // the newest year the schools actually have lines for.
        if (! $this->validYear()) {
            $this->year = (string) $this->defaultYear();
        }

        // The two id filters are narrowing only, so a malformed value is
        // simply dropped rather than allowed to reach the query. A school
        // that no longer exists falls back to the index of schools.
        $this->school = ctype_digit($this->school) ? $this->school : '';

        if ($this->school !== '' && ! School::query()->whereKey((int) $this->school)->exists()) {
            $this->school = '';
        }

        $this->month = $this->monthNumber() === null ? '' : $this->month;
    }

    /* -------------------------------------------------------------- guards */

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canCheckLineItems(), 404);

        return $user;
    }

    /* --------------------------------------------------------------- state */

    public function updatedYear(): void
    {
        if (! $this->validYear()) {
            $this->year = (string) $this->defaultYear();
        }

        $this->resetPage();
        $this->forgetFigures();
    }

    public function updatedSchool(): void
    {
        if (! ctype_digit($this->school)
            || ! School::query()->whereKey((int) $this->school)->exists()) {
            $this->school = '';
        }

        // Moving between the school index and one school's detail starts the
        // other view's search and month on a clean slate.
        $this->search = '';
        $this->month = '';
        $this->resetPage();
        $this->forgetFigures();
    }

    public function updatedMonth(): void
    {
        if ($this->monthNumber() === null) {
            $this->month = '';
        }

        $this->resetPage();
        $this->forgetFigures();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->forgetFigures();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
        $this->forgetFigures();
    }

    /**
     * Clear the line filters but stay on the selected school and year.
     * Leaving the school is the breadcrumb's job, not the empty table's.
     */
    public function clearFilters(): void
    {
        $this->month = '';
        $this->search = '';
        $this->resetPage();
        $this->forgetFigures();
    }

    /** Open one school's detail from the index. */
    public function openSchool(int $schoolId): void
    {
        if (! School::query()->whereKey($schoolId)->exists()) {
            return;
        }

        $this->school = (string) $schoolId;
        $this->month = '';
        $this->search = '';
        $this->resetPage();
        $this->forgetFigures();
    }

    /** Back from one school's detail to the school index. */
    public function backToSchools(): void
    {
        $this->school = '';
        $this->month = '';
        $this->search = '';
        $this->resetPage();
        $this->forgetFigures();
    }

    /** Open one month from the tab strip; the table then shows that month only. */
    public function selectMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            return;
        }

        $this->month = (string) $month;
        $this->resetPage();
        $this->forgetFigures();
    }

    /** Back to the whole year from a selected month tab. */
    public function clearMonth(): void
    {
        $this->month = '';
        $this->resetPage();
        $this->forgetFigures();
    }

    /**
     * Drop the memoised figures after a filter or school changes. The school
     * index, the summary, the month tabs and the allotment strip all read
     * different slices of the same filters, so they are refreshed together.
     */
    private function forgetFigures(): void
    {
        unset(
            $this->schools,
            $this->selectedSchool,
            $this->summary,
            $this->allotted,
            $this->months,
            $this->allocation,
        );
    }

    /* ------------------------------------------------------------ computed */

    /**
     * The years the page can report on: every year a matrix exists for, plus
     * the current calendar year, newest first.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function years(): array
    {
        $years = MonthlyExpenditureMatrix::query()
            ->distinct()
            ->pluck('year')
            ->map(fn ($year): string => (string) $year)
            ->all();

        $current = (string) now()->year;

        if (! in_array($current, $years, true)) {
            $years[] = $current;
        }

        usort($years, fn (string $a, string $b): int => (int) $b <=> (int) $a);

        return $years;
    }

    /**
     * The school index: every school, by name, with its School Head account(s),
     * narrowed by the search box. Unlike the line table, this is not derived
     * from the matrices — a school that has not encoded anything yet must
     * still appear, so the Budget office can see the whole roster.
     *
     * @return LengthAwarePaginator<int, School>
     */
    #[Computed]
    public function schools(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return School::query()
            ->with([
                'district',
                'users' => fn ($query) => $query
                    ->where('role', 'user')
                    ->orderBy('name')
                    ->orderBy('username'),
            ])
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.$term.'%';

                $query->where(function ($query) use ($like): void {
                    $query
                        ->where('name', 'like', $like)
                        ->orWhere('school_id', 'like', $like)
                        ->orWhereHas('district', fn ($district) => $district->where('name', 'like', $like))
                        ->orWhereHas('users', fn ($user) => $user
                            ->where('role', 'user')
                            ->where(fn ($user) => $user
                                ->where('name', 'like', $like)
                                ->orWhere('username', 'like', $like)));
                });
            })
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    /**
     * The school whose monthly expenditure is open, or null when the page is
     * showing the index of schools. The route only carries an id; this is the
     * one place it is resolved into a School, and mount() has already dropped
     * an id that no longer exists.
     */
    #[Computed]
    public function selectedSchool(): ?School
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return null;
        }

        return School::query()
            ->with([
                'district',
                'users' => fn ($query) => $query
                    ->where('role', 'user')
                    ->orderBy('name')
                    ->orderBy('username'),
            ])
            ->find($schoolId);
    }

    /**
     * The allotment budget the Budget office set for the open school's year.
     * Null when no ceiling has been set, so "Not set" and an explicit 0.00
     * stay different answers.
     */
    #[Computed]
    public function allotted(): ?float
    {
        $schoolId = $this->schoolId();

        return $schoolId === null
            ? null
            : WfpAllotment::allotment($schoolId, (int) $this->year);
    }

    /**
     * The twelve months of the open school's selected year, in calendar
     * order — the tab strip above the table. Each tab carries that month's
     * total: quantity × unit cost over the school's lines, recomputed here
     * rather than read from `calculated_total`.
     *
     * The strip deliberately ignores the search and month filters. It is the
     * year's context; the table below it is the filtered detail. When an
     * allotment is set, each tab also carries the running balance after that
     * month, exactly as the School Head's matrix page does. A month with no
     * lines has no total rather than a misleading zero, and carries the
     * previous balance forward.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function months(): array
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return [];
        }

        $year = (int) $this->year;

        $totals = MonthlyExpenditureItem::query()
            ->join('monthly_expenditure_matrices as mem', 'mem.id', '=', 'monthly_expenditure_items.matrix_id')
            ->where('mem.year', $year)
            ->where('mem.school_id', $schoolId)
            ->groupBy('mem.month')
            ->selectRaw(
                'mem.month as month, '
                .'SUM(ROUND(COALESCE(monthly_expenditure_items.quantity, 0) '
                .'* COALESCE(monthly_expenditure_items.unit_cost, 0), 2)) as total'
            )
            ->pluck('total', 'month');

        $allotted = $this->allotted;
        $labels = config('mem.months', []);
        $running = 0.0;
        $months = [];

        foreach (MonthlyExpenditureMatrix::MONTH_ABBREVIATIONS as $number => $abbreviation) {
            $value = $totals->get($number);
            $total = $value === null ? null : round((float) $value, 2);

            $running = round($running + (float) ($total ?? 0.0), 2);

            $months[] = [
                'month' => $number,
                'abbr' => strtoupper($abbreviation),
                'label' => ($labels[$number] ?? $abbreviation).' '.$year,
                'current' => (int) $this->month === $number,
                'total' => $total,
                'balance' => $allotted === null ? null : round($allotted - $running, 2),
            ];
        }

        return $months;
    }

    /**
     * The year's allotted / consumed / remaining figures, through the same
     * helper the School Head's matrix page and the annual report use. The
     * consumed figure is the sum of the twelve month totals; it is the whole
     * year, not only the rows the table currently shows, matching the matrix
     * page's strip.
     *
     * @return array{allotted: ?float, consumed: float, remaining: float, percent: float, is_over: bool}
     */
    #[Computed]
    public function allocation(): array
    {
        $consumed = 0.0;

        foreach ($this->months as $month) {
            $consumed += (float) ($month['total'] ?? 0.0);
        }

        return WfpAllotment::balance($this->allotted, $consumed);
    }

    /**
     * The figures above the table, for the whole filtered set (not only the
     * visible page): how many lines, how many schools and monthly matrices
     * they come from, and what they add up to.
     *
     * The total is the sum of quantity × unit cost, rounded per line — the
     * same figure the Per Line Item Expenditure report and the .xlsx export
     * use, so the Budget office checks the money the school head reads.
     *
     * @return array{lines: int, schools: int, matrices: int, total: float}
     */
    #[Computed]
    public function summary(): array
    {
        $query = $this->filteredQuery();

        $total = (clone $query)
            ->selectRaw(
                'SUM(ROUND(COALESCE(monthly_expenditure_items.quantity, 0) '
                .'* COALESCE(monthly_expenditure_items.unit_cost, 0), 2)) as total'
            )
            ->value('total');

        return [
            'lines' => (clone $query)->count('monthly_expenditure_items.id'),
            'schools' => (clone $query)->distinct()->count('mem.school_id'),
            'matrices' => (clone $query)->distinct()->count('mem.id'),
            'total' => round((float) ($total ?? 0), 2),
        ];
    }

    /**
     * The filtered lines, in the order a reader checks them: school, month,
     * section (operations before project) and the School Head's own line
     * order within the section.
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->select([
                'monthly_expenditure_items.*',
                'mem.month as matrix_month',
                'mem.year as matrix_year',
                'mem.status as matrix_status',
                'schools.name as school_name',
            ])
            ->orderBy('schools.name')
            ->orderBy('mem.month')
            ->orderBy('monthly_expenditure_items.section')
            ->orderBy('monthly_expenditure_items.position')
            ->orderBy('monthly_expenditure_items.id')
            ->paginate($this->perPage);
    }

    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    /* --------------------------------------------------------------- query */

    /**
     * One query for the rows and every figure in the summary, so the table
     * and the totals above it can never describe different sets of lines.
     */
    private function filteredQuery(): Builder
    {
        $schoolId = $this->schoolId();
        $month = $this->monthNumber();
        $term = trim($this->search);

        return MonthlyExpenditureItem::query()
            ->join('monthly_expenditure_matrices as mem', 'mem.id', '=', 'monthly_expenditure_items.matrix_id')
            ->join('schools', 'schools.id', '=', 'mem.school_id')
            ->where('mem.year', (int) $this->year)
            ->when($schoolId !== null, fn (Builder $query) => $query->where('mem.school_id', $schoolId))
            ->when($month !== null, fn (Builder $query) => $query->where('mem.month', $month))
            ->when($term !== '', function (Builder $query) use ($term): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query
                        ->where('monthly_expenditure_items.objective', 'like', $like)
                        ->orWhere('monthly_expenditure_items.activity_description', 'like', $like)
                        ->orWhere('monthly_expenditure_items.resource_kind', 'like', $like)
                        ->orWhere('monthly_expenditure_items.account_title', 'like', $like)
                        ->orWhere('monthly_expenditure_items.account_code', 'like', $like)
                        ->orWhere('schools.name', 'like', $like)
                        ->orWhere('schools.school_id', 'like', $like);
                });
            });
    }

    /* -------------------------------------------------------------- helpers */

    private function defaultYear(): int
    {
        $latest = (int) MonthlyExpenditureMatrix::query()->max('year');

        return $latest > 0 ? $latest : (int) now()->year;
    }

    private function validYear(): bool
    {
        return ctype_digit($this->year)
            && (int) $this->year >= MemYearInitializer::MIN_YEAR
            && (int) $this->year <= MemYearInitializer::MAX_YEAR;
    }

    private function schoolId(): ?int
    {
        return ctype_digit($this->school) ? (int) $this->school : null;
    }

    private function monthNumber(): ?int
    {
        if (! ctype_digit($this->month)) {
            return null;
        }

        $month = (int) $this->month;

        return $month >= 1 && $month <= 12 ? $month : null;
    }

    /**
     * Quantity × unit cost, rounded to two decimals. The same rule the matrix
     * editor and the printed reports use: a line missing either factor
     * contributes nothing rather than a wrong figure.
     */
    public static function lineTotal(MonthlyExpenditureItem $item): float
    {
        return round((float) ($item->quantity ?? 0) * (float) ($item->unit_cost ?? 0), 2);
    }

    public function render()
    {
        $this->user();

        $selectedSchool = $this->selectedSchool;

        // No school in the URL: the index of schools, each with its School
        // Head and this year's line/matrix counts. This is the Budget office's
        // landing view — the equivalent of the School Head's matrix index.
        if ($selectedSchool === null) {
            $schools = $this->schools;

            $matrices = MonthlyExpenditureMatrix::query()
                ->where('year', (int) $this->year)
                ->whereIn('school_id', collect($schools->items())->pluck('id')->all())
                ->withCount('items')
                ->get()
                ->groupBy('school_id');

            return view('livewire.budget.line-item-checker', [
                'selectedSchool' => null,
                'schools' => $schools,
                'matrices' => $matrices,
            ]);
        }

        // One school in the URL: its allotment strip, month tabs and lines.
        return view('livewire.budget.line-item-checker', [
            'selectedSchool' => $selectedSchool,
            'rows' => $this->rows,
            'summary' => $this->summary,
        ]);
    }
}
