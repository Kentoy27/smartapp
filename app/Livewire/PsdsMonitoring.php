<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\PsdsMonitoringReport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The PSDS's monitoring workspace: the AIP, OPCRF and WFP submission status
 * of the schools in the districts the account is assigned.
 *
 * Strictly view-only — the page reads submission records and writes nothing.
 * The guard is the role alone (isPsdsRole()), re-checked on every action, and
 * every query is scoped by the account's own district list
 * (assignedDistrictIds()), never by anything the request carries:
 *
 *   - a district filter naming an unassigned district narrows the table to
 *     nothing; it never widens the query;
 *   - opening the detail of a school outside the assigned districts is
 *     refused with a 404 before anything is read;
 *   - the CSV export runs through the same scoped report.
 *
 * The same component serves the dashboard (/home) and its own page (/psds),
 * so the two can never report different figures.
 */
#[Title('School Submission Monitoring — SmartApp')]
class PsdsMonitoring extends Component
{
    use WithPagination;

    /** The reporting period on screen (empty = all years). */
    #[Url(as: 'year')]
    public string $yearFilter = '';

    #[Url(as: 'district')]
    public string $districtFilter = '';

    #[Url(as: 'school')]
    public string $schoolFilter = '';

    #[Url(as: 'aip')]
    public string $aipFilter = '';

    #[Url(as: 'opcrf')]
    public string $opcrfFilter = '';

    #[Url(as: 'wfp')]
    public string $wfpFilter = '';

    #[Url(as: 'overall')]
    public string $overallFilter = '';

    /** School name or school id. */
    #[Url(as: 'q')]
    public string $search = '';

    /** The school whose read-only detail modal is open, if any. */
    public ?int $detailSchoolId = null;

    /** The detail's reporting period, as a string (empty = all years). */
    public string $detailYear = '';

    public bool $showDetail = false;

    /** The per-request report service; never serialised by Livewire. */
    private ?PsdsMonitoringReport $reportService = null;

    public function mount(): void
    {
        $this->psds();

        // The page opens on the current reporting year. A malformed year, or
        // one the account has no data for, falls back to the current year
        // rather than rendering a report nobody asked for.
        $requested = ctype_digit($this->yearFilter) ? (int) $this->yearFilter : null;

        if ($requested === null || ! in_array($requested, $this->report()->yearOptions(), true)) {
            $this->yearFilter = (string) now()->year;
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'districtFilter') {
            // A school from the old district would silently empty the table;
            // move the school picker back to "all schools" with the district.
            $this->schoolFilter = '';
        }

        if (in_array($property, [
            'yearFilter', 'districtFilter', 'schoolFilter',
            'aipFilter', 'opcrfFilter', 'wfpFilter', 'overallFilter', 'search',
        ], true)) {
            $this->resetPage();
        }
    }

    /* -------------------------------------------------------------- guards */

    private function psds(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isPsdsRole(), 404);

        return $user;
    }

    private function report(): PsdsMonitoringReport
    {
        return $this->reportService ??= new PsdsMonitoringReport($this->psds());
    }

    /* ------------------------------------------------------------ filters */

    /** @return array<string, string> */
    private function filters(): array
    {
        return [
            'district' => $this->districtFilter,
            'school' => $this->schoolFilter,
            'year' => $this->yearFilter,
            'aip' => $this->aipFilter,
            'opcrf' => $this->opcrfFilter,
            'wfp' => $this->wfpFilter,
            'overall' => $this->overallFilter,
            'search' => $this->search,
        ];
    }

    private function districtId(): ?int
    {
        return ctype_digit($this->districtFilter) && (int) $this->districtFilter > 0
            ? (int) $this->districtFilter
            : null;
    }

    /* ----------------------------------------------------------- computed */

    /**
     * The summary, coverage, scoped rows and filtered table in one pass, so
     * the view's figures all come from the same queries.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function reportData(): array
    {
        return $this->report()->dashboard($this->filters());
    }

    /**
     * The account's assigned districts (the picker's roster and the coverage
     * cards' list). Never a request payload.
     *
     * @return Collection<int, \App\Models\District>
     */
    #[Computed]
    public function districts(): Collection
    {
        return $this->report()->districts();
    }

    /**
     * The school picker's options, narrowed to the chosen district.
     *
     * @return Collection<int, \App\Models\School>
     */
    #[Computed]
    public function schoolOptions(): Collection
    {
        return $this->report()->schools($this->districtId());
    }

    /** @return array<int, int> */
    #[Computed]
    public function yearOptions(): array
    {
        return $this->report()->yearOptions();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function summary(): array
    {
        return $this->reportData['summary'];
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function coverage(): Collection
    {
        return $this->reportData['coverage'];
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    #[Computed]
    public function paginatedRows(): LengthAwarePaginator
    {
        $rows = collect($this->reportData['rows']);
        $perPage = 12;
        $page = max(1, (int) $this->getPage());

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /** Whether anything but the default reporting year is narrowing the view. */
    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->districtFilter !== ''
            || $this->schoolFilter !== ''
            || $this->yearFilter !== (string) now()->year
            || $this->aipFilter !== ''
            || $this->opcrfFilter !== ''
            || $this->wfpFilter !== ''
            || $this->overallFilter !== ''
            || trim($this->search) !== '';
    }

    /** The current view as a read-only CSV, re-scoped server-side. */
    #[Computed]
    public function exportUrl(): string
    {
        $query = array_filter(
            [
                'district' => $this->districtFilter,
                'school' => $this->schoolFilter,
                'year' => $this->yearFilter,
                'aip' => $this->aipFilter,
                'opcrf' => $this->opcrfFilter,
                'wfp' => $this->wfpFilter,
                'overall' => $this->overallFilter,
                'q' => trim($this->search),
            ],
            fn (string $value): bool => $value !== ''
        );

        return route('psds.report', $query);
    }

    /* ------------------------------------------------------------ details */

    public function openDetails(int $schoolId): void
    {
        // The school must sit in one of the account's districts. This is the
        // same boundary schoolDetail() re-checks; doing it first means a
        // crafted id never reaches the report queries.
        abort_unless($this->psds()->canMonitorSchool($schoolId), 404);

        $this->detailSchoolId = $schoolId;
        $this->detailYear = $this->yearFilter;
        $this->showDetail = true;

        unset($this->detail);
    }

    public function closeDetails(): void
    {
        $this->showDetail = false;
        $this->detailSchoolId = null;
        $this->detailYear = '';
    }

    /**
     * The open school's statuses, document metadata and review history.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function detail(): ?array
    {
        if (! $this->showDetail || $this->detailSchoolId === null) {
            return null;
        }

        $year = ctype_digit($this->detailYear) ? (int) $this->detailYear : null;

        return $this->report()->schoolDetail($this->detailSchoolId, $year);
    }

    /* -------------------------------------------------------------- actions */

    public function resetFilters(): void
    {
        $this->districtFilter = '';
        $this->schoolFilter = '';
        $this->yearFilter = (string) now()->year;
        $this->aipFilter = '';
        $this->opcrfFilter = '';
        $this->wfpFilter = '';
        $this->overallFilter = '';
        $this->search = '';

        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    public function render()
    {
        return view('livewire.psds.monitoring');
    }
}
