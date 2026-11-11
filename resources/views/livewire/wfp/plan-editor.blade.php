<div class="wfp-plan-component">
    @if ($successMessage)
        <div
            wire:key="wfp-alert-{{ md5($successMessage) }}"
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
        <div class="memory-breadcrumb">
            @if (auth()->user()?->canApproveWfp())
                <a href="{{ route('wfp.approvals.index') }}" wire:navigate.hover>WFP Final Approvals</a>
            @elseif (auth()->user()?->canCheckLineItems())
                {{-- Budget and Bookkeeper readers return to their checking pages. --}}
                <a href="{{ route('budget.per-line-item.index') }}" wire:navigate.hover>Per Line Item Expenditure</a>
            @else
                <a href="{{ route('wfp.matrices.index') }}" wire:navigate.hover>Monthly Expenditure Matrix</a>
            @endif
            <span aria-hidden="true">›</span>
            <span>WFP {{ $plan->fiscalYearLabel() }}</span>
        </div>
        <h1>Work and Financial Plan — {{ $plan->fiscalYearLabel() }}</h1>
        <p>
            {{ $plan->school?->name ?? 'Your school' }} ·
            <span class="mem-status mem-status--{{ $plan->status }}">{{ $plan->statusLabel() }}</span>
            · {{ $plan->items()->count() }} line(s) · {{ $this->flaggedCount }} to review
        </p>
        @if (auth()->user()?->canApproveWfp() && $plan->isPendingApproval())
            <a
                class="btn-primary"
                href="{{ route('wfp.approvals.index', ['plan' => $plan->id]) }}"
                wire:navigate
            >
                Review &amp; Sign WFP
            </a>
        @endif
    </div>

    @if ($plan->school_id)
        @livewire('wfp-signatory-actions', [
            'schoolId' => $plan->school_id,
            'year' => (int) $plan->year,
        ], key('wfp-plan-signoffs-'.$plan->id))
    @endif

    @if (! empty($this->signatoryStatuses))
        <section class="card wfp-signatory-statuses" aria-label="Signatory status for this WFP">
            <div class="card-head">
                <div class="card-title">Signatory Status</div>
            </div>

            <div class="wfp-signatory-statuses__list">
                @foreach ($this->signatoryStatuses as $status)
                    <div @class([
                        'wfp-signatory-status',
                        'wfp-signatory-status--'.($status['state'] ?? 'pending'),
                    ])>
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

    @if ($errorMessage)
        <div class="ac-import-error mem-banner" role="alert">
            {{ $errorMessage }}
            <button type="button" class="users-clear" wire:click="clearError" title="Dismiss">×</button>
        </div>
    @endif

    @if ($this->isOutOfDate)
        <div class="mem-banner mem-banner--warn" role="status">
            <strong>Out of date.</strong>
            A Monthly Expenditure Matrix has changed since this plan was generated:
            <ul class="mem-stale-list">
                @foreach ($this->staleSources as $source)
                    <li>
                        {{ $source['matrix']->periodLabel() }} — version {{ $source['was'] }} → {{ $source['now'] }}
                        ({{ $source['items'] }} line(s)). Regenerate to bring them across.
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- FINAL APPROVAL — Budget's certification and check are the gate; the
         Superadmin's decision is the final, read-only state. The panel shows
         only recorded facts: an approved plan without a stored actor is
         described as legacy, never given a fabricated signature. --}}
    <section class="card wfp-approval-panel">
        <div class="card-head">
            <div class="card-title">Final approval</div>
            <span class="mem-status mem-status--{{ $plan->status }}">{{ $plan->statusLabel() }}</span>
        </div>

        @if ($plan->isPendingApproval())
            <p class="card-text">
                Submitted by <strong>{{ $plan->submittedBy?->name ?? 'the School Head' }}</strong>
                @if ($plan->submitted_at)
                    on {{ $plan->submitted_at->format('M j, Y g:i A') }}
                @endif
                — waiting for the Schools Division Superintendent's decision.
            </p>
        @elseif ($plan->isApproved())
            @if ($this->finalApproval?->decidedBy)
                <p class="card-text">
                    Approved by <strong>{{ $this->finalApproval->decidedBy->name }}</strong>
                    on {{ $this->finalApproval->decided_at->format('M j, Y g:i A') }}.
                </p>
                @if (trim((string) $this->finalApproval->remarks) !== '')
                    <p class="card-text"><strong>Remarks:</strong> {{ $this->finalApproval->remarks }}</p>
                @endif
            @else
                <p class="card-text">
                    This WFP was finalized before final-approval records were kept.
                    No Schools Division Superintendent signature will be printed on its export.
                </p>
            @endif
        @elseif ($this->latestReturn)
            <p class="card-text">
                <strong>Returned for revision</strong> by
                {{ $this->latestReturn->decidedBy?->name ?? 'the Superadmin' }}
                on {{ $this->latestReturn->decided_at->format('M j, Y g:i A') }}.
            </p>
            <p class="card-text"><strong>Remarks:</strong> {{ $this->latestReturn->remarks }}</p>
        @else
            <p class="card-text">
                Budget must certify the allotment and check the account titles and codes.
                Once both are recorded, the School Head can submit this WFP for the
                Schools Division Superintendent's final approval.
            </p>
        @endif

        <ul class="wfp-approval-checklist">
            <li>
                <span>Allotment certification</span>
                @if ($this->budgetCertification)
                    <strong>Certified</strong> — {{ $this->budgetCertification['name'] }}
                    on {{ $this->budgetCertification['certified_at']->format('M j, Y') }}
                @else
                    <strong>Pending</strong>
                @endif
            </li>
            <li>
                <span>Account titles &amp; codes</span>
                @if ($this->budgetChecking)
                    <strong>Checked</strong> — {{ $this->budgetChecking['name'] }}
                    on {{ $this->budgetChecking['checked_at']->format('M j, Y') }}
                @else
                    <strong>Pending</strong>
                @endif
            </li>
        </ul>

        @if ($plan->isEditable() && ! $this->canSubmitForApproval)
            <p class="mem-actions__hint">
                @if (! $this->hasBudgetReview)
                    Submission is unavailable until Budget records both sign-offs.
                @elseif ($this->isOutOfDate)
                    Regenerate the plan from the current MEM before submitting.
                @else
                    Add at least one line before submitting.
                @endif
            </p>
        @endif
    </section>

    {{-- VALIDATION — the same report `php artisan wfp:validate` prints, so the
         SH sees the totals reconcile (and any unknown codes) without the CLI. --}}
    @php($validation = $this->validation)
    <details class="card mem-validation" @if (! $validation['passed']) open @endif>
        <summary class="mem-validation__summary">
            <span class="cell-strong">Validation</span>
            @if ($validation['passed'])
                <span class="badge">Totals reconcile</span>
            @else
                <span class="badge badge-muted">Issues found</span>
            @endif
            <span class="mem-validation__hint">{{ $validation['missing']['count'] }} row(s) to enrich</span>
        </summary>

        <div class="mem-validation__body">
            <p>
                Totals — matrix <strong>{{ number_format($validation['grand']['matrix'], 2) }}</strong>
                vs plan <strong>{{ number_format($validation['grand']['plan'], 2) }}</strong>:
                {{ $validation['grand']['matches'] ? 'match' : 'MISMATCH' }}.
            </p>

            @if ($validation['unknownCodes'] !== [])
                <p>
                    <strong>Account codes not on the master list:</strong>
                    @foreach ($validation['unknownCodes'] as $row)
                        <span class="ac-code">{{ $row['code'] }}</span> ({{ $row['count'] }})
                    @endforeach
                </p>
            @endif

            <p>
                Missing enrichment: {{ $validation['missing']['major_output'] }} without a Major Output,
                {{ $validation['missing']['performance_indicator'] }} without a Performance Indicator.
            </p>

            @if ($validation['nearDuplicates'] !== [])
                <p><strong>Near-duplicate activities (warn only):</strong></p>
                <ul class="mem-stale-list">
                    @foreach ($validation['nearDuplicates'] as $pair)
                        <li>
                            “{{ \Illuminate\Support\Str::limit($pair['a'], 60) }}”
                            ~ “{{ \Illuminate\Support\Str::limit($pair['b'], 60) }}”
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </details>

    <div class="mem-actions mem-actions--top">
        <div class="mem-actions__group">
            @if ($this->canEdit())
                <button type="button" class="btn-ghost" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save changes</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
                <button type="button" class="ac-import-btn" wire:click="regenerate" wire:loading.attr="disabled" wire:target="regenerate">
                    <span wire:loading.remove wire:target="regenerate">Regenerate from MEM</span>
                    <span wire:loading wire:target="regenerate">Regenerating…</span>
                </button>
                <button
                    type="button"
                    class="btn-primary"
                    wire:click="submitForApproval"
                    wire:confirm="Submit this WFP for the Schools Division Superintendent's final approval? It becomes read-only while it is pending."
                    wire:loading.attr="disabled"
                    wire:target="submitForApproval"
                    @disabled(! $this->canSubmitForApproval)
                >
                    <span wire:loading.remove wire:target="submitForApproval">Submit for final approval</span>
                    <span wire:loading wire:target="submitForApproval">Submitting…</span>
                </button>
            @else
                {{-- Read-only: the Bookkeeper, the Superadmin previewing a
                     plan, and the SH after submission all read the same page.
                     Saving, regenerating and submitting are the owning School
                     Head's, and only while the plan is editable. --}}
                <span class="mem-actions__hint">
                    @if (! $plan->isEditable())
                        Read-only — this WFP is {{ strtolower($plan->statusLabel()) }} and can no longer be edited.
                    @else
                        Read-only — the enrichment fields are the School Head’s to fill in.
                    @endif
                </span>
            @endif
        </div>

        @if (auth()->user()?->canViewWfpSignoffs())
            {{-- The signed copy uses only signers backed by existing WFP
                 records and their registered profile signatures. --}}
            <div
                class="mem-actions__group"
                x-data="{ missing: @js($this->missingSignatureLabels) }"
            >
                <a
                    href="{{ route('wfp.plans.export', ['plan' => $plan->id]) }}"
                    class="btn-primary"
                    x-on:click.prevent="if (missing.length === 0 || window.confirm('These sections have no applicable registered e-signature and will remain unsigned: ' + missing.join('; ') + '. Continue with the available signatures?')) window.location.href = $el.href"
                >
                    <x-icon name="download" :size="16" />
                    <span>Export with E-Signatures</span>
                </a>
                <a
                    href="{{ route('wfp.plans.export', ['plan' => $plan->id, 'signatures' => 'without']) }}"
                    class="btn-ghost"
                    download
                >
                    <x-icon name="download" :size="16" />
                    <span>Export without E-Signatures</span>
                </a>
            </div>
        @endif
    </div>

    @forelse ($this->groups as $group)
        <div class="card mem-group" wire:key="group-{{ $group['key'] }}">
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
                        <span class="mem-chip">{{ $group['account_title'] }}@if($group['account_code']) · {{ $group['account_code'] }}@endif</span>
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
                            @php($row = $items[$item->id] ?? [])
                            <tr wire:key="wfp-item-{{ $item->id }}">
                                <td>
                                    {{ $item->month ? \App\Models\MonthlyExpenditureMatrix::MONTH_ABBREVIATIONS[$item->month] ?? $item->month : '—' }}
                                </td>
                                <td class="mem-linetotal">
                                    {{ $item->physical_target !== null ? number_format((float) $item->physical_target, 2) : '—' }}
                                    @if ($item->physical_unit) <span class="mem-unit">{{ $item->physical_unit }}</span> @endif
                                </td>
                                <td class="mem-linetotal">{{ number_format((float) $item->financial_target, 2) }}</td>
                                <td>
                                    <textarea rows="2" wire:model="items.{{ $item->id }}.major_output" aria-label="Major output" @disabled(! $this->canEdit())></textarea>
                                    @error("items.{$item->id}.major_output") <div class="error-text">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <textarea rows="2" wire:model="items.{{ $item->id }}.performance_indicator" aria-label="Performance indicator" @disabled(! $this->canEdit())></textarea>
                                    @error("items.{$item->id}.performance_indicator") <div class="error-text">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="text" wire:model="items.{{ $item->id }}.funding_source" aria-label="Funding source" @disabled(! $this->canEdit())>
                                </td>
                                <td>
                                    <input type="text" wire:model="items.{{ $item->id }}.organizational_classification" aria-label="Organizational classification" @disabled(! $this->canEdit())>
                                </td>
                                <td>
                                    <textarea rows="2" wire:model="items.{{ $item->id }}.activity_movs" aria-label="Activity MOVs" @disabled(! $this->canEdit())></textarea>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card">
            <div class="table-wrap">
                <p class="table-empty">This plan has no lines yet — generate it from a Monthly Expenditure Matrix.</p>
            </div>
        </div>
    @endforelse

    @if ($plan->items()->count() > 0)
        <div class="mem-actions mem-actions--total">
            <span class="mem-total-label mem-total-label--grand">Annual financial total</span>
            <span class="mem-linetotal mem-linetotal--grand">{{ number_format($this->annualTotal, 2) }}</span>
        </div>
    @endif
</div>
