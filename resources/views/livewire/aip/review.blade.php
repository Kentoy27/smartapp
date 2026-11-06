<div class="aip-component" @unless ($showReview) wire:poll.visible.15s @endunless>
    @if ($successMessage)
        <div
            wire:key="aip-review-alert-{{ md5($successMessage) }}"
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
        <h1>Review AIP</h1>
        <p>Every school's submitted Annual Implementation Plan — approve it, or return it for revision with your remarks.</p>
    </div>

    <section class="card" aria-labelledby="aip-review-list-title">
        <div class="card-head">
            <h2 class="card-title" id="aip-review-list-title">Submissions</h2>
            <p class="mem-toolbar-copy">{{ $this->plans->total() }} plan(s) submitted.</p>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <caption>AIP submissions</caption>
                <thead>
                    <tr>
                        <th scope="col">School</th>
                        <th scope="col">Year</th>
                        <th scope="col">Objectives / Projects</th>
                        <th scope="col">PPAs / Activities</th>
                        <th scope="col">Budget</th>
                        <th scope="col">Submitted</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="users-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->plans as $plan)
                        <tr wire:key="aip-review-{{ $plan->id }}">
                            <td>
                                <span class="cell-strong">{{ $plan->school?->name ?? '—' }}</span>
                                <div class="aip-sub">{{ $plan->label() }}</div>
                            </td>
                            <td>{{ $plan->fiscalYearLabel() }}</td>
                            <td>{{ $plan->projectCount() }}</td>
                            <td>{{ $plan->activityCount() }}</td>
                            <td class="mem-linetotal">{{ number_format($plan->annualBudgetTotal(), 2) }}</td>
                            <td>{{ $plan->submitted_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $plan->statusBadgeClass() }}">{{ $plan->statusLabelFor(auth()->user()) }}</span>
                                @if ($plan->approvedBy)
                                    <div class="aip-sub">by {{ $plan->approvedBy->name }}</div>
                                @endif
                            </td>
                            <td class="users-actions-col">
                                <button type="button" class="users-action" wire:click="openReview({{ $plan->id }})">
                                    <span class="users-action-inner">
                                        <x-icon name="eye" :size="14" />
                                        <span>Review</span>
                                    </span>
                                </button>
                                @if ($plan->canBeDeletedBy(auth()->user()))
                                    <button
                                        type="button"
                                        class="users-action users-action--danger"
                                        x-data
                                        @click="
                                            window.smartAlert.confirmDelete({
                                                title: 'Delete this AIP submission?',
                                                text: 'This will permanently remove the submitted plan, its projects, activities, versions, and review history. You can not undo this action.',
                                                confirmButtonText: 'Yes, delete'
                                            }).then(r => { if (r.isConfirmed) $wire.deletePlan({{ $plan->id }}); });
                                        "
                                    >
                                        <span class="users-action-inner">
                                            <x-icon name="trash" :size="14" />
                                            <span>Delete</span>
                                        </span>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="table-empty" colspan="8">No AIPs have been submitted yet.</td>
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
        <div class="modal-backdrop is-open" wire:key="aip-review-modal-{{ $reviewPlan->id }}">
            <div class="modal aip-review-modal" role="dialog" aria-modal="true" aria-labelledby="aip-review-modal-title">
                <div class="modal-head">
                    <h2 id="aip-review-modal-title">{{ $reviewPlan->label() }}</h2>
                    <button type="button" class="modal-close" wire:click="closeReview" aria-label="Close review">×</button>
                </div>

                <div class="modal-form aip-review-body">
                    <p class="role-modal-description">
                        <strong>{{ $reviewPlan->school?->name ?? 'School' }}</strong> ·
                        {{ $reviewPlan->school?->district?->name ?? '(District)' }} ·
                        FY {{ $reviewPlan->year }} ·
                        <span class="badge {{ $reviewPlan->statusBadgeClass() }}">{{ $reviewPlan->statusLabelFor(auth()->user()) }}</span>
                    </p>

                    <div class="aip-version-bar">
                        <button
                            type="button"
                            class="users-action{{ $previewVersionId === null ? ' is-selected' : '' }}"
                            wire:click="showVersion(null)"
                        >
                            <span class="users-action-inner"><span>Current</span></span>
                        </button>

                        @foreach ($this->reviewVersions as $version)
                            <button
                                type="button"
                                class="users-action{{ $previewVersionId === $version->id ? ' is-selected' : '' }}"
                                wire:click="showVersion({{ $version->id }})"
                                title="{{ $version->submitted_at?->format('M j, Y g:i A') }}"
                            >
                                <span class="users-action-inner">
                                    <span>{{ $version->label() }}</span>
                                    <span class="aip-muted">({{ number_format($version->budgetTotal(), 2) }})</span>
                                </span>
                            </button>
                        @endforeach
                    </div>

                    @if ($this->previewVersion)
                        <p class="field-hint">
                            Showing archived {{ $this->previewVersion->label() }}
                            @if ($this->previewVersion->submittedBy) submitted by {{ $this->previewVersion->submittedBy->name }} @endif
                            on {{ $this->previewVersion->submitted_at?->format('M j, Y g:i A') ?? '—' }}.
                        </p>
                    @endif

                    <div class="aip-template-heading aip-template-heading--review">
                        <h3>FY {{ $reviewPlan->year }} ANNUAL IMPLEMENTATION PLAN</h3>
                        <p>{{ str_replace(':year', (string) $reviewPlan->year, config('aip.template.purpose')) }}</p>
                    </div>

                    <div class="aip-review-contents">
                        @php($contents = $this->reviewContents)
                        @if (isset($contents['form_data']))
                            @php($reviewRows = collect($contents['form_data']['rows'] ?? []))
                            @php($reviewPillars = collect(config('aip.pillars', []))->merge($reviewRows->pluck('pillar')->filter()->unique())->unique()->values())
                            <div class="table-wrap aip-official-form__scroll">
                                <table class="data-table aip-official-grid">
                                    <thead>
                                        <tr>
                                            <th scope="col">Strategic Objective (Based on SIP)</th>
                                            <th scope="col">Strategy</th>
                                            <th scope="col">Updated Programs, Projects, and Activities (PPAs)</th>
                                            <th scope="col">Resources / Materials Needed</th>
                                            <th scope="col">Budget (If the fund source is MOOE, type “c/o approved WFP”. If the fund source is not MOOE, indicate the amount.)</th>
                                            <th scope="col">Persons Responsible</th>
                                            <th scope="col">Indicative Timeline (Indicate the month(s) covered)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($reviewPillars as $pillar)
                                            <tr class="aip-review-pillar"><th scope="rowgroup" colspan="7">{{ $pillar }}</th></tr>
                                            @php($pillarRows = $reviewRows->filter(fn (array $row): bool => ($row['pillar'] ?? '') === $pillar))
                                            @forelse ($pillarRows as $row)
                                                <tr>
                                                    <td>{{ $row['strategic_objective'] ?: '—' }}</td>
                                                    <td>{{ $row['strategy'] ?: '—' }}</td>
                                                    <td>{{ $row['ppa'] ?: '—' }}</td>
                                                    <td>{{ $row['resources'] ?: '—' }}</td>
                                                    <td>{{ $row['budget'] ?: '—' }}</td>
                                                    <td>{{ $row['persons_responsible'] ?: '—' }}</td>
                                                    <td>{{ $row['timeline'] ?: '—' }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="7">&nbsp;</td></tr>
                                            @endforelse
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <p class="aip-template-note">Note: Rows may be added as needed.</p>
                            @include('aip.partials.signatures', ['plan' => $reviewPlan])
                        @else
                        @forelse ($contents['projects'] ?? [] as $pi => $project)
                            <div class="aip-preview-project">
                                <h3>{{ $pi + 1 }}. {{ $project['title'] ?? '(untitled project)' }}</h3>
                                @if (trim((string) ($project['objective'] ?? '')) !== '')
                                    <p><strong>Objective:</strong> {{ $project['objective'] }}</p>
                                @endif
                                @if (trim((string) ($project['output_for_year'] ?? '')) !== '')
                                    <p><strong>Output for the year:</strong> {{ $project['output_for_year'] }}</p>
                                @endif

                                <div class="table-wrap">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th scope="col">Activity</th>
                                                <th scope="col">Person(s)</th>
                                                <th scope="col">Schedule / Venue</th>
                                                <th scope="col">Budget</th>
                                                <th scope="col">Source</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse ($project['activities'] ?? [] as $activity)
                                                <tr>
                                                    <td>{{ $activity['activity'] ?? '' }}</td>
                                                    <td>{{ ($activity['person_responsible'] ?? '') !== '' ? $activity['person_responsible'] : '—' }}</td>
                                                    <td>
                                                        {{ ($activity['schedule'] ?? '') !== '' ? $activity['schedule'] : '—' }}@if (($activity['venue'] ?? '') !== '') · {{ $activity['venue'] }}@endif
                                                    </td>
                                                    <td class="mem-linetotal">{{ isset($activity['budget']) && $activity['budget'] !== null ? number_format((float) $activity['budget'], 2) : '—' }}</td>
                                                    <td>{{ ($activity['budget_source'] ?? '') !== '' ? $activity['budget_source'] : '—' }}</td>
                                                </tr>
                                            @empty
                                                <tr><td class="table-empty" colspan="5">No activities.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @empty
                            <p class="table-empty">This plan has no projects.</p>
                        @endforelse
                        @endif

                        <p class="aip-total-line">
                            <strong>Total budget:</strong>
                            {{ number_format((float) ($contents['budget_total'] ?? $reviewPlan->annualBudgetTotal()), 2) }}
                        </p>
                    </div>

                    @if ($this->reviewHistory->isNotEmpty())
                        <h3 class="aip-history-title">Review history</h3>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Reviewer</th>
                                        <th scope="col">Action</th>
                                        <th scope="col">Remarks</th>
                                        <th scope="col">When</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->reviewHistory as $entry)
                                        <tr wire:key="aip-history-{{ $entry->id }}">
                                            <td>{{ $entry->reviewer?->name ?? '—' }}</td>
                                            <td>
                                                {{ $entry->actionLabel() }}
                                                @if ($entry->transitionLabel())
                                                    <div class="aip-sub">{{ $entry->transitionLabel() }}</div>
                                                @endif
                                            </td>
                                            <td>{{ $entry->remarks ?: '—' }}</td>
                                            <td>{{ $entry->reviewed_at?->format('M j, Y g:i A') ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($reviewPlan->canBeReviewedBy(auth()->user()) && $reviewPlan->isAwaitingReview())
                        <div class="field aip-remarks-field">
                            <label for="aip-review-remarks">Remarks</label>
                            <textarea
                                id="aip-review-remarks"
                                rows="3"
                                maxlength="2000"
                                placeholder="Notes for the school (required when returning for revision)"
                                wire:model="reviewRemarks"
                            ></textarea>
                            @error('reviewRemarks') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="modal-actions">
                            <button
                                type="button"
                                class="btn-ghost"
                                wire:click="returnForRevision"
                                wire:loading.attr="disabled"
                                wire:target="returnForRevision"
                            >
                                Return for revision
                            </button>
                            <button
                                type="button"
                                class="btn-primary"
                                wire:click="approve"
                                wire:loading.attr="disabled"
                                wire:target="approve"
                            >
                                <span wire:loading.remove wire:target="approve">Approve AIP</span>
                                <span wire:loading wire:target="approve">Approving…</span>
                            </button>
                        </div>
                    @else
                        <p class="field-hint">
                            @if ($reviewPlan->isApproved())
                                This plan has received its final approval.
                            @elseif ($reviewPlan->status === \App\Models\AipPlan::STATUS_FORWARDED)
                                This plan has been forwarded to {{ $reviewPlan->assignedTo?->name ?? 'the next reviewer' }}.
                            @elseif ($reviewPlan->isReturned())
                                This plan is back with the school for revision.
                            @else
                                This plan is held by another reviewer, so it is read-only here.
                            @endif
                        </p>

                        <div class="modal-actions">
                            @if ($reviewPlan->isReturned() && auth()->user()->canReviewAllOpcrfSubmissions())
                                <a
                                    class="btn-ghost"
                                    href="{{ route('aip.plans.edit', ['plan' => $reviewPlan->id]) }}"
                                    wire:navigate
                                >Update returned AIP</a>
                            @endif
                            <button type="button" class="btn-primary" wire:click="closeReview">Close</button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
