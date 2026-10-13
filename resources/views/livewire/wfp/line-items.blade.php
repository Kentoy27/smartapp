{{-- THE ANNUAL MOOE ALLOTMENT REPORT — the school's year of Monthly
     Expenditure Matrices read as the printed form: the MONTH / BUDGET column
     on the left, and the year's lines rolled up per account code with their
     share of the grand total on the right.

     Everything on the page is derived from the matrices as they stand, so the
     report is never stale and there is nothing to generate. The figures are
     read-only here — this page reports, the Monthly Expenditure Matrix page
     is where they are entered. --}}
<div class="wfp-allotment-component">

    <div class="page-header">
        <h1>Per Line Item Expenditure</h1>
        <p>
            Your school’s annual MOOE allotment, allocated per line item or object of expenditure.
            @if ($this->school)
                <span class="mem-chip">{{ $this->school->name }}</span>
            @endif
        </p>
    </div>

    <div class="card">
        <div class="card-head users-toolbar wfp-allotment-toolbar">
            <label class="users-per-page">
                <span>Year</span>
                <select wire:model.live="year" aria-label="Report year">
                    @foreach ($this->years as $option)
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

                {{-- The same report as an .xlsx, with its columns auto-fitted
                     to the figures. A plain link, not a Livewire action: the
                     target is a file download, and there is no page to morph
                     into. The year the reader is on travels with it. --}}
                @if ($this->school)
                    <a
                        class="ac-import-btn"
                        href="{{ route('wfp.lineitems.export', ['year' => $this->year]) }}"
                        title="Download this report as Excel (.xlsx)"
                    >
                        <x-icon name="file-spreadsheet" :size="16" />
                        <span>Download Excel</span>
                    </a>
                @endif
            </div>
        </div>

        @unless ($this->school)
            <div class="ac-import-error mem-banner mem-banner--notice" role="status">
                Your account has no school assigned, so there is no expenditure to report.
                Ask an administrator to set your school first.
            </div>
        @endunless

        <div class="wfp-allotment">

            {{-- LETTERHEAD: the DepEd heading block. The app stores the
                 district and the school, not the region or the street
                 address, so the division line falls back to a generic one —
                 a form with no division line reads worse than a generic. --}}
            <div class="wfp-allotment-letterhead">
                <span>Republic of the Philippines</span>
                <span>Department of Education</span>
                <span>{{ $this->school?->district?->name ?: 'Schools Division Office' }}</span>
                <strong>{{ $this->school?->name ?: 'School' }}</strong>
                <h2>
                    {{ $this->year }} ANNUAL MOOE ALLOTMENT ALLOCATED PER LINE ITEM
                    OR OBJECT OF EXPENDITURE
                </h2>
            </div>

            <div class="wfp-allotment-grid">

                {{-- THE MONTH COLUMN: the calendar year, each month against
                     the total of its own matrix. A month with no matrix reads
                     as zero — the report is a year, and its gaps are part of
                     what it says. --}}
                <table class="wfp-allotment-table wfp-allotment-table--months">
                    <thead>
                        <tr>
                            <th scope="col">MONTH</th>
                            <th scope="col">BUDGET</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->months as $month)
                            <tr @class(['is-pending' => ! $month['has_matrix']])>
                                <th scope="row">{{ strtoupper($month['label']) }}</th>
                                <td class="wfp-allotment-num">{{ number_format($month['budget'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="is-total">
                            <th scope="row">TOTAL</th>
                            <td class="wfp-allotment-num">{{ number_format($this->grandTotal, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>

                {{-- THE LINE ITEMS: one row per account code for the whole
                     year, in code order, each with its share of the total. --}}
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
                        @forelse ($this->lineItems as $index => $row)
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
                                    No expenditure lines for {{ $this->year }} yet.
                                    Add them in the Monthly Expenditure Matrix and they appear here.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="is-total">
                            <th scope="row" colspan="3">Grand Total</th>
                            <td class="wfp-allotment-num">{{ number_format($this->grandTotal, 2) }}</td>
                            <td class="wfp-allotment-num">{{ $this->grandTotal > 0 ? '100.00%' : '0.00%' }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- SIGNATORIES: the form's four blocks. Only the preparer is known
                 to the app (the signed-in School Head), so the rest are the
                 blank signature lines and captions the printed form carries. --}}
            <div class="wfp-allotment-signatures">
                <div class="wfp-allotment-signatory">
                    <span class="wfp-allotment-signatory__label">Prepared by:</span>
                    {{-- An account with no name of its own still signs with
                         something: the username it logs in with. --}}
                    <span class="wfp-allotment-signatory__name">{{ $preparedBy->name ?: $preparedBy->username }}</span>
                    <span class="wfp-allotment-signatory__role">{{ $preparedBy->position ?: 'School Principal I' }}</span>
                </div>

                <div class="wfp-allotment-signatory">
                    <span class="wfp-allotment-signatory__label">Checked as to the Correctness of Account Titles and Codes:</span>
                    <span class="wfp-allotment-signatory__name">_________________</span>
                    <span class="wfp-allotment-signatory__role">Senior Bookkeeper</span>
                </div>

                <div class="wfp-allotment-signatory">
                    <span class="wfp-allotment-signatory__label">Certifying Availability of Allotment:</span>
                    <span class="wfp-allotment-signatory__name">_________________</span>
                    <span class="wfp-allotment-signatory__role">Budget Officer III</span>
                </div>

                <div class="wfp-allotment-signatory">
                    <span class="wfp-allotment-signatory__label">Approved:</span>
                    <span class="wfp-allotment-signatory__name">_________________</span>
                    <span class="wfp-allotment-signatory__role">Schools Division Superintendent</span>
                </div>
            </div>
        </div>
    </div>
</div>
