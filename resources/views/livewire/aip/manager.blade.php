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
        <h1>Annual Implementation Plan (AIP)</h1>
        <p>
            The school's one-year breakdown of its School Improvement Plan — the projects,
            objectives and activities the school commits to for the year.
        </p>
    </div>

    @if ($errorMessage)
        <div class="ac-import-error mem-banner" role="alert">
            {{ $errorMessage }}
            <button type="button" class="users-clear" wire:click="clearError" title="Dismiss">×</button>
        </div>
    @endif

    @unless ($this->hasSchool)
        <div class="card">
            <div class="table-wrap">
                <p class="table-empty">
                    Your account is not attached to a school yet, so it cannot own an AIP.
                    Ask an administrator to assign your school on the Users page.
                </p>
            </div>
        </div>
    @else
        <section class="card aip-start" aria-labelledby="aip-new-title">
            <div class="aip-start__intro">
                <span class="aip-start__icon" aria-hidden="true">
                    <x-icon name="file-spreadsheet" :size="20" />
                </span>

                <div class="aip-start__text">
                    <h2 class="card-title" id="aip-new-title">Start a plan</h2>
                    <p class="aip-start__copy">
                        One plan exists per school and year — starting a year that already has a
                        plan opens it instead of creating a second one.
                    </p>
                </div>
            </div>

            <form class="aip-start__form" wire:submit="create">
                <div class="aip-field aip-field--year">
                    <label for="aip-year">School year</label>
                    <input
                        id="aip-year"
                        type="number"
                        inputmode="numeric"
                        min="2000"
                        max="2100"
                        wire:model="year"
                        aria-label="School year"
                    >
                    @error('year') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="aip-field aip-field--grow">
                    <label for="aip-title">
                        Title
                        <span class="aip-field__optional">optional</span>
                    </label>
                    <input
                        id="aip-title"
                        type="text"
                        maxlength="255"
                        placeholder="Annual Implementation Plan — School Year {{ $year }}"
                        wire:model="title"
                        aria-label="Title"
                    >
                    @error('title') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <button type="submit" class="btn-primary aip-start__submit" wire:loading.attr="disabled" wire:target="create">
                    <x-icon name="file-text" :size="16" />
                    <span wire:loading.remove wire:target="create">Open plan</span>
                    <span wire:loading wire:target="create">Opening…</span>
                </button>
            </form>
        </section>
    @endunless

    <section class="card" aria-labelledby="aip-records-title">
            <div class="card-head">
                <h2 class="card-title" id="aip-records-title">AIP Records</h2>
                <p class="mem-toolbar-copy">{{ $this->plans->count() }} plan(s) on file.</p>
            </div>

            <div class="table-wrap">
                <table class="data-table aip-table">
                    <caption>Annual Implementation Plan records</caption>
                    <thead>
                        <tr>
                            <th scope="col">Plan</th>
                            <th scope="col">Projects</th>
                            <th scope="col">Activities</th>
                            <th scope="col">Budget</th>
                            <th scope="col">WFP</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="users-actions-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->plans as $plan)
                            <tr wire:key="aip-plan-{{ $plan->id }}">
                                <td>
                                    <span class="cell-strong">{{ $plan->label() }}</span>
                                    <div class="aip-sub">{{ $plan->fiscalYearLabel() }}</div>
                                </td>
                                <td>{{ $plan->projects_count }}</td>
                                <td>{{ $plan->activities_count }}</td>
                                <td class="mem-linetotal">{{ number_format($plan->annualBudgetTotal(), 2) }}</td>
                                <td>
                                    @if ($plan->wfpPlan)
                                        <a href="{{ route('wfp.plans.edit', ['plan' => $plan->wfpPlan->id]) }}" wire:navigate.hover>
                                            {{ $plan->wfpPlan->fiscalYearLabel() }}
                                        </a>
                                    @else
                                        <span class="aip-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $plan->statusBadgeClass() }}">
                                        {{ $plan->statusLabelFor(auth()->user()) }}
                                    </span>
                                    @if ($plan->review_remarks)
                                        <div class="aip-sub" title="{{ $plan->review_remarks }}">
                                            {{ \Illuminate\Support\Str::limit($plan->review_remarks, 60) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="users-actions-col">
                                    <a
                                        href="{{ route('aip.plans.edit', ['plan' => $plan->id]) }}"
                                        class="users-action"
                                        wire:navigate.hover
                                    >
                                        <span class="users-action-inner">
                                            <x-icon name="file-spreadsheet" :size="14" />
                                            <span>{{ $plan->isEditable() ? 'Open' : 'View' }}</span>
                                        </span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="table-empty" colspan="7">No AIP records yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
</div>
