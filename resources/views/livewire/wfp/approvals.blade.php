<div class="wfp-approvals-component" @unless ($showReview) wire:poll.15s @endunless>
    @if ($successMessage)
        <div
            wire:key="wfp-approval-alert-{{ md5($successMessage) }}"
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

    <div class="page-header">
        <h1>WFP Final Approvals</h1>
        <p>
            Budget-certified Work and Financial Plans submitted by the schools.
            Approving one gives it the Schools Division Superintendent's final sign-off;
            returning one sends it back for revision and Budget re-certification.
        </p>
    </div>

    @if ($errorMessage)
        <div class="ac-import-error mem-banner" role="alert">
            {{ $errorMessage }}
            <button type="button" class="users-clear" wire:click="clearError" title="Dismiss">×</button>
        </div>
    @endif

    <section class="card wfp-approval-filters-card" aria-label="Filter WFP approvals">
        <div class="wfp-approval-filters">
            <label class="wfp-approval-filter">
                <span>Search school</span>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="School name"
                    autocomplete="off"
                >
            </label>

            <label class="wfp-approval-filter">
                <span>School</span>
                <select wire:model.live="schoolFilter">
                    <option value="">All schools</option>
                    @foreach ($this->schools as $school)
                        <option value="{{ $school->id }}">{{ $school->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="wfp-approval-filter">
                <span>School level</span>
                <select wire:model.live="divisionFilter">
                    <option value="">All levels</option>
                    @foreach (\App\Support\SchoolDivision::labels() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="wfp-approval-filter">
                <span>Year</span>
                <select wire:model.live="yearFilter">
                    <option value="">All years</option>
                    @foreach ($this->years as $year)
                        <option value="{{ $year }}">CY {{ $year }}</option>
                    @endforeach
                </select>
            </label>

            <label class="wfp-approval-filter">
                <span>Status</span>
                <select wire:model.live="statusFilter">
                    @foreach ($this->statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </section>

    <section class="card" aria-labelledby="wfp-approvals-list-title">
        <div class="card-head">
            <h2 class="card-title" id="wfp-approvals-list-title">Submitted WFPs</h2>
            <p class="mem-toolbar-copy">{{ $this->plans->total() }} plan(s).</p>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <caption>WFP final-approval queue</caption>
                <thead>
                    <tr>
                        <th scope="col">School</th>
                        <th scope="col">Level</th>
                        <th scope="col">Year</th>
                        <th scope="col">Submitted</th>
                        <th scope="col">Status</th>
                        <th scope="col">Final decision</th>
                        <th scope="col" class="users-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->plans as $plan)
                        <tr wire:key="wfp-approval-{{ $plan->id }}">
                            <td><span class="cell-strong">{{ $plan->school?->name ?? '—' }}</span></td>
                            <td>{{ \App\Support\SchoolDivision::label($plan->schoolDivision()) }}</td>
                            <td>{{ $plan->fiscalYearLabel() }}</td>
                            <td>
                                @if ($plan->submitted_at)
                                    {{ $plan->submitted_at->format('M j, Y g:i A') }}
                                    <div class="aip-sub">by {{ $plan->submittedBy?->name ?? '—' }}</div>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                <span class="mem-status mem-status--{{ $plan->status }}">{{ $plan->statusLabel() }}</span>
                                @php($planSignatories = \App\Support\WfpAllotment::signatoryStatuses(
                                    $plan->school_id !== null ? (int) $plan->school_id : null,
                                    (int) $plan->year,
                                    $plan->schoolDivision(),
                                    $plan,
                                ))
                                <div class="wfp-approval-signoff-list">
                                    @foreach ($planSignatories as $signatory)
                                        <span class="wfp-signatory-status__badge">{{ $signatory['label'] }}: {{ $signatory['badge'] }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                @if ($plan->isApproved())
                                    @if ($plan->finalApproval?->decidedBy)
                                        <span class="cell-strong">{{ $plan->finalApproval->decidedBy->name }}</span>
                                        <div class="aip-sub">{{ $plan->finalApproval->decided_at?->format('M j, Y g:i A') }}</div>
                                    @else
                                        <span class="field-hint">Legacy finalization — no approval record</span>
                                    @endif
                                @elseif ($plan->isReturned())
                                    @if ($plan->latestReturn?->decidedBy)
                                        <span class="cell-strong">{{ $plan->latestReturn->decidedBy->name }}</span>
                                        <div class="aip-sub">{{ $plan->latestReturn->decided_at?->format('M j, Y g:i A') }}</div>
                                    @else
                                        <span class="field-hint">Returned for revision</span>
                                    @endif
                                @else
                                    <span class="field-hint">Awaiting decision</span>
                                @endif
                            </td>
                            <td class="users-actions-col">
                                @if ($plan->isPendingApproval())
                                    <button
                                        type="button"
                                        class="users-action"
                                        wire:click="approveFromQueue({{ $plan->id }})"
                                        wire:confirm="Sign and approve {{ $plan->school?->name ?? 'this school' }}’s {{ $plan->fiscalYearLabel() }} WFP? This final sign-off cannot be undone."
                                        wire:loading.attr="disabled"
                                        wire:target="approveFromQueue"
                                    >
                                        <span class="users-action-inner">
                                            <x-icon name="check-check" :size="14" />
                                            <span>Sign &amp; approve</span>
                                        </span>
                                    </button>
                                @endif
                                <button type="button" class="users-action" wire:click="openReview({{ $plan->id }})">
                                    <span class="users-action-inner">
                                        <x-icon name="eye" :size="14" />
                                        <span>{{ $plan->isPendingApproval() ? 'Review & Sign' : 'Review' }}</span>
                                    </span>
                                </button>
                                <a
                                    class="users-action"
                                    href="{{ route('wfp.plans.edit', ['plan' => $plan->id]) }}"
                                    wire:navigate
                                >
                                    <span class="users-action-inner">Open</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="table-empty" colspan="7">
                                No submitted WFPs match these filters. Schools appear here after a School Head submits a plan for final approval; this queue is not a school directory, and Superadmin accounts are not assigned to a single school.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->plans->hasPages())
            <div class="users-footer">
                {{ $this->plans->links() }}
            </div>
        @endif
    </section>

    @php($reviewPlan = $this->reviewPlan)

    @if ($showReview && $reviewPlan)
        <div class="modal-backdrop is-open" wire:key="wfp-approval-modal-{{ $reviewPlan->id }}">
            <div class="modal wfp-approval-modal" role="dialog" aria-modal="true" aria-labelledby="wfp-approval-modal-title">
                <div class="modal-head">
                    <h2 id="wfp-approval-modal-title">
                        {{ $reviewPlan->school?->name ?? 'School' }} — {{ $reviewPlan->fiscalYearLabel() }}
                    </h2>
                    <button type="button" class="modal-close" wire:click="closeReview" aria-label="Close review">×</button>
                </div>

                <div class="modal-form wfp-approval-body">
                    <p class="role-modal-description">
                        <strong>{{ $reviewPlan->school?->name ?? 'School' }}</strong> ·
                        {{ \App\Support\SchoolDivision::label($reviewPlan->schoolDivision()) }} ·
                        {{ $reviewPlan->fiscalYearLabel() }} ·
                        <span class="mem-status mem-status--{{ $reviewPlan->status }}">{{ $reviewPlan->statusLabel() }}</span>
                    </p>

                    @if ($errorMessage)
                        <div class="ac-import-error mem-banner" role="alert">
                            {{ $errorMessage }}
                            <button type="button" class="users-clear" wire:click="clearError" title="Dismiss">×</button>
                        </div>
                    @endif

                    <div class="wfp-approval-summary">
                        <div>
                            <span class="wfp-approval-summary__label">Allotment certification</span>
                            @if ($this->reviewBudgetCertification)
                                <strong>Certified</strong> — {{ $this->reviewBudgetCertification['name'] }}
                                on {{ $this->reviewBudgetCertification['certified_at']->format('M j, Y') }}
                            @else
                                <strong>Pending</strong>
                            @endif
                        </div>
                        <div>
                            <span class="wfp-approval-summary__label">Account titles &amp; codes</span>
                            @if ($this->reviewBudgetChecking)
                                <strong>Checked</strong> — {{ $this->reviewBudgetChecking['name'] }}
                                on {{ $this->reviewBudgetChecking['checked_at']->format('M j, Y') }}
                            @else
                                <strong>Pending</strong>
                            @endif
                        </div>
                        <div>
                            <span class="wfp-approval-summary__label">Submitted</span>
                            @if ($reviewPlan->submitted_at)
                                <strong>{{ $reviewPlan->submittedBy?->name ?? 'School Head' }}</strong>
                                on {{ $reviewPlan->submitted_at->format('M j, Y g:i A') }}
                            @else
                                <strong>Not submitted</strong>
                            @endif
                        </div>
                    </div>

                    @livewire('wfp-signatory-actions', [
                        'schoolId' => $reviewPlan->school_id,
                        'year' => (int) $reviewPlan->year,
                    ], key('wfp-approval-signoffs-'.$reviewPlan->id))

                    @if ($this->reviewOutOfDate)
                        <div class="mem-banner mem-banner--warn" role="status">
                            <strong>Out of date.</strong>
                            A Monthly Expenditure Matrix has changed since this plan was generated.
                            It cannot be approved until the School Head regenerates it and Budget reviews it again.
                        </div>
                    @elseif (! $this->reviewHasBudgetReview)
                        <div class="mem-banner mem-banner--warn" role="status">
                            <strong>Budget review is not current.</strong>
                            The plan can no longer be approved; return it for revision so Budget can certify it again.
                        </div>
                    @endif

                    <h3 class="aip-history-title">WFP details</h3>

                    @forelse ($this->reviewGroups as $group)
                        <div class="wfp-approval-group" wire:key="wfp-approval-group-{{ $group['key'] }}">
                            <div class="mem-group__head">
                                <div class="mem-group__title">
                                    <span class="mem-group__section">{{ ucfirst($group['section'] ?? '') }}</span>
                                    <span class="cell-strong">{{ $group['program_activity'] ?: '—' }}</span>
                                </div>
                                <div class="mem-group__meta">
                                    @if ($group['objective'])
                                        <span class="mem-chip">Objective: {{ \Illuminate\Support\Str::limit($group['objective'], 90) }}</span>
                                    @endif
                                    @if ($group['account_title'])
                                        <span class="mem-chip">
                                            {{ $group['account_title'] }}@if ($group['account_code']) · {{ $group['account_code'] }}@endif
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="table-wrap">
                                <table class="data-table mem-plan-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Month</th>
                                            <th scope="col">Physical Target</th>
                                            <th scope="col">Financial Target</th>
                                            <th scope="col">Major Output</th>
                                            <th scope="col">Performance Indicator</th>
                                            <th scope="col">Funding Source</th>
                                            <th scope="col">Org. Classification</th>
                                            <th scope="col">Activity MOVs</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($group['items'] as $item)
                                            <tr>
                                                <td>
                                                    {{ $item->month ? \App\Models\MonthlyExpenditureMatrix::MONTH_ABBREVIATIONS[$item->month] ?? $item->month : '—' }}
                                                </td>
                                                <td class="mem-linetotal">
                                                    {{ $item->physical_target !== null ? number_format((float) $item->physical_target, 2) : '—' }}
                                                    @if ($item->physical_unit) <span class="mem-unit">{{ $item->physical_unit }}</span> @endif
                                                </td>
                                                <td class="mem-linetotal">{{ number_format((float) $item->financial_target, 2) }}</td>
                                                <td>{{ $item->major_output ?: '—' }}</td>
                                                <td>{{ $item->performance_indicator ?: '—' }}</td>
                                                <td>{{ $item->funding_source ?: '—' }}</td>
                                                <td>{{ $item->organizational_classification ?: '—' }}</td>
                                                <td>{{ $item->activity_movs ?: '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @empty
                        <p class="table-empty">This plan has no lines.</p>
                    @endforelse

                    @if ($reviewPlan->items()->count() > 0)
                        <p class="aip-total-line">
                            <strong>Annual financial total:</strong>
                            {{ number_format($reviewPlan->annualFinancialTotal(), 2) }}
                        </p>
                    @endif

                    <h3 class="aip-history-title">Approval history</h3>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th scope="col">Who</th>
                                    <th scope="col">Decision</th>
                                    <th scope="col">Remarks</th>
                                    <th scope="col">When</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if ($reviewPlan->submitted_at)
                                    <tr>
                                        <td>{{ $reviewPlan->submittedBy?->name ?? '—' }}</td>
                                        <td>Submitted for final approval</td>
                                        <td>—</td>
                                        <td>{{ $reviewPlan->submitted_at->format('M j, Y g:i A') }}</td>
                                    </tr>
                                @endif

                                @forelse ($reviewPlan->approvals as $entry)
                                    <tr wire:key="wfp-approval-history-{{ $entry->id }}">
                                        <td>{{ $entry->decidedBy?->name ?? '—' }}</td>
                                        <td>{{ $entry->decisionLabel() }}</td>
                                        <td>{{ $entry->remarks ?: '—' }}</td>
                                        <td>{{ $entry->decided_at?->format('M j, Y g:i A') ?? '—' }}</td>
                                    </tr>
                                @empty
                                    @unless ($reviewPlan->submitted_at)
                                        <tr>
                                            <td class="table-empty" colspan="4">No decisions recorded yet.</td>
                                        </tr>
                                    @endunless
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="wfp-approval-links">
                        <a
                            class="users-action"
                            href="{{ route('wfp.plans.export', ['plan' => $reviewPlan->id]) }}"
                            download
                        >
                            <span class="users-action-inner">
                                <x-icon name="download" :size="14" />
                                <span>Download official WFP</span>
                            </span>
                        </a>
                        <a
                            class="users-action"
                            href="{{ route('wfp.plans.edit', ['plan' => $reviewPlan->id]) }}"
                            wire:navigate
                        >
                            <span class="users-action-inner">Open full plan</span>
                        </a>
                    </div>

                    @if ($this->canDecideReview)
                        <div class="field aip-remarks-field">
                            <label for="wfp-approval-remarks">Remarks</label>
                            <textarea
                                id="wfp-approval-remarks"
                                rows="3"
                                maxlength="2000"
                                placeholder="Required when returning for revision; optional when approving."
                                wire:model="remarks"
                            ></textarea>
                            @error('remarks') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn-ghost"
                                wire:click="returnForRevision"
                                wire:loading.attr="disabled"
                                wire:target="returnForRevision"
                            >
                                <span wire:loading.remove wire:target="returnForRevision">Return for revision</span>
                                <span wire:loading wire:target="returnForRevision">Returning…</span>
                            </button>

                            @if ($this->canApproveReview)
                                <button
                                    type="button"
                                    class="btn-primary"
                                    wire:click="approve"
                                    wire:confirm="Sign and approve this WFP? This final sign-off cannot be undone."
                                    wire:loading.attr="disabled"
                                    wire:target="approve"
                                >
                                    <span wire:loading.remove wire:target="approve">Sign &amp; approve WFP</span>
                                    <span wire:loading wire:target="approve">Signing…</span>
                                </button>
                            @else
                                <button
                                    type="button"
                                    class="btn-primary"
                                    disabled
                                    title="The plan is out of date or Budget review is no longer current. Return it for revision instead."
                                >
                                    Sign &amp; approve WFP
                                </button>
                            @endif
                        </div>

                        @unless ($this->canApproveReview)
                            <p class="field-hint">
                                Approval is unavailable until the plan is current and Budget's two sign-offs are recorded.
                                Return it for revision instead.
                            </p>
                        @endunless
                    @else
                        <p class="field-hint">
                            @if ($reviewPlan->isApproved())
                                This WFP is approved and read-only.
                            @elseif ($reviewPlan->isReturned())
                                This WFP is back with the school for revision.
                            @else
                                This WFP is not awaiting a decision.
                            @endif
                        </p>

                        <div class="modal-actions">
                            <button type="button" class="btn-primary" wire:click="closeReview">Close</button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
