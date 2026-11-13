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
       $missingSignatureLabels array the sign-off blocks whose e-signature the
                                   signed export will not carry, each with the
                                   reason it is missing
       $canCheck      bool      whether the viewer may give the check (Bookkeeper)
       $canCertify    bool      whether the viewer may certify the allotment (Budget)
       $preparedBy    ?User     the school's School Head
       $schoolOptions null|Collection  the Budget office's picker menu — the
                                   roster narrowed by the component's
                                   `schoolSearch` box; null on the School
                                   Head's copy, where the picker is hidden
       $exportSignedUrl   ?string  the .xlsx download with e-signatures, or null
       $exportUnsignedUrl ?string  the .xlsx download without e-signatures, or null
       $planUrl       ?string   the Bookkeeper's route to the school's
                                   generated WFP — where its correctness block
                                   is signed; null on every other copy and
                                   while no plan has been generated

     The picker (Budget office's copy only) reads `$schoolId` — the school on
     screen, for the check mark — and drives the component's chooseSchool() /
     resetSchoolSearch() actions. --}}

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

@if (! empty($signatoryStatuses ?? []))
    <section class="card wfp-signatory-statuses" aria-label="Signatory status report">
        <div class="card-head">
            <div class="card-title">Signatory Status</div>
        </div>

        <div class="wfp-signatory-statuses__list">
            @foreach ($signatoryStatuses as $status)
                <div class="wfp-signatory-status">
                    <div class="wfp-signatory-status__header">
                        <span class="wfp-signatory-status__label">{{ $status['label'] }}</span>
                        <span @class([
                            'wfp-signatory-status__badge',
                            'wfp-signatory-status__badge--pending' => ($status['state'] ?? 'pending') === 'pending',
                            'wfp-signatory-status__badge--checked' => ($status['state'] ?? 'pending') === 'checked',
                            'wfp-signatory-status__badge--certified' => ($status['state'] ?? 'pending') === 'certified',
                            'wfp-signatory-status__badge--approved' => ($status['state'] ?? 'pending') === 'approved',
                            'wfp-signatory-status__badge--returned' => ($status['state'] ?? 'pending') === 'returned',
                        ])>{{ $status['badge'] }}</span>
                    </div>

                    @if (($status['state'] ?? 'pending') === 'pending')
                        <div class="wfp-signatory-status__meta">{{ $status['pending_text'] ?? 'Pending' }}</div>
                    @else
                        @if (! empty($status['name']))
                            <div class="wfp-signatory-status__meta">{{ $status['name'] }}</div>
                        @endif
                        @if (! empty($status['position']))
                            <div class="wfp-signatory-status__meta">{{ $status['position'] }}</div>
                        @endif
                        @if (! empty($status['date']))
                            <div class="wfp-signatory-status__meta">{{ $status['date']->format('M j, Y g:i A') }}</div>
                        @endif
                        @if (! empty($status['remarks']))
                            <div class="wfp-signatory-status__remarks"><strong>Remarks:</strong> {{ $status['remarks'] }}</div>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif

@if (! empty($missingSignatureLabels ?? []))
    {{-- THE E-SIGNATURES THE SIGNED EXPORT WILL NOT CARRY: a signature is
         stamped only when the account behind the sign-off still stands
         behind it AND holds a registered signature image. The page says which
         blocks those are before the download, so a workbook without
         signatures is never a surprise. --}}
    <p class="mem-banner mem-banner--warn wfp-allotment-export-note" role="status">
        These sections have no applicable registered e-signature and will
        remain unsigned:
        {{ implode('; ', $missingSignatureLabels) }}.
    </p>
@endif

<div class="card">
    <div class="card-head users-toolbar wfp-allotment-toolbar">
        @if ($schoolOptions !== null)
            {{-- The Budget office reads every school and the Bookkeeper its
                 assigned ones, so the report opens on one school at a time:
                 the picker is the page's scope control, not a filter, and it
                 offers only the schools the account may read. It is
                 searchable — a division's roster is longer than a plain
                 select shows comfortably — and typing only narrows the menu:
                 the report moves when a school is picked (chooseSchool()),
                 never on the text alone. The menu's open/closed state is a
                 class (like the matrix editor's combobox), so a Livewire
                 re-render never resurrects it mid-typing. --}}
            <div
                class="users-per-page wfp-school-picker"
                x-data="{ open: false }"
                @click.outside="if (open) { open = false; $wire.resetSchoolSearch() }"
            >
                <label for="wfpReportSchool">School</label>

                <div class="mem-combo">
                    <span class="mem-combo__search" aria-hidden="true">
                        <x-icon name="search" :size="15" />
                    </span>

                    <input
                        id="wfpReportSchool"
                        type="text"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls="wfpReportSchoolOptions"
                        :aria-expanded="String(open)"
                        wire:model.live.debounce.300ms="schoolSearch"
                        placeholder="Search school…"
                        @focus="open = true"
                        @input="open = true"
                        @keydown.escape="if (open) { open = false; $wire.resetSchoolSearch() }"
                    >

                    @if (trim($schoolSearch) !== '')
                        <button
                            type="button"
                            class="mem-combo__clear"
                            wire:click="$set('schoolSearch', '')"
                            aria-label="Clear school search"
                            title="Clear"
                        >
                            <x-icon name="x" :size="14" />
                        </button>
                    @endif

                    <div
                        id="wfpReportSchoolOptions"
                        class="mem-combo__menu"
                        role="listbox"
                        :class="open ? 'open' : ''"
                    >
                        @forelse ($schoolOptions as $option)
                            <button
                                type="button"
                                role="option"
                                class="mem-combo__option"
                                wire:key="wfp-school-opt-{{ $option->id }}"
                                wire:click="chooseSchool({{ $option->id }})"
                                @click="open = false"
                                aria-selected="{{ (string) $option->id === $schoolId ? 'true' : 'false' }}"
                            >
                                <span class="mem-combo__option-main">
                                    <span class="mem-combo__option-title">{{ $option->name }}</span>
                                    <span class="mem-combo__option-sub">
                                        {{ $option->district?->name ?? 'No district' }}@if ($option->school_id) · {{ $option->school_id }}@endif
                                    </span>
                                </span>

                                @if ((string) $option->id === $schoolId)
                                    <span class="mem-combo__option-meta" title="Report on screen">
                                        <x-icon name="check" :size="14" />
                                    </span>
                                @endif
                            </button>
                        @empty
                            <div class="mem-combo__empty">No schools match “{{ trim($schoolSearch) }}”.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <label class="users-per-page">
            <span>Year</span>
            <select wire:model.live="year" aria-label="Report year">
                @foreach ($years as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>

        <div
            class="ac-toolbar-actions"
            x-data="{ missing: @js($missingSignatureLabels ?? []) }"
        >
            {{-- The report is a printed form, so printing it is the action:
                 the browser's own dialog is the one that puts the letterhead
                 on paper. --}}
            {{-- <button
                type="button"
                class="ac-import-btn"
                onclick="window.print()"
                title="Print this report"
            >
                <x-icon name="printer" :size="16" />
                <span>Print</span>
            </button> --}}

            {{-- The same report as an .xlsx, with its columns auto-fitted to
                 the figures. Plain links, not Livewire actions: the target is
                 a file download, and there is no page to morph into. The year
                 the reader is on travels with each one. The signed copy stamps
                 the registered profile signatures of the workflow signers; the
                 unsigned copy keeps the printed form for wet signing. A
                 signature that cannot be stamped is announced before the
                 download, not discovered in the file. --}}
            @if ($exportSignedUrl)
                <a
                    class="ac-import-btn"
                    href="{{ $exportSignedUrl }}"
                    title="Download this report as Excel with the signers’ e-signatures"
                    x-on:click.prevent="if (missing.length === 0 || window.confirm('These sections have no applicable registered e-signature and will remain unsigned: ' + missing.join('; ') + '. Continue with the available signatures?')) window.location.href = $el.href"
                >
                    <x-icon name="file-spreadsheet" :size="16" />
                    <span>Export with E-Signatures</span>
                </a>
            @endif

            @if ($exportUnsignedUrl)
                <a
                    class="ac-import-btn"
                    href="{{ $exportUnsignedUrl }}"
                    title="Download this report as Excel without e-signatures, for wet signing"
                >
                    <x-icon name="file-spreadsheet" :size="16" />
                    <span>Export without E-Signatures</span>
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
                        <span>{{ $checking ? 'Re-sign check' : 'Sign check' }}</span>
                    </button>

                    {{-- The electronic sign-off is given on the plan page, so
                         the report offers the way there for the Bookkeeper. --}}
                    @if (($planUrl ?? null) !== null)
                        <a
                            class="allotment-certify"
                            href="{{ $planUrl }}"
                            wire:navigate.hover
                            title="Open the generated WFP to sign its correctness block"
                        >
                            <x-icon name="file-spreadsheet" :size="14" />
                            <span>Open WFP to sign</span>
                        </a>
                    @endif
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

                @if (($canCertify ?? false) && ! $certification)
                    <button
                        type="button"
                        class="allotment-certify"
                        wire:click="openCertificationModal"
                        wire:loading.attr="disabled"
                        wire:target="openCertificationModal"
                        title="Sign that this school's allotment is available"
                    >
                        <x-icon name="check-check" :size="14" />
                        <span>Sign certification</span>
                    </button>
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
