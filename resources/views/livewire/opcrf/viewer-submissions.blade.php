<div class="opcrf-viewer-submissions" wire:poll.15s>
    <div class="card">
        <div class="card-head">
            <div class="card-title">Submitted OPCRFs from School Heads</div>
            <span class="cell-sub">{{ $this->submissions->total() }} submissions</span>
        </div>

        <div class="card-head users-toolbar">
            <div class="users-search">
                <input
                    type="text"
                    placeholder="Search School Head, school, or district…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Search School Head OPCRF submissions"
                >

                @if (trim($search) !== '')
                    <button type="button" class="users-clear" wire:click="clearSearch" title="Clear search">×</button>
                @endif
            </div>
        </div>

        @if ($this->submissions->isEmpty())
            <p class="card-text">
                {{ trim($search) !== ''
                    ? 'No School Head OPCRF submissions match your search.'
                    : 'No School Head OPCRFs have been submitted yet.' }}
            </p>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Submitted</th>
                            <th scope="col">School Head</th>
                            <th scope="col">School</th>
                            <th scope="col">District</th>
                            <th scope="col">Form</th>
                            <th scope="col">Review Period</th>
                            <th scope="col">Self Rating</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="users-actions-col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->submissions as $submission)
                            <tr wire:key="viewer-opcrf-{{ $submission->id }}">
                                <td>
                                    <span class="cell-strong">{{ $submission->submitted_at?->format('M j, Y g:i A') ?? '—' }}</span>
                                </td>
                                <td>
                                    <span class="cell-strong">{{ $submission->realNameLabel() ?: ($submission->user?->name ?: '—') }}</span>
                                    <span class="cell-sub">{{ $submission->user?->username ?? '—' }}</span>
                                </td>
                                <td>{{ $submission->user?->school?->name ?? 'Not assigned' }}</td>
                                <td>{{ $submission->user?->school?->district?->displayName() ?? '—' }}</td>
                                <td>{{ $submission->partLabel() }}</td>
                                <td>{{ $submission->review_period !== '' ? $submission->review_period : '—' }}</td>
                                <td>
                                    <span class="badge">{{ $submission->self_rating > 0 ? number_format($submission->self_rating, 2) : '—' }}</span>
                                </td>
                                <td>
                                    <span class="badge {{ $submission->statusBadgeClass() }}">
                                        {{ $submission->statusLabelFor(auth()->user()) }}
                                    </span>
                                </td>
                                <td class="users-actions-col">
                                    @if ($submission->hasFile())
                                        <a
                                            class="btn-ghost"
                                            href="#"
                                            wire:click.prevent="openSubmission({{ $submission->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openSubmission"
                                            title="View the submitted OPCRF"
                                        >
                                            <x-icon name="eye" :size="14" />
                                            <span>View OPCRF</span>
                                        </a>
                                    @else
                                        <span class="cell-sub">Workbook unavailable</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $this->submissions->links() }}
        @endif
    </div>

    @if ($this->viewingSubmission)
        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.closeSubmission()"
            @click.self="$wire.closeSubmission()"
            role="presentation"
        >
            <div class="modal modal--opcrf-review modal--opcrf-sheet" role="dialog" aria-modal="true" aria-labelledby="viewerOpcrfTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="viewerOpcrfTitle">Submitted OPCRF — {{ $this->viewingSubmission->user?->school?->name ?? 'School not assigned' }}</h2>
                    <button type="button" class="modal-close" wire:click="closeSubmission" aria-label="Close" title="Close">×</button>
                </div>

                <p class="opcrf-review-note">
                    Submitted by <strong>{{ $this->viewingSubmission->realNameLabel() ?: ($this->viewingSubmission->user?->name ?? '—') }}</strong>
                    @if ($this->viewingSubmission->user?->school?->district)
                        — {{ $this->viewingSubmission->user->school->district->displayName() }}
                    @endif
                    · {{ $this->viewingSubmission->submitted_at?->format('M j, Y g:i A') ?? '—' }}
                    · {{ $this->viewingSubmission->statusLabelFor(auth()->user()) }}
                </p>

                <div class="modal-form opcrf-review-body">
                    <div class="opcrf-review-summary opcrf-review-summary--sheet">
                        <div class="opcrf-review-sheetblock">
                            <div class="opcrf-review-card-head">
                                <span>Submitted form</span>
                            </div>

                            @if ($this->viewingSheet !== null)
                                @include('livewire.opcrf.partials.opcrf-excelwin', [
                                    'sheet' => $this->viewingSheet,
                                    'rating' => (string) $this->viewingSubmission->self_rating,
                                    'full' => false,
                                    'rateeSignature' => $this->viewingSubmission->rateeSignature(),
                                    'reviewSignatures' => [],
                                    'reviewerRemarks' => $this->viewingSubmission->reviewer_remarks ?? [],
                                    'canEditObjectiveRemarks' => false,
                                    'workbook' => $this->viewingWorkbook ?? ['tabs' => [], 'part_two' => ['sections' => [], 'total_rows' => [], 'signers' => [], 'scale' => ['title' => '', 'levels' => []]], 'part_three' => ['components' => [], 'agreement' => [], 'overall' => null, 'rating' => '', 'rating_table' => [], 'signatures' => []], 'part_four' => ['office_plan' => [], 'office_feedback' => '', 'development_plan' => [], 'development_feedback' => '', 'signers' => [], 'sections' => []], 'extra_sheets' => []],
                                    'uid' => 'viewer',
                                ])
                            @else
                                <p class="card-text">The submitted workbook is unavailable or cannot be displayed.</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
