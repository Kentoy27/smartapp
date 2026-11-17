{{-- PSDS read-only monitoring views for schools in assigned districts. --}}
<div class="psds-monitoring-component">
    <div class="page-header psds-page-header">
        <div>
            @if ($mode === 'mov')
                <h1>MOV Monitoring</h1>
                <p>Monitor required MOV upload progress, review status, and evidence for schools in your assigned districts. This view is read-only.</p>
            @else
                <h1>School Submission Monitoring</h1>
                <p>The AIP, OPCRF, and WFP submission status of the schools in your assigned districts. This workspace is read-only.</p>
            @endif
        </div>

        <div class="psds-page-header__actions">
            @if ($mode === 'mov')
                <a class="users-action" href="{{ route('psds.monitoring') }}" wire:navigate>
                    <span class="users-action-inner"><x-icon name="eye" :size="14" /><span>Submission overview</span></span>
                </a>
            @elseif ($this->districts->isNotEmpty())
                <a class="users-action psds-export-link" href="{{ $this->exportUrl }}">
                    <span class="users-action-inner">
                        <x-icon name="download" :size="14" />
                        <span>Export CSV</span>
                    </span>
                </a>
            @endif
        </div>
    </div>

    @php($report = \App\Support\PsdsMonitoringReport::class)

    @if ($this->districts->isEmpty())
        <section class="card psds-empty-state" aria-labelledby="psds-no-districts-title">
            <span class="psds-empty-state__icon"><x-icon name="school" :size="28" /></span>
            <h2 id="psds-no-districts-title">No districts assigned yet</h2>
            <p>
                An administrator assigns the districts a PSDS monitors. Your
                dashboard will show each school's AIP, OPCRF, and WFP
                submissions as soon as one is assigned to you.
            </p>
        </section>
    @else
        @php($totalSchools = (int) $this->summary['schools'])

        <section class="stats-grid psds-stats" aria-label="Monitoring summary">
            <div class="stat-card psds-stat-card">
                <div class="stat-label">Districts</div>
                <div class="stat-value">{{ $this->districts->count() }}</div>
                <div class="stat-sub">
                    {{ $totalSchools }} {{ \Illuminate\Support\Str::plural('school', $totalSchools) }} in view
                </div>
            </div>

                @if ($mode === 'mov')
                    @php($movSummary = $this->movSummary)
                    <div class="stat-card psds-stat-card">
                        <div class="stat-label">Required MOVs uploaded</div>
                        <div class="stat-value">
                            {{ $movSummary['submitted'] }}
                            <span class="psds-stat-of">/ {{ $movSummary['required'] }}</span>
                        </div>
                        <div class="stat-sub">
                            {{ $movSummary['percent'] }}% uploaded across schools in view
                        </div>
                        <div class="psds-stat-meter" aria-hidden="true">
                            <span style="width: {{ $movSummary['percent'] }}%"></span>
                        </div>
                    </div>

                    <div class="stat-card psds-stat-card">
                        <div class="stat-label">MOVs needing revision</div>
                        <div class="stat-value">{{ $movSummary['returned'] }}</div>
                        <div class="stat-sub">Across {{ $movSummary['schools'] }} schools in view</div>
                    </div>
                @else
                    @foreach (['aip' => 'AIP', 'opcrf' => 'OPCRF', 'wfp' => 'WFP'] as $documentKey => $documentLabel)
                        @php($counts = $this->summary[$documentKey])
                        @php($percent = $totalSchools > 0 ? (int) round($counts[$report::DOC_SUBMITTED] / $totalSchools * 100) : 0)
                        <div class="stat-card psds-stat-card">
                            <div class="stat-label">{{ $documentLabel }}</div>
                            <div class="stat-value">
                                {{ $counts[$report::DOC_SUBMITTED] }}
                                <span class="psds-stat-of">/ {{ $totalSchools }}</span>
                            </div>
                            <div class="stat-sub">
                                {{ $counts[$report::DOC_NOT_SUBMITTED] }} not submitted
                                @if ($counts[$report::DOC_NEEDS_REVISION] > 0)
                                    · {{ $counts[$report::DOC_NEEDS_REVISION] }} need revision
                                @endif
                            </div>
                            <div class="psds-stat-meter" aria-hidden="true">
                                <span style="width: {{ $percent }}%"></span>
                            </div>
                        </div>
                    @endforeach

                    <div class="stat-card psds-stat-card">
                        <div class="stat-label">Complete schools</div>
                        <div class="stat-value">{{ $this->summary['overall'][$report::OVERALL_COMPLETE] }}</div>
                        <div class="stat-sub">All three documents submitted</div>
                    </div>
                @endif
        </section>

        <section class="card psds-filter-card" aria-label="Filter school submissions">
            <div class="psds-filters">
                <label class="psds-filter psds-filter--search">
                    <span>Search</span>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="School name or school ID"
                        autocomplete="off"
                    >
                </label>

                <label class="psds-filter">
                    <span>District</span>
                    <select wire:model.live="districtFilter">
                        <option value="">All assigned districts</option>
                        @foreach ($this->districts as $district)
                            <option value="{{ $district->id }}">{{ $district->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="psds-filter">
                    <span>School</span>
                    <select wire:model.live="schoolFilter">
                        <option value="">All schools</option>
                        @foreach ($this->schoolOptions as $school)
                            <option value="{{ $school->id }}">
                                {{ $school->name }}@if ($school->school_id) ({{ $school->school_id }})@endif
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="psds-filter">
                    <span>Reporting period</span>
                    <select wire:model.live="yearFilter">
                        <option value="">All years</option>
                        @foreach ($this->yearOptions as $year)
                            <option value="{{ $year }}">CY {{ $year }}</option>
                        @endforeach
                    </select>
                </label>

                @if ($mode === 'all')
                    <label class="psds-filter">
                        <span>AIP status</span>
                        <select wire:model.live="aipFilter">
                            <option value="">All AIP statuses</option>
                            @foreach ($report::docStatusOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="psds-filter">
                        <span>OPCRF status</span>
                        <select wire:model.live="opcrfFilter">
                            <option value="">All OPCRF statuses</option>
                            @foreach ($report::docStatusOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="psds-filter">
                        <span>WFP status</span>
                        <select wire:model.live="wfpFilter">
                            <option value="">All WFP statuses</option>
                            @foreach ($report::docStatusOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="psds-filter">
                        <span>Overall status</span>
                        <select wire:model.live="overallFilter">
                            <option value="">All overall statuses</option>
                            @foreach ($report::overallStatusOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>

            <div class="psds-filter-actions">
                <button
                    type="button"
                    class="users-action"
                    wire:click="resetFilters"
                    @disabled(! $this->hasActiveFilters)
                >
                    <span class="users-action-inner">Reset filters</span>
                </button>

                @if ($mode === 'all')
                    <a class="users-action" href="{{ $this->exportUrl }}">
                        <span class="users-action-inner">
                            <x-icon name="download" :size="14" />
                            <span>Export this view</span>
                        </span>
                    </a>
                @endif

                <span class="psds-filter-count">
                    {{ $this->paginatedRows->total() }}
                    {{ \Illuminate\Support\Str::plural('school', $this->paginatedRows->total()) }}
                </span>
            </div>
        </section>

        @if ($this->districts->sum('schools_count') === 0)
            <section class="card psds-empty-state" aria-labelledby="psds-no-schools-title">
                <span class="psds-empty-state__icon"><x-icon name="school" :size="28" /></span>
                <h2 id="psds-no-schools-title">No schools in your districts yet</h2>
                <p>
                    Your assigned districts do not have any schools on file.
                    Schools appear here as soon as an administrator adds them
                    to one of those districts.
                </p>
            </section>
        @else
            @if ($mode === 'all')
                <section class="card psds-coverage-card" aria-labelledby="psds-coverage-title">
                <div class="card-head">
                    <div>
                        <h2 class="card-title" id="psds-coverage-title">District coverage</h2>
                        <p class="mem-toolbar-copy">
                            Submitted documents across the schools in view
                            ({{ $totalSchools }} {{ \Illuminate\Support\Str::plural('school', $totalSchools) }}).
                        </p>
                    </div>
                </div>

                <div class="psds-coverage-grid">
                    @forelse ($this->coverage as $coverage)
                        <article class="psds-coverage-item">
                            <div class="psds-coverage-head">
                                <span class="cell-strong">{{ $coverage['district']->name }}</span>
                                <span class="psds-coverage-percent">{{ $coverage['percent'] }}%</span>
                            </div>
                            <div
                                class="psds-coverage-meter"
                                role="progressbar"
                                aria-label="{{ $coverage['district']->name }} document coverage"
                                aria-valuenow="{{ $coverage['percent'] }}"
                                aria-valuemin="0"
                                aria-valuemax="100"
                            >
                                <span style="width: {{ $coverage['percent'] }}%"></span>
                            </div>
                            <div class="psds-coverage-meta">
                                <span>{{ $coverage['schools'] }} {{ \Illuminate\Support\Str::plural('school', $coverage['schools']) }}</span>
                                <span>{{ $coverage['submitted'] }} / {{ $coverage['expected'] }} documents</span>
                                <span>{{ $coverage['complete'] }} complete</span>
                                @if ($coverage['needs_revision'] > 0)
                                    <span class="psds-coverage-warn">{{ $coverage['needs_revision'] }} need revision</span>
                                @endif
                            </div>
                        </article>
                    @empty
                        <p class="psds-detail-empty">No district matches this view.</p>
                    @endforelse
                </div>
                </section>
            @endif

            <section class="card" aria-labelledby="psds-schools-title">
                <div class="card-head">
                    <div>
                        <h2 class="card-title" id="psds-schools-title">Schools</h2>
                        <p class="mem-toolbar-copy">
                            @if ($mode === 'mov')
                                Required MOV upload progress for each school. Open a school to view its evidence and review remarks.
                            @else
                                One row per school. Open a school for its read-only submission history and MOV progress.
                            @endif
                        </p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data-table psds-table">
                        <caption>{{ $mode === 'mov' ? 'MOV progress by school' : 'Submission status by school' }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">District</th>
                                <th scope="col">School name</th>
                                <th scope="col">School ID</th>
                                @if ($mode === 'all')
                                    <th scope="col">AIP</th>
                                    <th scope="col">OPCRF</th>
                                    <th scope="col">WFP</th>
                                @endif
                                <th scope="col">MOV progress</th>
                                @if ($mode === 'all')
                                    <th scope="col">Overall</th>
                                @endif
                                <th scope="col" class="users-actions-col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->paginatedRows as $row)
                                <tr wire:key="psds-school-{{ $row['school_id'] }}">
                                    <td>{{ $row['district_name'] ?: '—' }}</td>
                                    <td>
                                        <span class="cell-strong">{{ $row['school_name'] }}</span>
                                    </td>
                                    <td>{{ $row['school_code'] !== '' ? $row['school_code'] : '—' }}</td>

                                    @if ($mode === 'all')
                                        @foreach (['aip', 'opcrf', 'wfp'] as $documentKey)
                                            @php($document = $row[$documentKey])
                                            <td>
                                                <span class="badge {{ $document['badge'] }}">{{ $document['label'] }}</span>
                                                @if ($document['detail_label'])
                                                    <span class="cell-sub">{{ $document['detail_label'] }}</span>
                                                @endif
                                                @if ($document['submitted_at'])
                                                    <span class="cell-sub">{{ $document['submitted_at']->format('M j, Y g:i A') }}</span>
                                                @else
                                                    <span class="cell-sub">
                                                        {{ $document['status'] === $report::DOC_NOT_SUBMITTED ? 'No record' : 'Not yet submitted' }}
                                                    </span>
                                                @endif
                                            </td>
                                        @endforeach
                                    @endif

                                    <td>
                                        <span class="cell-strong">{{ $row['mov']['submitted'] }} / {{ $row['mov']['total'] }}</span>
                                        <span class="cell-sub">{{ $row['mov']['percent'] }}% of required MOVs uploaded</span>
                                        @if ($row['mov']['returned'] > 0)
                                            <span class="cell-sub psds-coverage-warn">{{ $row['mov']['returned'] }} need revision</span>
                                        @endif
                                    </td>
                                    @if ($mode === 'all')
                                        <td>
                                            <span class="badge {{ $row['overall_badge'] }}">{{ $row['overall_label'] }}</span>
                                        </td>
                                    @endif
                                    <td class="users-actions-col">
                                        <button
                                            type="button"
                                            class="users-action"
                                            wire:click="openDetails({{ $row['school_id'] }})"
                                        >
                                            <span class="users-action-inner">
                                                <x-icon name="eye" :size="14" />
                                                <span>{{ $mode === 'mov' ? 'View MOVs' : 'View' }}</span>
                                            </span>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                                            <td class="table-empty" colspan="{{ $mode === 'mov' ? 5 : 9 }}">
                                        No schools match these filters. Clear a
                                        filter or search term to see the schools
                                        in your assigned districts.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($this->paginatedRows->hasPages())
                    <div class="users-footer">
                        {{ $this->paginatedRows->links() }}
                    </div>
                @endif
            </section>
        @endif
    @endif

    @php($detail = $this->detail)

    @if ($showDetail && $detail)
        <div class="modal-backdrop is-open" wire:key="psds-detail-{{ $detail['school']->id }}">
            <div
                class="modal modal--wide psds-detail-modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="psds-detail-title"
            >
                <div class="modal-head">
                    <div>
                        <h2 id="psds-detail-title">{{ $detail['school']->name }}</h2>
                        <p class="psds-detail-sub">
                            {{ $detail['school']->district?->name ?? '—' }}
                            · School ID {{ $detail['school']->school_id ?: '—' }}
                            · {{ $detail['year'] !== null ? 'CY '.$detail['year'] : 'All years' }}
                        </p>
                    </div>
                    <button type="button" class="modal-close" wire:click="closeDetails" aria-label="Close details">×</button>
                </div>

                <div class="modal-form psds-detail-body">
                    <div class="psds-detail-summary">
                        @if ($mode === 'all')
                            <div>
                                <span class="psds-detail-summary__label">Overall status</span>
                                <strong>
                                    <span class="badge {{ $detail['row']['overall_badge'] }}">
                                        {{ $detail['row']['overall_label'] }}
                                    </span>
                                </strong>
                            </div>
                        @else
                            <div>
                                <span class="psds-detail-summary__label">Required MOVs uploaded</span>
                                <strong>{{ $detail['mov']['stats']['submitted'] }} / {{ $detail['mov']['stats']['total'] }}</strong>
                            </div>
                        @endif
                        <div>
                            <span class="psds-detail-summary__label">District</span>
                            <strong>{{ $detail['school']->district?->name ?? '—' }}</strong>
                        </div>
                        <div>
                            <span class="psds-detail-summary__label">School ID</span>
                            <strong>{{ $detail['school']->school_id ?: '—' }}</strong>
                        </div>
                        @if ($mode === 'all')
                            <div>
                                <span class="psds-detail-summary__label">Reporting period</span>
                                <strong>{{ $detail['year'] !== null ? 'CY '.$detail['year'] : 'All years' }}</strong>
                            </div>
                        @endif
                    </div>

                    @if ($mode === 'all')
                    {{-- AIP --}}
                    <section class="psds-detail-section" aria-labelledby="psds-detail-aip-title">
                        <div class="psds-detail-section__head">
                            <h3 id="psds-detail-aip-title">Annual Implementation Plan (AIP)</h3>
                            <span class="badge {{ $detail['row']['aip']['badge'] }}">{{ $detail['row']['aip']['label'] }}</span>
                        </div>

                        @if ($detail['aip']['plan'])
                            @php($aipPlan = $detail['aip']['plan'])
                            <dl class="psds-detail-facts">
                                <div>
                                    <dt>Plan</dt>
                                    <dd>{{ $aipPlan->label() }}</dd>
                                </div>
                                <div>
                                    <dt>Planning year</dt>
                                    <dd>{{ $aipPlan->fiscalYearLabel() }}</dd>
                                </div>
                                <div>
                                    <dt>Workflow status</dt>
                                    <dd>{{ \App\Models\AipPlan::statusLabel((string) $aipPlan->status) }}</dd>
                                </div>
                                <div>
                                    <dt>Submitted</dt>
                                    <dd>{{ $aipPlan->submitted_at?->format('M j, Y g:i A') ?? 'Not submitted' }}</dd>
                                </div>
                                @if ($aipPlan->approved_at)
                                    <div>
                                        <dt>Approved</dt>
                                        <dd>{{ $aipPlan->approved_at->format('M j, Y g:i A') }}</dd>
                                    </div>
                                @endif
                            </dl>

                            @if ($detail['aip']['history']->isNotEmpty())
                                <div class="table-wrap">
                                    <table class="data-table psds-history-table">
                                        <caption>AIP review history</caption>
                                        <thead>
                                            <tr>
                                                <th scope="col">When</th>
                                                <th scope="col">Action</th>
                                                <th scope="col">Actor</th>
                                                <th scope="col">Transition</th>
                                                <th scope="col">Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($detail['aip']['history'] as $entry)
                                                <tr wire:key="psds-aip-history-{{ $loop->index }}">
                                                    <td>{{ $entry['when']?->format('M j, Y g:i A') ?? '—' }}</td>
                                                    <td>{{ $entry['action'] }}</td>
                                                    <td>{{ $entry['actor'] }}</td>
                                                    <td>{{ $entry['transition'] ?? '—' }}</td>
                                                    <td>{{ $entry['remarks'] ?: '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="psds-detail-empty">No review history recorded for this plan.</p>
                            @endif
                        @else
                            <p class="psds-detail-empty">No AIP on file for this reporting period.</p>
                        @endif
                    </section>

                    {{-- OPCRF --}}
                    <section class="psds-detail-section" aria-labelledby="psds-detail-opcrf-title">
                        <div class="psds-detail-section__head">
                            <h3 id="psds-detail-opcrf-title">Office Performance Commitment and Review Form (OPCRF)</h3>
                            <span class="badge {{ $detail['row']['opcrf']['badge'] }}">{{ $detail['row']['opcrf']['label'] }}</span>
                        </div>

                        @forelse ($detail['opcrf']['submissions'] as $submission)
                            @php($opcrfBadge = match ($submission['status_raw']) {
                                \App\Models\OpcrfSubmission::STATUS_RETURNED => 'badge--warn',
                                \App\Models\OpcrfSubmission::STATUS_APPROVED,
                                \App\Models\OpcrfSubmission::STATUS_FOR_COMPLIANCE => 'badge--ok',
                                default => 'badge--info',
                            })
                            <article class="psds-submission-block" wire:key="psds-opcrf-{{ $submission['reference'] }}">
                                <div class="psds-submission-block__head">
                                    <div>
                                        <span class="cell-strong">{{ $submission['reference'] ?: 'OPCRF submission' }}</span>
                                        <span class="cell-sub">{{ $submission['part'] }}</span>
                                    </div>
                                    <span class="badge {{ $opcrfBadge }}">{{ $submission['status'] }}</span>
                                </div>

                                <dl class="psds-detail-facts">
                                    <div>
                                        <dt>Submitted by</dt>
                                        <dd>{{ $submission['submitter'] }}</dd>
                                    </div>
                                    <div>
                                        <dt>Submitted</dt>
                                        <dd>{{ $submission['submitted_at']?->format('M j, Y g:i A') ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt>Routed to</dt>
                                        <dd>{{ $submission['reviewer'] }}</dd>
                                    </div>
                                </dl>

                                @if ($submission['history']->isNotEmpty())
                                    <div class="table-wrap">
                                        <table class="data-table psds-history-table">
                                            <caption>{{ $submission['reference'] }} review history</caption>
                                            <thead>
                                                <tr>
                                                    <th scope="col">When</th>
                                                    <th scope="col">Action</th>
                                                    <th scope="col">Actor</th>
                                                    <th scope="col">Transition</th>
                                                    <th scope="col">Remarks</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($submission['history'] as $entry)
                                                    <tr wire:key="psds-opcrf-history-{{ $submission['reference'] }}-{{ $loop->index }}">
                                                        <td>{{ $entry['when']?->format('M j, Y g:i A') ?? '—' }}</td>
                                                        <td>{{ $entry['action'] }}</td>
                                                        <td>{{ $entry['actor'] }}</td>
                                                        <td>{{ $entry['transition'] ?? '—' }}</td>
                                                        <td>{{ $entry['remarks'] ?: '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <p class="psds-detail-empty">No review history recorded for this submission.</p>
                                @endif
                            </article>
                        @empty
                            <p class="psds-detail-empty">No OPCRF on file for this reporting period.</p>
                        @endforelse
                    </section>

                    {{-- WFP --}}
                    <section class="psds-detail-section" aria-labelledby="psds-detail-wfp-title">
                        <div class="psds-detail-section__head">
                            <h3 id="psds-detail-wfp-title">Work and Financial Plan (WFP)</h3>
                            <span class="badge {{ $detail['row']['wfp']['badge'] }}">{{ $detail['row']['wfp']['label'] }}</span>
                        </div>

                        @if ($detail['wfp']['plan'])
                            @php($wfpPlan = $detail['wfp']['plan'])
                            <dl class="psds-detail-facts">
                                <div>
                                    <dt>Planning year</dt>
                                    <dd>{{ $wfpPlan->fiscalYearLabel() }}</dd>
                                </div>
                                <div>
                                    <dt>Workflow status</dt>
                                    <dd>{{ $wfpPlan->statusLabel() }}</dd>
                                </div>
                                <div>
                                    <dt>Submitted by</dt>
                                    <dd>{{ $wfpPlan->submittedBy?->name ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Submitted</dt>
                                    <dd>{{ $wfpPlan->submitted_at?->format('M j, Y g:i A') ?? 'Not submitted' }}</dd>
                                </div>
                                @if ($wfpPlan->approved_at)
                                    <div>
                                        <dt>Approved</dt>
                                        <dd>{{ $wfpPlan->approved_at->format('M j, Y g:i A') }}</dd>
                                    </div>
                                @endif
                            </dl>

                            @if ($detail['wfp']['approvals']->isNotEmpty())
                                <div class="table-wrap">
                                    <table class="data-table psds-history-table">
                                        <caption>WFP approval history</caption>
                                        <thead>
                                            <tr>
                                                <th scope="col">When</th>
                                                <th scope="col">Decision</th>
                                                <th scope="col">Actor</th>
                                                <th scope="col">Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($detail['wfp']['approvals'] as $entry)
                                                <tr wire:key="psds-wfp-history-{{ $loop->index }}">
                                                    <td>{{ $entry['when']?->format('M j, Y g:i A') ?? '—' }}</td>
                                                    <td>{{ $entry['decision'] }}</td>
                                                    <td>{{ $entry['actor'] }}</td>
                                                    <td>{{ $entry['remarks'] ?: '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="psds-detail-empty">No approval history recorded for this plan.</p>
                            @endif
                        @elseif ($detail['wfp']['uploads']->isEmpty())
                            <p class="psds-detail-empty">No WFP on file for this reporting period.</p>
                        @endif

                        @if ($detail['wfp']['uploads']->isNotEmpty())
                            <h4 class="psds-detail-subheading">Uploaded WFP workbooks</h4>
                            <div class="table-wrap">
                                <table class="data-table psds-history-table">
                                    <caption>Uploaded WFP workbooks</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Workbook</th>
                                            <th scope="col">Uploaded by</th>
                                            <th scope="col">Detected year</th>
                                            <th scope="col">Uploaded</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($detail['wfp']['uploads'] as $upload)
                                            <tr wire:key="psds-wfp-upload-{{ $upload->id }}">
                                                <td>{{ $upload->original_file_name }}</td>
                                                <td>{{ $upload->user?->name ?? '—' }}</td>
                                                <td>{{ $upload->school_year ?: '—' }}</td>
                                                <td>{{ $upload->uploaded_at?->format('M j, Y g:i A') ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </section>
                    @endif

                    {{-- MOV monitoring --}}
                    <section class="psds-detail-section" aria-labelledby="psds-detail-mov-title">
                        <div class="psds-detail-section__head">
                            <h3 id="psds-detail-mov-title">Means of Verification (MOV) progress</h3>
                            <span class="badge {{ $detail['mov']['stats']['returned'] > 0 ? 'badge--warn' : ($detail['mov']['stats']['percent'] === 100 ? 'badge--ok' : 'badge--info') }}">
                                {{ $detail['mov']['stats']['submitted'] }} / {{ $detail['mov']['stats']['total'] }} required uploaded
                            </span>
                        </div>

                        <div
                            class="psds-coverage-meter"
                            role="progressbar"
                            aria-label="Required MOV upload progress for {{ $detail['school']->name }}"
                            aria-valuenow="{{ $detail['mov']['stats']['percent'] }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        >
                            <span style="width: {{ $detail['mov']['stats']['percent'] }}%"></span>
                        </div>
                        <p class="mem-toolbar-copy">
                            {{ $detail['mov']['stats']['percent'] }}% uploaded
                            @if ($detail['mov']['stats']['returned'] > 0)
                                · {{ $detail['mov']['stats']['returned'] }} requirement(s) need revision
                            @endif
                        </p>

                        @if ($detail['mov']['parts']->isEmpty())
                            <p class="psds-detail-empty">No active MOV checklist requirements are configured.</p>
                        @else
                            @foreach ($detail['mov']['parts'] as $part)
                                <h4 class="psds-detail-subheading">{{ $part->name }}</h4>
                                <div class="table-wrap">
                                    <table class="data-table psds-history-table">
                                        <caption>{{ $part->name }} MOV checklist progress</caption>
                                        <thead>
                                            <tr>
                                                <th scope="col">Category</th>
                                                <th scope="col">Requirement</th>
                                                <th scope="col">Progress</th>
                                                <th scope="col">Evidence and review</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse ($part->categories as $category)
                                                @foreach ($category->requirements as $requirement)
                                                    @php($uploads = $requirement->userMovs)
                                                    @php($hasReturned = $uploads->contains('status', \App\Models\UserMov::STATUS_RETURNED))
                                                    @php($allAccepted = $uploads->isNotEmpty() && $uploads->every(fn ($upload) => $upload->status === \App\Models\UserMov::STATUS_ACCEPTED))
                                                    @php($underReview = $uploads->contains('status', \App\Models\UserMov::STATUS_UNDER_REVIEW))
                                                    @php($requirementStatus = $uploads->isEmpty() ? 'Not Submitted' : ($hasReturned ? 'Returned' : ($allAccepted ? 'Accepted' : ($underReview ? 'Under Review' : 'Uploaded'))))
                                                    @php($requirementBadge = $uploads->isEmpty() ? 'badge-muted' : ($hasReturned ? 'badge--warn' : ($allAccepted ? 'badge--ok' : ($underReview ? 'badge--info' : 'badge-muted'))))
                                                    <tr wire:key="psds-mov-{{ $requirement->id }}">
                                                        <td>{{ $category->name }}</td>
                                                        <td>
                                                            <span class="cell-strong">{{ $requirement->label() }}: {{ $requirement->title }}</span>
                                                            @unless ($requirement->is_required)
                                                                <span class="cell-sub">Optional</span>
                                                            @endunless
                                                        </td>
                                                        <td>
                                                            <span class="badge {{ $requirementBadge }}">{{ $requirementStatus }}</span>
                                                        </td>
                                                        <td>
                                                            @forelse ($uploads as $upload)
                                                                <div class="psds-mov-file">
                                                                    <span class="cell-strong">{{ $upload->original_name }}</span>
                                                                    <span class="badge {{ $upload->statusBadgeClass() }}">{{ $upload->statusLabel() }}</span>
                                                                    <span class="cell-sub">
                                                                        Uploaded {{ $upload->uploaded_at?->format('M j, Y g:i A') ?? '—' }}
                                                                        by {{ $upload->user?->name ?? 'School account' }}
                                                                        @if ($upload->reviewer)
                                                                            · Reviewed by {{ $upload->reviewer->name }}
                                                                        @endif
                                                                    </span>
                                                                    @if ($upload->remarks)
                                                                        <span class="cell-sub">Remarks: {{ $upload->remarks }}</span>
                                                                    @endif
                                                                    @if ($upload->fileExists())
                                                                        <span class="psds-mov-file__actions">
                                                                            <a class="btn-ghost btn-ghost--small" href="{{ route('mov.preview', $upload) }}" target="_blank" rel="noopener">
                                                                                <x-icon name="eye" :size="14" /><span>View</span>
                                                                            </a>
                                                                            <a class="btn-ghost btn-ghost--small" href="{{ route('mov.download', $upload) }}">
                                                                                <x-icon name="download" :size="14" /><span>Download</span>
                                                                            </a>
                                                                        </span>
                                                                    @else
                                                                        <span class="cell-sub">File unavailable</span>
                                                                    @endif
                                                                </div>
                                                            @empty
                                                                <span class="cell-sub">No evidence uploaded</span>
                                                            @endforelse
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            @empty
                                                <tr>
                                                    <td class="table-empty" colspan="4">No active requirements in this part.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @endforeach
                        @endif
                    </section>

                    <p class="psds-readonly-note">
                        <x-icon name="lock" :size="14" />
                        <span>
                            This is a read-only monitoring view. PSDS accounts
                            cannot upload, edit, submit, approve, return, or
                            delete a document, or manage an account.
                        </span>
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
