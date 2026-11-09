{{-- PER LINE ITEM EXPENDITURE — the Budget office's copy of a school's annual
     allotment report, and the school Bookkeeper's copy of it too. It is the
     same printed form the School Head reads on /wfp/line-items; the Budget
     office reads every school, the Bookkeeper reads the schools on its
     assignment list, so a school picker replaces the SH's fixed school.

     The form itself is the shared partial, so this page cannot report the
     figures differently from the school's own copy. Everything is derived on
     every load; nothing here edits a school's plan.

     On the Bookkeeper's copy the report also carries its one action — the
     "Checked as to the Correctness of Account Titles and Codes" sign-off —
     confirmed first because it is the checker's own statement. --}}
<div class="wfp-allotment-component">

    {{-- SUCCESS ALERT: popped once after a stored check, then cleared. --}}
    @if ($successMessage)
        <div
            wire:key="wfp-check-alert-{{ md5($successMessage) }}"
            x-data
            x-init="
                if (window.smartAlert) {
                    smartAlert.success({{ \Illuminate\Support\Js::from($successMessage) }});
                }
                $wire.clearSuccess();
            "
            class="success-alert-sentinel"
            aria-hidden="true"
        ></div>
    @endif

    @if ($errorMessage)
        <div class="ac-import-error" role="alert">
            {{ $errorMessage }}
            <button type="button" class="users-clear" wire:click="clearError" title="Dismiss">×</button>
        </div>
    @endif

    <div class="page-header">
        <h1>Per Line Item Expenditure</h1>
        <p>
            @if ($this->scopedToAssignedSchools())
                Your assigned schools’ annual MOOE allotment, allocated per line
                item or object of expenditure. Choose a school to read the report
                its School Head reads.
            @else
                Every school’s annual MOOE allotment, allocated per line item or
                object of expenditure. Choose a school to read the report its
                School Head reads.
            @endif
            @if ($this->school)
                <span class="mem-chip">{{ $this->school->name }}</span>
            @endif
        </p>
    </div>

    @if (! $this->school)
        <div class="card">
            <div class="ac-import-error mem-banner mem-banner--notice" role="status">
                @if ($this->hasNoAssignedSchools())
                    Your account is not assigned to any school yet, so there is no
                    report to read here. Add the schools you are responsible for in
                    <strong>Update My Profile</strong> (the account menu), or ask an
                    administrator to assign them.
                @else
                    No schools are registered yet, so there is no expenditure to report.
                    Ask an administrator to add a school first.
                @endif
            </div>
        </div>
    @else
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
            'signatoryStatuses' => $signatoryStatuses,
            // Bookkeeper and Budget sign their own separate blocks.
            'canCheck' => $canCheck,
            'canCertify' => $this->canCertify(),
            'preparedBy' => $preparedBy,
            'schoolOptions' => $this->schoolOptions,
            'exportSignedUrl' => route('budget.per-line-item.export', [
                'school' => $this->school->id,
                'year' => $this->year,
                'signatures' => 'with',
            ]),
            'exportUnsignedUrl' => route('budget.per-line-item.export', [
                'school' => $this->school->id,
                'year' => $this->year,
                'signatures' => 'without',
            ]),
            // The Bookkeeper's route to the plan to sign; null for the Budget
            // office and while no plan has been generated.
            'planUrl' => $planUrl,
        ])

        {{-- CHECK CONFIRMATION: the check is a statement the Bookkeeper
             signs, so it is confirmed against the school and year on screen
             before it is recorded. Rendered only while it is open, and only
             for an account that may actually give it. --}}
        @if ($showCheckModal && $this->canCheck() && $this->school)
            <div
                class="modal-backdrop is-open"
                @click="self && $wire.closeCheckModal()"
                role="presentation"
            >
                <div
                    class="modal modal--confirmation"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="checkReportTitle"
                    @click.stop
                >
                    <div class="modal-head">
                        <h2 id="checkReportTitle">Check Account Titles and Codes</h2>
                        <button
                            type="button"
                            class="modal-close"
                            wire:click="closeCheckModal"
                            aria-label="Close"
                            title="Close"
                        >×</button>
                    </div>

                    <div class="modal-form">
                        <p class="delete-modal-description">
                            Check <strong>{{ $this->school->name }}</strong>’s CY {{ $this->year }}
                            report as to the correctness of its account titles and codes?
                        </p>

                        <p class="allotment-footnote">
                            The report rolls up {{ count($this->lineItems) }}
                            {{ count($this->lineItems) === 1 ? 'line item' : 'line items' }}
                            for the year.
                        </p>

                        <p class="allotment-footnote">
                            Signed as
                            <strong>{{ auth()->user()->name ?: auth()->user()->username }}</strong>,
                            {{ auth()->user()->position ?: 'Senior Bookkeeper' }}.
                            The report and its Excel export will name you as the checker.
                        </p>

                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn-ghost"
                                wire:click="closeCheckModal"
                                wire:loading.attr="disabled"
                                wire:target="check"
                            >Cancel</button>
                            <button
                                type="button"
                                class="btn-primary"
                                wire:click="check"
                                wire:loading.attr="disabled"
                                wire:target="check"
                            >
                                <span wire:loading.remove wire:target="check">
                                    <x-icon name="check" :size="16" /> Check report
                                </span>
                                <span wire:loading wire:target="check">Checking…</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif

    @if ($showCertificationModal && $this->canCertify() && $this->school)
        <div
            class="modal-backdrop is-open"
            @click.self="$wire.closeCertificationModal()"
            role="presentation"
        >
            <div
                class="modal modal--confirmation"
                role="dialog"
                aria-modal="true"
                aria-labelledby="certifyReportTitle"
                @click.stop
            >
                <div class="modal-head">
                    <h2 id="certifyReportTitle">Certify Availability of Allotment</h2>
                    <button
                        type="button"
                        class="modal-close"
                        wire:click="closeCertificationModal"
                        aria-label="Close"
                        title="Close"
                    >×</button>
                </div>

                <div class="modal-form">
                    <p class="delete-modal-description">
                        Certify that the allotment for <strong>{{ $this->school->name }}</strong>
                        is available for CY {{ $this->year }}?
                    </p>
                    <p class="allotment-footnote">
                        Signed as <strong>{{ auth()->user()->name ?: auth()->user()->username }}</strong>,
                        {{ auth()->user()->position ?: 'Budget Officer III' }}.
                        The report and its Excel export will name you as the certifying officer.
                    </p>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="btn-ghost"
                            wire:click="closeCertificationModal"
                            wire:loading.attr="disabled"
                            wire:target="certify"
                        >Cancel</button>
                        <button
                            type="button"
                            class="btn-primary"
                            wire:click="certify"
                            wire:loading.attr="disabled"
                            wire:target="certify"
                        >
                            <span wire:loading.remove wire:target="certify">
                                <x-icon name="check-check" :size="16" /> Sign certification
                            </span>
                            <span wire:loading wire:target="certify">Signing…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
