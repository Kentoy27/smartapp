<div class="aip-component">
    @if ($successMessage)
        <div
            wire:key="aip-alert-{{ md5($successMessage) }}"
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
            <a href="{{ route('aip.index') }}" wire:navigate.hover>Annual Implementation Plan</a>
            <span aria-hidden="true">›</span>
            <span>{{ $plan->fiscalYearLabel() }}</span>
        </div>
        <h1>{{ $plan->label() }}</h1>
        <p>
            {{ $plan->school?->name ?? 'Your school' }} ·
            <span class="badge {{ $plan->statusBadgeClass() }}">{{ $plan->statusLabelFor(auth()->user()) }}</span>
            · {{ count($projects) }} project(s) · {{ number_format($this->totals['grand'], 2) }} total budget
        </p>
    </div>

    @if ($errorMessage)
        <div class="ac-import-error mem-banner" role="alert">
            {{ $errorMessage }}
            <button type="button" class="users-clear" wire:click="clearError" title="Dismiss">×</button>
        </div>
    @endif

    @if ($plan->isReturned() && $plan->review_remarks)
        <div class="mem-banner mem-banner--warn" role="status">
            <strong>Returned for revision.</strong>
            {{ $plan->review_remarks }}
            @if ($plan->reviewer)
                <span class="aip-muted">— {{ $plan->reviewer->name }}</span>
            @endif
        </div>
    @endif

    @unless ($this->canEdit)
        <div class="mem-banner" role="status">
            This plan is {{ strtolower($plan->statusLabelFor(auth()->user())) }}, so its contents are read-only.
            @if ($plan->isApproved())
                Use <strong>Attach to MOV</strong> to file the approved copy as evidence, or <strong>Export</strong> to download it.
            @endif
        </div>
    @endunless

    <div class="card">
        <div class="card-head users-toolbar">
            <div class="aip-actions">
                @if ($this->canEdit)
                    <button type="button" class="btn-ghost" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">
                        <span wire:loading.remove wire:target="saveDraft">Save draft</span>
                        <span wire:loading wire:target="saveDraft">Saving…</span>
                    </button>

                    <button type="button" class="ac-import-btn" wire:click="submit" wire:loading.attr="disabled" wire:target="submit">
                        <x-icon name="send" :size="16" />
                        <span wire:loading.remove wire:target="submit">{{ $plan->isReturned() ? 'Resubmit for review' : 'Submit for review' }}</span>
                        <span wire:loading wire:target="submit">Submitting…</span>
                    </button>

                    <button type="button" class="ac-import-btn" wire:click="importFromWfp" wire:loading.attr="disabled" wire:target="importFromWfp">
                        <x-icon name="chart-column" :size="16" />
                        <span>Copy from WFP</span>
                    </button>
                @endif

                <button type="button" class="ac-import-btn" wire:click="openPreview">
                    <x-icon name="eye" :size="16" />
                    <span>Preview</span>
                </button>

                @if ($plan->isApproved())
                    <button type="button" class="ac-import-btn" wire:click="attachToMov" wire:loading.attr="disabled" wire:target="attachToMov">
                        <x-icon name="upload" :size="16" />
                        <span wire:loading.remove wire:target="attachToMov">Attach to MOV</span>
                        <span wire:loading wire:target="attachToMov">Attaching…</span>
                    </button>
                @endif
            </div>

            <div class="aip-actions">
                <a href="{{ route('aip.plans.export', ['plan' => $plan->id]) }}" class="ac-import-btn" download title="Export as Excel">
                    <x-icon name="file-spreadsheet" :size="16" />
                    <span>Excel</span>
                </a>
                <a href="{{ route('aip.plans.print', ['plan' => $plan->id]) }}" class="ac-import-btn" target="_blank" rel="noopener" title="Open the printable form (save as PDF from the browser)">
                    <x-icon name="file-text" :size="16" />
                    <span>PDF / Print</span>
                </a>
            </div>
        </div>

        @if ($plan->wfpPlan)
            <p class="mem-toolbar-copy aip-wfp-note">
                Linked to the
                <a href="{{ route('wfp.plans.edit', ['plan' => $plan->wfpPlan->id]) }}" wire:navigate.hover>Work and Financial Plan for {{ $plan->wfpPlan->fiscalYearLabel() }}</a>
                — use <strong>Copy from WFP</strong> to start from its lines.
            </p>
        @endif
    </div>

    <div class="card aip-title-card">
        <div class="aip-title-card__intro">
            <div class="aip-title-card__icon">
                <x-icon name="file-text" :size="18" />
            </div>
            <div class="aip-title-card__text">
                <h2 class="card-title">Plan title</h2>
                <p class="aip-title-card__copy">Set a clear, identifiable title for this Annual Implementation Plan.</p>
            </div>
        </div>
        <div class="field aip-title-field">
            <label for="aip-plan-title">Plan title</label>
            <input
                id="aip-plan-title"
                type="text"
                maxlength="255"
                placeholder="Annual Implementation Plan — {{ $plan->fiscalYearLabel() }}"
                wire:model="title"
                @disabled(! $this->canEdit)
            >
            @error('title') <div class="error-text">{{ $message }}</div> @enderror
        </div>
    </div>

    @error('projects')
        <div class="ac-import-error mem-banner" role="alert">{{ $message }}</div>
    @enderror

    @forelse ($projects as $pi => $project)
        <section class="card mem-group aip-project" wire:key="aip-project-{{ $project['_key'] }}">
            <div class="aip-project__head">
                <div class="aip-project__title-wrap">
                    <div class="aip-project__title-bar">
                        <span class="aip-index">Project {{ $pi + 1 }}</span>
                        <span class="mem-chip">Budget {{ number_format($this->totals['projects'][$pi] ?? 0, 2) }}</span>
                    </div>
                </div>

                <div class="aip-project__actions">
                    @if ($this->canEdit)
                        <button type="button" class="users-action" wire:click="addActivity('{{ $project['_key'] }}')" title="Add an activity to this project">
                            <span class="users-action-inner"><x-icon name="upload" :size="14" /><span>Add activity</span></span>
                        </button>
                        <button type="button" class="users-action" wire:click="duplicateProject('{{ $project['_key'] }}')" title="Duplicate this project">
                            <span class="users-action-inner"><x-icon name="copy" :size="14" /><span>Duplicate</span></span>
                        </button>
                        <button
                            type="button"
                            class="users-action users-action--danger"
                            wire:click="removeProject('{{ $project['_key'] }}')"
                            wire:confirm="Remove this project and all of its activities?"
                            title="Remove this project"
                        >
                            <span class="users-action-inner"><x-icon name="trash" :size="14" /><span>Remove</span></span>
                        </button>
                    @endif
                </div>
            </div>

            <div class="aip-project__fields">
                <div class="field">
                    <label>Project title</label>
                    <input
                        type="text"
                        maxlength="255"
                        placeholder="e.g. Improve Reading Proficiency"
                        wire:model="projects.{{ $pi }}.title"
                        @disabled(! $this->canEdit)
                    >
                    @error("projects.{$pi}.title") <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label>Project objective</label>
                    <textarea
                        rows="2"
                        maxlength="2000"
                        placeholder="What the project sets out to achieve"
                        wire:model="projects.{{ $pi }}.objective"
                        @disabled(! $this->canEdit)
                    ></textarea>
                    @error("projects.{$pi}.objective") <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label>Output for the year</label>
                    <textarea
                        rows="2"
                        maxlength="2000"
                        placeholder="The year's target / deliverable"
                        wire:model="projects.{{ $pi }}.output_for_year"
                        @disabled(! $this->canEdit)
                    ></textarea>
                    @error("projects.{$pi}.output_for_year") <div class="error-text">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table aip-grid">
                    <thead>
                        <tr>
                            <th scope="col">Activities</th>
                            <th scope="col">Person(s) Responsible</th>
                            <th scope="col">Schedule</th>
                            <th scope="col">Venue</th>
                            <th scope="col">Budget per Activity</th>
                            <th scope="col">Budget Source</th>
                            @if ($this->canEdit)
                                <th scope="col" class="users-actions-col">Row</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($project['activities'] as $ai => $activity)
                            <tr wire:key="aip-activity-{{ $activity['_key'] }}">
                                <td>
                                    <textarea rows="2" wire:model="projects.{{ $pi }}.activities.{{ $ai }}.activity" aria-label="Activity" @disabled(! $this->canEdit)></textarea>
                                    @error("projects.{$pi}.activities.{$ai}.activity") <div class="error-text">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="text" wire:model="projects.{{ $pi }}.activities.{{ $ai }}.person_responsible" aria-label="Person responsible" @disabled(! $this->canEdit)>
                                    @error("projects.{$pi}.activities.{$ai}.person_responsible") <div class="error-text">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="text" wire:model="projects.{{ $pi }}.activities.{{ $ai }}.schedule" aria-label="Schedule" @disabled(! $this->canEdit)>
                                    @error("projects.{$pi}.activities.{$ai}.schedule") <div class="error-text">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="text" wire:model="projects.{{ $pi }}.activities.{{ $ai }}.venue" aria-label="Venue" @disabled(! $this->canEdit)>
                                    @error("projects.{$pi}.activities.{$ai}.venue") <div class="error-text">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" wire:model="projects.{{ $pi }}.activities.{{ $ai }}.budget" aria-label="Budget per activity" @disabled(! $this->canEdit)>
                                    @error("projects.{$pi}.activities.{$ai}.budget") <div class="error-text">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="text" list="aip-budget-sources" wire:model="projects.{{ $pi }}.activities.{{ $ai }}.budget_source" aria-label="Budget source" @disabled(! $this->canEdit)>
                                    @error("projects.{$pi}.activities.{$ai}.budget_source") <div class="error-text">{{ $message }}</div> @enderror
                                </td>
                                @if ($this->canEdit)
                                    <td class="users-actions-col">
                                        <button type="button" class="users-action" wire:click="duplicateActivity('{{ $project['_key'] }}', '{{ $activity['_key'] }}')" title="Duplicate this activity">
                                            <span class="users-action-inner"><x-icon name="copy" :size="14" /><span>Copy</span></span>
                                        </button>
                                        <button
                                            type="button"
                                            class="users-action users-action--danger"
                                            wire:click="removeActivity('{{ $project['_key'] }}', '{{ $activity['_key'] }}')"
                                            title="Remove this activity"
                                        >
                                            <span class="users-action-inner"><x-icon name="trash" :size="14" /><span>Remove</span></span>
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td class="table-empty" colspan="{{ $this->canEdit ? 7 : 6 }}">
                                    No activities yet — add the first one below.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($this->canEdit)
                <div class="aip-project__footer">
                    <button type="button" class="ac-import-btn" wire:click="addActivity('{{ $project['_key'] }}')">
                        <x-icon name="plus" :size="16" />
                        <span>Add activity</span>
                    </button>
                    <span class="aip-project__subtotal">Project budget: {{ number_format($this->totals['projects'][$pi] ?? 0, 2) }}</span>
                </div>
            @endif
        </section>
    @empty
        <div class="card">
            <div class="table-wrap">
                <p class="table-empty">
                    No projects yet.
                    @if ($this->canEdit)
                        Add the first School Improvement Project below, or copy the lines from your WFP.
                    @endif
                </p>
            </div>
        </div>
    @endforelse

    @if ($this->canEdit)
        <div class="aip-addproject">
            <button type="button" class="btn-primary" wire:click="addProject">
                <x-icon name="file-text" :size="16" />
                <span>Add project</span>
            </button>
        </div>
    @endif

    @if (count($projects) > 0)
        <div class="mem-actions mem-actions--total">
            <span class="mem-total-label mem-total-label--grand">Total budget</span>
            <span class="mem-linetotal mem-linetotal--grand">{{ number_format($this->totals['grand'], 2) }}</span>
        </div>
    @endif

    <datalist id="aip-budget-sources">
        @foreach ($this->budgetSources as $source)
            <option value="{{ $source }}"></option>
        @endforeach
    </datalist>

    {{-- PREVIEW MODAL: the plan as the official form reads, before submitting. --}}
    @if ($showPreview)
        <div class="modal-backdrop is-open" wire:key="aip-preview">
            <div class="modal aip-preview-modal" role="dialog" aria-modal="true" aria-labelledby="aip-preview-title">
                <div class="modal-head">
                    <h2 id="aip-preview-title">Preview — {{ $plan->label() }}</h2>
                    <button type="button" class="modal-close" wire:click="closePreview" aria-label="Close preview">×</button>
                </div>

                <div class="modal-form aip-preview-body">
                    <p class="role-modal-description">
                        <strong>{{ $plan->school?->name ?? 'Your school' }}</strong> ·
                        School Year {{ $plan->fiscalYearLabel() }} ·
                        {{ count($projects) }} project(s) ·
                        {{ number_format($this->totals['grand'], 2) }} total
                    </p>

                    @forelse ($projects as $pi => $project)
                        <div class="aip-preview-project">
                            <h3>{{ $pi + 1 }}. {{ $project['title'] !== '' ? $project['title'] : '(untitled project)' }}</h3>
                            @if (trim((string) $project['objective']) !== '')
                                <p><strong>Objective:</strong> {{ $project['objective'] }}</p>
                            @endif
                            @if (trim((string) $project['output_for_year']) !== '')
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
                                        @forelse ($project['activities'] as $activity)
                                            <tr>
                                                <td>{{ $activity['activity'] }}</td>
                                                <td>{{ $activity['person_responsible'] ?: '—' }}</td>
                                                <td>
                                                    {{ $activity['schedule'] ?: '—' }}@if ($activity['venue']) · {{ $activity['venue'] }}@endif
                                                </td>
                                                <td class="mem-linetotal">{{ is_numeric($activity['budget']) ? number_format((float) $activity['budget'], 2) : '—' }}</td>
                                                <td>{{ $activity['budget_source'] ?: '—' }}</td>
                                            </tr>
                                        @empty
                                            <tr><td class="table-empty" colspan="5">No activities.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @empty
                        <p class="table-empty">Nothing to preview yet.</p>
                    @endforelse

                    <div class="modal-actions">
                        <a href="{{ route('aip.plans.print', ['plan' => $plan->id]) }}" class="btn-ghost" target="_blank" rel="noopener">
                            Open printable form
                        </a>
                        <button type="button" class="btn-primary" wire:click="closePreview">Close</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
