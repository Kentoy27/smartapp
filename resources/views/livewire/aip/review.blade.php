<div class="aip-component" @unless ($showReview) wire:poll.15s @endunless>
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
                        <th scope="col">Projects</th>
                        <th scope="col">Activities</th>
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
                            <td>{{ $plan->projects_count }}</td>
                            <td>{{ $plan->activities_count }}</td>
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
                                @if (auth()->user()?->hasAdminAccess())
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
                        School Year {{ $reviewPlan->fiscalYearLabel() }} ·
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

                    <div class="aip-review-contents">
                        @php($contents = $this->reviewContents)
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
                                This plan is approved.
                            @elseif ($reviewPlan->isReturned())
                                This plan is back with the school for revision.
                            @else
                                This plan is held by another reviewer, so it is read-only here.
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
