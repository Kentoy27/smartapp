{{-- THE ANNUAL MOOE ALLOTMENT REPORT as a shared form — the MONTH / BUDGET
     column on the left, and the year's lines rolled up per account code with
     their share of the grand total on the right. Read by the School Head's own
     report (/wfp/line-items) and by the Budget office's per-school copy
     (/budget/per-line-item), so the two cannot describe the form differently.

     Everything here is derived from the matrices as they stand, so the report
     is never stale and there is nothing to generate. The figures are read-only:
     the Monthly Expenditure Matrix page is where they are entered.

     Expected variables:
       $school        ?School   the school the report covers
       $year          string    the planning year
       $years         array     the years the school has a matrix for
       $months        array     the MONTH / BUDGET rows
       $lineItems     array     the No. / Account Title / Code / Amount / % rows
       $grandTotal    float     the year's total
       $allocation    array     allotted / consumed / remaining / percent
       $certification ?array    the Budget office's certification
       $checking      ?array    the Bookkeeper's check, or null while unchecked
       $canCheck      bool      whether the viewer may give the check (Bookkeeper)
       $preparedBy    ?User     the school's School Head
       $schoolOptions null|Collection  schools for the Budget office's picker
       $exportUrl     ?string   the .xlsx download, or null when there is none --}}

{{-- THE ALLOTMENT BUDGET: what the Budget office set for this school head to
     consume this year, read against what the year's Monthly Expenditure
     Matrices have actually consumed. The matrices are the consumption;
     nothing is deducted here — the balance is derived on every load, like the
     report below it. Hidden on paper: the printed form that follows is the
     official document. --}}
<section
    class="allotment-summary{{ $allocation['is_over'] ? ' is-over' : '' }}"
    aria-label="Allotment budget for {{ $year }}"
>
    <div class="allotment-summary__item">
        <span class="allotment-summary__label">Allotted budget</span>
        <strong class="allotment-summary__value">
            {{ $allocation['allotted'] === null ? 'Not set' : number_format($allocation['allotted'], 2) }}
        </strong>
        <span class="allotment-summary__hint">
            @if ($certification)
                Certified by {{ $certification['name'] }}
            @else
                Set by the Budget office
            @endif
        </span>
    </div>

    <div class="allotment-summary__item">
        <span class="allotment-summary__label">Consumed by monthly expenditures</span>
        <strong class="allotment-summary__value">{{ number_format($allocation['consumed'], 2) }}</strong>
        <span class="allotment-summary__hint">The {{ $year }} matrices’ total</span>
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
        </p>
    @else
        <p class="allotment-summary__note">
            No allotment budget has been set for CY {{ $year }} yet.
            The Budget office sets it in School Allotments; the expenditures
            above are recorded either way.
        </p>
    @endif
</section>

<div class="card">
    <div class="card-head users-toolbar wfp-allotment-toolbar">
        @if ($schoolOptions !== null)
            {{-- The Budget office oversees every school, so the report opens on
                 one school at a time: the picker is the page's scope control,
                 not a filter. --}}
            <label class="users-per-page">
                <span>School</span>
                <select wire:model.live="schoolId" aria-label="Report school" @disabled($schoolOptions->isEmpty())>
                    @foreach ($schoolOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label class="users-per-page">
            <span>Year</span>
            <select wire:model.live="year" aria-label="Report year">
                @foreach ($years as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>

        <div class="ac-toolbar-actions">
            {{-- The report is a printed form, so printing it is the action:
                 the browser's own dialog is the one that puts the letterhead
                 on paper. --}}
            <button
                type="button"
                class="ac-import-btn"
                onclick="window.print()"
                title="Print this report"
            >
                <x-icon name="printer" :size="16" />
                <span>Print</span>
            </button>

            {{-- The same report as an .xlsx, with its columns auto-fitted to
                 the figures. A plain link, not a Livewire action: the target is
                 a file download, and there is no page to morph into. The year
                 the reader is on travels with it. --}}
            @if ($exportUrl)
                <a
                    class="ac-import-btn"
                    href="{{ $exportUrl }}"
                    title="Download this report as Excel (.xlsx)"
                >
                    <x-icon name="file-spreadsheet" :size="16" />
                    <span>Download Excel</span>
                </a>
            @endif
        </div>
    </div>

    <div class="wfp-allotment">

        {{-- LETTERHEAD: the DepEd heading block. The app stores the district
             and the school, not the region or the street address, so the
             division line falls back to a generic one — a form with no
             division line reads worse than a generic. --}}
        <div class="wfp-allotment-letterhead">
            <span>Republic of the Philippines</span>
            <span>Department of Education</span>
            <span>{{ $school?->district?->name ?: 'Schools Division Office' }}</span>
            <strong>{{ $school?->name ?: 'School' }}</strong>
            <h2>
                {{ $year }} ANNUAL MOOE ALLOTMENT ALLOCATED PER LINE ITEM
                OR OBJECT OF EXPENDITURE
            </h2>
        </div>

        <div class="wfp-allotment-grid">

            {{-- THE MONTH COLUMN: the calendar year, each month against the
                 total of its own matrix. A month with no matrix reads as zero —
                 the report is a year, and its gaps are part of what it says. --}}
            <table class="wfp-allotment-table wfp-allotment-table--months">
                <thead>
                    <tr>
                        <th scope="col">MONTH</th>
                        <th scope="col">BUDGET</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($months as $month)
                        <tr @class(['is-pending' => ! $month['has_matrix']])>
                            <th scope="row">{{ strtoupper($month['label']) }}</th>
                            <td class="wfp-allotment-num">{{ number_format($month['budget'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="is-total">
                        <th scope="row">TOTAL</th>
                        <td class="wfp-allotment-num">{{ number_format($grandTotal, 2) }}</td>
                    </tr>
                </tfoot>
            </table>

            {{-- THE LINE ITEMS: one row per account code for the whole year, in
                 code order, each with its share of the total. --}}
            <table class="wfp-allotment-table wfp-allotment-table--items">
                <thead>
                    <tr>
                        <th scope="col">No.</th>
                        <th scope="col">Account Title</th>
                        <th scope="col">Account Code</th>
                        <th scope="col">Total Amount</th>
                        <th scope="col">%</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lineItems as $index => $row)
                        <tr wire:key="wfp-allotment-{{ $row['code'] ?? $row['title'] }}">
                            <td class="wfp-allotment-num">{{ $index + 1 }}</td>
                            <td>{{ $row['title'] }}</td>
                            <td class="ac-code">{{ $row['code'] ?? '—' }}</td>
                            <td class="wfp-allotment-num">{{ number_format($row['total'], 2) }}</td>
                            <td class="wfp-allotment-num">{{ number_format($row['percent'], 2) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="wfp-allotment-empty">
                                No expenditure lines for {{ $year }} yet.
                                Add them in the Monthly Expenditure Matrix and they appear here.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="is-total">
                        <th scope="row" colspan="3">Grand Total</th>
                        <td class="wfp-allotment-num">{{ number_format($grandTotal, 2) }}</td>
                        <td class="wfp-allotment-num">{{ $grandTotal > 0 ? '100.00%' : '0.00%' }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- SIGNATORIES: the form's four blocks. The preparer is the school's
             School Head; the checker is the Bookkeeper account that checked
             this school and year's account titles and codes; the certifying
             officer is the Budget account that certified this school and
             year. The last two stay blank lines until their sign-offs exist.
             The Approved line is signed on paper — the SDS has no account. --}}
        <div class="wfp-allotment-signatures">
            <div class="wfp-allotment-signatory">
                <span class="wfp-allotment-signatory__label">Prepared by:</span>
                {{-- An account with no name of its own still signs with
                     something: the username it logs in with. A school with no
                     SH account yet leaves the line blank rather than signing
                     for someone who is not there. --}}
                <span class="wfp-allotment-signatory__name">{{ $preparedBy?->name ?: ($preparedBy?->username ?: '_________________') }}</span>
                <span class="wfp-allotment-signatory__role">{{ $preparedBy?->position ?: 'School Principal I' }}</span>
            </div>

            <div class="wfp-allotment-signatory">
                <span class="wfp-allotment-signatory__label">Checked as to the Correctness of Account Titles and Codes:</span>

                @if ($checking)
                    <span class="wfp-allotment-signatory__name">{{ $checking['name'] }}</span>
                    <span class="wfp-allotment-signatory__role">{{ $checking['position'] }}</span>
                    <span class="wfp-allotment-signatory__role">
                        Checked {{ $checking['checked_at']->format('M j, Y') }}
                    </span>
                @else
                    {{-- No check on record yet: the block keeps its blank
                         line, signed on paper as before. --}}
                    <span class="wfp-allotment-signatory__name">_________________</span>
                    <span class="wfp-allotment-signatory__role">Senior Bookkeeper</span>
                @endif

                {{-- The check is the Bookkeeper's own statement, so only
                     their copy is offered the action — the School Head reads
                     the status, they do not check their own lines. --}}
                @if ($canCheck)
                    <button
                        type="button"
                        class="allotment-certify"
                        wire:click="openCheckModal"
                        wire:loading.attr="disabled"
                        wire:target="openCheckModal"
                        title="{{ $checking
                            ? 'Check again — your name replaces the current check'
                            : 'Check this report as to the correctness of its account titles and codes' }}"
                    >
                        <x-icon name="check-check" :size="14" />
                        <span>{{ $checking ? 'Re-check' : 'Check report' }}</span>
                    </button>
                @endif
            </div>

            <div class="wfp-allotment-signatory">
                <span class="wfp-allotment-signatory__label">Certifying Availability of Allotment:</span>

                @if ($certification)
                    <span class="wfp-allotment-signatory__name">{{ $certification['name'] }}</span>
                    <span class="wfp-allotment-signatory__role">{{ $certification['position'] }}</span>
                    <span class="wfp-allotment-signatory__role">
                        Certified {{ $certification['certified_at']->format('M j, Y') }}
                    </span>
                @else
                    <span class="wfp-allotment-signatory__name">_________________</span>
                    <span class="wfp-allotment-signatory__role">Budget Officer III</span>
                @endif
            </div>

            <div class="wfp-allotment-signatory">
                <span class="wfp-allotment-signatory__label">Approved:</span>
                <span class="wfp-allotment-signatory__name">_________________</span>
                <span class="wfp-allotment-signatory__role">Schools Division Superintendent</span>
            </div>
        </div>
    </div>
</div>
