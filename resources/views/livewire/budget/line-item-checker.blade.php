{{-- MONTHLY EXPENDITURE — the Budget office's read-only view of every
     school's Monthly Expenditure Matrices.

     The page has two levels, like the School Head's matrix index:
       · no school selected — the index of schools, each row carrying the
         School Head and the year's line/matrix counts;
       · one school selected — that school's allotted / consumed / remaining
         strip, the JAN–DEC tabs with each month's total and running balance,
         and the printed MEM's line columns.

     Nothing here edits a school's plan: the School Head owns the lines in the
     matrix editor. Totals are quantity × unit cost, recomputed on every load,
     never a stored figure. --}}
<div class="budget-line-check">

    @if ($selectedSchool === null)
        {{-- THE INDEX: one row per school, the equivalent of the School Head's
             month rows. A school with no lines still appears — the Budget
             office is checking the whole roster, not only the schools that
             have encoded something. --}}
        <div class="page-header">
            <h1>Monthly Expenditure</h1>
            <p>
                Every school’s monthly expenditure lines — choose a school to
                check the lines its School Head encoded in the Monthly
                Expenditure Matrices.
                <span class="mem-chip">
                    {{ number_format($schools->total()) }} {{ $schools->total() === 1 ? 'school' : 'schools' }}
                </span>
            </p>
        </div>

        <div class="card">
            <div class="card-head users-toolbar">
                <div class="users-search">
                    <input
                        type="search"
                        placeholder="Search school, School ID, district, or School Head…"
                        wire:model.live.debounce.300ms="search"
                        aria-label="Search schools"
                    >

                    @if (trim($search) !== '')
                        <button type="button" class="users-clear" wire:click="clearSearch" title="Clear search">×</button>
                    @endif
                </div>

                <label class="users-per-page">
                    <span>Year</span>
                    <select wire:model.live="year" aria-label="Filter by year">
                        @foreach ($this->years as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="users-per-page">
                    <span>Rows</span>
                    <select wire:model.live="perPage" aria-label="Rows per page">
                        @foreach ([10, 25, 50] as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="table-wrap">
                <table class="data-table budget-school-table">
                    <thead>
                        <tr>
                            <th scope="col">School</th>
                            <th scope="col">School Head</th>
                            <th scope="col">Year</th>
                            <th scope="col">Lines</th>
                            <th scope="col">Months</th>
                            <th scope="col">Updated</th>
                            <th scope="col" class="users-actions-col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($schools as $school)
                            @php
                                $schoolMatrices = $matrices->get($school->id, collect());
                                $lineCount = (int) $schoolMatrices->sum('items_count');
                                $monthCount = $schoolMatrices->count();
                                $lastUpdated = $schoolMatrices->max('updated_at');
                            @endphp

                            <tr
                                wire:key="budget-school-{{ $school->id }}"
                                class="mem-row-clickable"
                                wire:click="openSchool({{ $school->id }})"
                                title="Open {{ $school->name }}"
                            >
                                <td>
                                    <span class="cell-strong">{{ $school->name }}</span>
                                    <span class="cell-sub">
                                        {{ $school->district?->name ?? 'No district' }}@if ($school->school_id) · {{ $school->school_id }}@endif
                                    </span>
                                </td>

                                <td>
                                    @forelse ($school->users as $head)
                                        <span class="cell-strong">{{ $head->name !== '' ? $head->name : $head->username }}</span>
                                        <span class="cell-sub">{{ $head->username }}</span>
                                    @empty
                                        <span class="badge badge-muted">No SH account</span>
                                    @endforelse
                                </td>

                                <td><span class="ac-code">CY {{ $this->year }}</span></td>
                                <td>{{ number_format($lineCount) }}</td>
                                <td>{{ $monthCount }} / 12</td>
                                <td>{{ $lastUpdated?->diffForHumans() ?? '—' }}</td>

                                <td class="users-actions-col" @click.stop>
                                    <button
                                        type="button"
                                        class="users-action"
                                        wire:click="openSchool({{ $school->id }})"
                                        title="Open {{ $school->name }}"
                                    >
                                        <span class="users-action-inner">
                                            <x-icon name="file-text" :size="14" />
                                            <span>Open</span>
                                        </span>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="table-empty">
                                    @if (trim($search) !== '')
                                        No schools match “{{ $search }}”.
                                    @else
                                        No schools to display yet.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="users-footer">
                <span class="users-count">
                    @if ($schools->total() > 0)
                        {{ $schools->firstItem() }}–{{ $schools->lastItem() }} of {{ $schools->total() }}
                    @else
                        0 schools
                    @endif
                </span>

                {{ $schools->links() }}
            </div>

            <p class="allotment-footnote">
                Read-only. Open a school to check its Monthly Expenditure
                Matrices month by month; the School Head owns the lines, so
                nothing on this page can alter a school’s plan.
            </p>
        </div>
    @else
        {{-- ONE SCHOOL: the same layout as the matrix page the lines come
             from — the allotment strip, the month tabs, then the lines. --}}
        <div class="page-header">
            <div class="memory-breadcrumb">
                <a
                    href="{{ route('budget.lineitems.index', ['year' => $this->year]) }}"
                    wire:click.prevent="backToSchools"
                >Monthly Expenditure</a>
                <span aria-hidden="true">›</span>
                <span>{{ $selectedSchool->name }}</span>
            </div>

            <h1>{{ $selectedSchool->name }}</h1>

            <p>
                {{ $selectedSchool->district?->name ?? 'No district' }}@if ($selectedSchool->school_id) · {{ $selectedSchool->school_id }}@endif
                ·
                @php($schoolHead = $selectedSchool->users->first())
                @if ($schoolHead)
                    School Head:
                    {{ $schoolHead->name !== '' ? $schoolHead->name : $schoolHead->username }}
                    <span class="cell-sub">{{ $schoolHead->username }}</span>
                @else
                    <span class="badge badge-muted">No SH account</span>
                @endif
                · {{ number_format($summary['lines']) }} {{ $summary['lines'] === 1 ? 'line' : 'lines' }}
                · {{ number_format($summary['matrices']) }} {{ $summary['matrices'] === 1 ? 'monthly matrix' : 'monthly matrices' }}
                <span class="mem-status mem-status--draft">Read-only</span>
            </p>
        </div>

        {{-- THE ALLOTMENT BALANCE: the annual budget the Budget office set for
             this school, read against the year's matrices. The month tabs below
             carry the same running balance after each month. --}}
    @php($allocation = $this->allocation)
    <section
        class="allotment-summary{{ $allocation['is_over'] ? ' is-over' : '' }}"
        aria-label="Allotment budget for {{ $this->year }}"
    >
        <div class="allotment-summary__item">
            <span class="allotment-summary__label">Allotted budget</span>
            <strong class="allotment-summary__value">
                {{ $allocation['allotted'] === null ? 'Not set' : number_format($allocation['allotted'], 2) }}
            </strong>
            <span class="allotment-summary__hint">Set by the Budget office for CY {{ $this->year }}</span>
        </div>

        <div class="allotment-summary__item">
            <span class="allotment-summary__label">Consumed by monthly expenditures</span>
            <strong class="allotment-summary__value">{{ number_format($allocation['consumed'], 2) }}</strong>
            <span class="allotment-summary__hint">
                This school’s CY {{ $this->year }} matrices’ total
            </span>
        </div>

        <div class="allotment-summary__item">
            <span class="allotment-summary__label">
                {{ $allocation['is_over'] ? 'Over allotment' : 'Remaining' }}
            </span>
            <strong @class([
                'allotment-summary__value',
                'is-over' => $allocation['is_over'],
                'is-unset' => $allocation['allotted'] === null,
            ])>
                {{ $allocation['allotted'] === null
                    ? '—'
                    : number_format(abs($allocation['remaining']), 2) }}
            </strong>
            <span class="allotment-summary__hint">
                {{ $allocation['allotted'] === null
                    ? 'No ceiling set for this year'
                    : ($allocation['is_over'] ? 'More spent than allotted' : 'Still available to spend') }}
            </span>
        </div>

        @if ($allocation['allotted'] !== null)
            <div
                class="allotment-summary__progress"
                role="progressbar"
                aria-label="Allotment used"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="{{ min(100, $allocation['percent']) }}"
            >
                <span
                    class="allotment-summary__progress-bar{{ $allocation['is_over'] ? ' is-over' : '' }}"
                    style="width: {{ min(100, max(0, $allocation['percent'])) }}%"
                ></span>
            </div>
            <p class="allotment-summary__note">
                {{ number_format($allocation['percent'], 2) }}% of the allotted budget consumed.
                Each month tab shows the running balance after that month.
            </p>
        @else
            <p class="allotment-summary__note">
                No allotment budget has been set for CY {{ $this->year }} yet.
                The Budget office sets it in School Allotments; the lines are checked either way.
            </p>
        @endif
    </section>

    {{-- MONTH NAVIGATION — the twelve months of the open school's year, so the
         Budget office can check one month at a time without leaving the page.
         Each tab carries that month's total (quantity × unit cost) and, when
         an allotment is set, the running balance after it. The table below
         obeys the search and month filters; the strip describes the whole
         year. --}}
    <nav class="mem-month-tabs" aria-label="Months of CY {{ $this->year }}">
        @foreach ($this->months as $tab)
            @php($tabTotal = $tab['total'] !== null ? number_format($tab['total'], 2) : null)

            <button
                type="button"
                @class(['mem-month-tab', 'is-current' => $tab['current']])
                wire:click="selectMonth({{ $tab['month'] }})"
                wire:key="budget-month-{{ $tab['month'] }}"
                aria-pressed="{{ $tab['current'] ? 'true' : 'false' }}"
                title="Show {{ $tab['label'] }} lines"
            >
                <span class="mem-month-tab__head">
                    <span class="mem-month-tab__abbr">{{ $tab['abbr'] }}</span>
                </span>
                <span class="mem-month-tab__total">
                    @if ($tabTotal !== null)
                        <span class="sr-only">Monthly total </span>{{ $tabTotal }}
                    @else
                        <span aria-hidden="true">—</span>
                    @endif
                </span>
                @if ($tab['balance'] !== null)
                    <span @class(['mem-month-tab__balance', 'is-over' => $tab['balance'] < 0])
                          title="{{ $tab['balance'] < 0 ? 'Over allotment by ' : 'Balance after '.$tab['label'].': ' }}{{ number_format(abs($tab['balance']), 2) }}">
                        {{ $tab['balance'] < 0 ? 'Over ' : 'Bal. ' }}{{ number_format(abs($tab['balance']), 2) }}
                    </span>
                @endif
            </button>
        @endforeach
    </nav>

    <div class="card">
        <div class="card-head users-toolbar">
            <div class="users-search">
                <input
                    type="search"
                    placeholder="Search activity, account title or code…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Search expenditure lines"
                >

                @if (trim($search) !== '')
                    <button type="button" class="users-clear" wire:click="clearSearch" title="Clear search">×</button>
                @endif
            </div>

            <label class="users-per-page">
                <span>Year</span>
                <select wire:model.live="year" aria-label="Filter by year">
                    @foreach ($this->years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>

            <label class="users-per-page">
                <span>Rows</span>
                <select wire:model.live="perPage" aria-label="Rows per page">
                    @foreach ([10, 25, 50] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>

            @if ($month !== '')
                <button
                    type="button"
                    class="ac-import-btn"
                    wire:click="clearMonth"
                    title="Show every month of {{ $this->year }}"
                >
                    <x-icon name="x" :size="14" />
                    <span>All months</span>
                </button>
            @endif
        </div>

        <div class="table-wrap">
            <table class="data-table budget-line-table">
                {{-- Explicit column ruler: table-layout: fixed uses these shares
                     instead of each cell's content, so the printed-form columns
                     divide the width and the table scrolls as one piece. --}}
                <colgroup>
                    <col class="blc-col--line">
                    <col class="blc-col--school">
                    <col class="blc-col--objective">
                    <col class="blc-col--activity">
                    <col class="blc-col--kind">
                    <col class="blc-col--quantity">
                    <col class="blc-col--unit">
                    <col class="blc-col--unit-cost">
                    <col class="blc-col--total">
                    <col class="blc-col--account-title">
                    <col class="blc-col--account-code">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col"><span class="sr-only">Line</span></th>
                        <th scope="col">Period</th>
                        <th scope="col">Objectives</th>
                        <th scope="col">Project/<wbr>Activity/<wbr>Expenditure</th>
                        <th scope="colgroup" colspan="4">Resources Needed</th>
                        <th scope="col" class="mem-num">Total Estimated Cost</th>
                        <th scope="col">Account Title</th>
                        <th scope="col">Account Code</th>
                    </tr>
                    <tr>
                        <th scope="col" aria-hidden="true"></th>
                        <th scope="col" aria-hidden="true"></th>
                        <th scope="col" aria-hidden="true"></th>
                        <th scope="col" aria-hidden="true"></th>
                        <th scope="col">Kind</th>
                        <th scope="col" class="mem-num">Quantity</th>
                        <th scope="col">Unit</th>
                        <th scope="col" class="mem-num">Unit Cost</th>
                        <th scope="col" aria-hidden="true"></th>
                        <th scope="col" aria-hidden="true"></th>
                        <th scope="col" aria-hidden="true"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php($objective = trim((string) ($row->objective ?? '')))
                        @php($activity = trim((string) ($row->activity_description ?? '')))
                        @php($resourceKind = trim((string) ($row->resource_kind ?? '')))
                        @php($quantity = $row->quantity !== null ? (string) (float) $row->quantity : '')
                        @php($unit = trim((string) ($row->unit ?? '')))
                        @php($unitCost = trim((string) ($row->unit_cost ?? '')))
                        @php($accountTitle = trim((string) ($row->account_title ?? '')))
                        @php($accountCode = trim((string) ($row->account_code ?? '')))
                        @php($funding = trim((string) ($row->funding_source ?? '')))
                        @php($org = trim((string) ($row->organizational_classification ?? '')))
                        @php($movs = trim((string) ($row->activity_movs ?? '')))
                        @php($period = config('mem.months')[$row->matrix_month] ?? $row->matrix_month)
                        @php($section = config('mem.sections')[$row->section] ?? ucfirst((string) $row->section))

                        <tr wire:key="budget-line-{{ $row->id }}">
                            <td class="mem-rownum">{{ $rows->firstItem() + $loop->index }}</td>

                            <td>
                                <span class="cell-strong">{{ $period }}</span>
                                <span class="cell-sub">CY {{ $row->matrix_year }} · {{ $section }}</span>
                                <span class="mem-status mem-status--{{ $row->matrix_status }}">
                                    {{ ucfirst((string) $row->matrix_status) }}
                                </span>
                            </td>

                            <td>
                                @if ($objective !== '')
                                    <span title="{{ $objective }}">{{ \Illuminate\Support\Str::limit($objective, 200) }}</span>
                                @else
                                    <span class="mem-list__muted">—</span>
                                @endif
                            </td>

                            <td>
                                <span class="cell-strong">{{ $activity !== '' ? $activity : '—' }}</span>

                                @if ($funding !== '' || $org !== '')
                                    <div class="mem-list__meta">
                                        @if ($funding !== '')
                                            <span class="mem-list__meta-item">Funding: {{ $funding }}</span>
                                        @endif
                                        @if ($org !== '')
                                            <span class="mem-list__meta-item">{{ $org }}</span>
                                        @endif
                                    </div>
                                @endif

                                @if ($movs !== '')
                                    <div class="mem-list__movs">MOVs: {{ \Illuminate\Support\Str::limit($movs, 100) }}</div>
                                @endif
                            </td>

                            <td>
                                @if ($resourceKind !== '')
                                    {{ $resourceKind }}
                                @else
                                    <span class="mem-list__muted">—</span>
                                @endif
                            </td>

                            <td class="mem-num">
                                @if ($quantity !== '')
                                    {{ $quantity }}
                                @else
                                    <span class="mem-list__muted">—</span>
                                @endif
                            </td>

                            <td>
                                @if ($unit !== '')
                                    {{ $unit }}
                                @else
                                    <span class="mem-list__muted">—</span>
                                @endif
                            </td>

                            <td class="mem-num">
                                @if ($unitCost !== '')
                                    {{ number_format((float) $unitCost, 2) }}
                                @else
                                    <span class="mem-list__muted">—</span>
                                @endif
                            </td>

                            <td class="mem-num mem-linetotal--cell">
                                {{ number_format(\App\Livewire\BudgetLineItemChecker::lineTotal($row), 2) }}
                            </td>

                            <td>
                                @if ($accountTitle !== '')
                                    <span class="cell-strong">{{ $accountTitle }}</span>
                                @else
                                    <span class="mem-list__muted">—</span>
                                @endif
                            </td>

                            <td>
                                @if ($accountCode !== '')
                                    <span class="ac-code">{{ $accountCode }}</span>
                                @else
                                    <span class="mem-list__muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="table-empty">
                                @if (trim($search) !== '' || $month !== '')
                                    No expenditure lines match the current filters.
                                    <button type="button" class="ac-import-btn" wire:click="clearFilters">
                                        Clear filters
                                    </button>
                                @else
                                    No expenditure lines have been entered for CY {{ $this->year }} yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="users-footer">
            <span class="users-live-indicator" wire:loading.delay wire:target="year,school,month,search,perPage" title="Updating…">
                <span class="users-live-dot"></span> Updating…
            </span>

            <span class="users-count">
                @if ($rows->total() > 0)
                    {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }}
                @else
                    0 lines
                @endif
            </span>

            {{ $rows->links() }}
        </div>

        <p class="allotment-footnote">
            Read-only. The School Head owns these lines in the Monthly Expenditure
            Matrix; the Budget office checks them here. Changing a line starts
            from the School Head’s own editor, so nothing on this page can alter a
            school’s plan.
        </p>
    </div>
    @endif
</div>
