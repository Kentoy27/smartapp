{{-- THE ANNUAL MOOE ALLOTMENT REPORT — the school's year of Monthly
     Expenditure Matrices read as the printed form: the MONTH / BUDGET column
     on the left, and the year's lines rolled up per account code with their
     share of the grand total on the right.

     The form itself is the shared partial, so the School Head's copy and the
     Budget office's / Bookkeeper's per-school copy cannot drift apart.

     This is the SH's own copy: read-only, with no check action — the check
     is signed by the Bookkeeper on its division-wide copy
     (BudgetLineItemAllotment) and simply prints here like the certification. --}}
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

    @unless ($this->school)
        <div class="ac-import-error mem-banner mem-banner--notice" role="status">
            Your account has no school assigned, so there is no expenditure to report.
            Ask an administrator to set your school first.
        </div>
    @endunless

    @include('livewire.wfp.partials.allotment-report', [
        'school' => $this->school,
        'year' => $this->year,
        'years' => $this->years,
        'months' => $this->months,
        'lineItems' => $this->lineItems,
        'grandTotal' => $this->grandTotal,
        'allocation' => $this->allocation,
        'certification' => $this->certification,
        'checking' => $checking,
        'canCheck' => false,
        'preparedBy' => $preparedBy,
        'schoolOptions' => null,
        'exportUrl' => $this->school
            ? route('wfp.lineitems.export', ['year' => $this->year])
            : null,
    ])
</div>
