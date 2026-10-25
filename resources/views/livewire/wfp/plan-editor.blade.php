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
            <a href="{{ route('wfp.matrices.index') }}" wire:navigate.hover>Monthly Expenditure Matrix</a>
            <span aria-hidden="true">›</span>
            <span>WFP {{ $plan->fiscalYearLabel() }}</span>
        </div>
        <h1>Work and Financial Plan — {{ $plan->fiscalYearLabel() }}</h1>
        <p>
            {{ $plan->school?->name ?? 'Your school' }} ·
            <span class="mem-status mem-status--{{ $plan->status }}">{{ ucfirst($plan->status) }}</span>
            · {{ $plan->items()->count() }} line(s) · {{ $this->flaggedCount }} to review
        </p>
    </div>

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
                <button type="button" class="ac-import-btn" wire:click="approve" wire:loading.attr="disabled" wire:target="approve">
                    Approve
                </button>
            @else
                {{-- Read-only (the school's Bookkeeper): saving, regenerating
                     and approving are the School Head's; the download follows
                     the same view gate as this page. --}}
                <span class="mem-actions__hint">
                    Read-only — the enrichment fields are the School Head’s to fill in.
                </span>
            @endif
        </div>

        <a
            href="{{ route('wfp.plans.export', ['plan' => $plan->id]) }}"
            class="btn-primary"
            download
        >
            <x-icon name="download" :size="16" />
            <span>Download {{ $templateName }}</span>
        </a>
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
