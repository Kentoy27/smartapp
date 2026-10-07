<div class="opcrf-movs-component" wire:poll.15s>

    {{-- SUCCESS ALERT: popped once after a resubmission, then cleared so
         later renders never re-fire it. --}}
    @if ($successMessage)
        <div
            wire:key="alert-{{ md5($successMessage) }}"
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

    {{-- ACTION REQUIRED: a returned submission sits at the top of the
         table — the reviewer's remarks front and center, with the
         Revise & Resubmit entry point. --}}
    @php($returned = $this->submissions->firstWhere('status', \App\Models\OpcrfSubmission::STATUS_RETURNED))
    @if ($returned !== null)
        <div class="card opcrf-action-required" wire:key="action-required-{{ $returned->id }}">
            <div class="opcrf-action-required-head">
                <span class="opcrf-action-required-icon" aria-hidden="true">
                    <x-icon name="undo-2" :size="16" />
                </span>
                <span class="opcrf-action-required-title">Action Required — Revision Needed</span>
                <span class="badge badge--warn">{{ $returned->statusLabelFor(auth()->user()) }}</span>
            </div>

            <p class="opcrf-action-required-text">
                <strong>Your OPCRF has been returned for revision.</strong>
                Please review the Superadmin's remarks, make the necessary
                corrections, and resubmit your OPCRF.
            </p>

            <div class="opcrf-action-required-remarks">
                <div class="opcrf-action-required-remarks-label">
                    Superadmin Remarks @if ($returned->latestReview?->reviewer)
                        — {{ $returned->latestReview->reviewer->username }}@endif
                </div>
                <p class="opcrf-action-required-remarks-text">
                    {{ $returned->latestReview?->remarks !== null && trim((string) $returned->latestReview?->remarks) !== ''
                        ? $returned->latestReview->remarks
                        : 'No remarks were provided — please review your submission and correct any missing or inconsistent details.' }}
                </p>
            </div>

            <div class="opcrf-action-required-foot">
                <span class="opcrf-review-owner">
                    Returned {{ $returned->latestReview?->reviewed_at?->format('M j, Y g:i A') ?? '' }}
                    @if ($returned->latestReview?->reviewer)
                        by {{ $returned->latestReview->reviewer->username }}@endif
                </span>
                <button
                    type="button"
                    class="btn-primary"
                    wire:click="openRevise({{ $returned->id }})"
                    wire:loading.attr="disabled"
                    wire:target="openRevise"
                >
                    <x-icon name="pen-line" :size="14" />
                    <span>Revise &amp; Resubmit</span>
                </button>
            </div>
        </div>
    @endif

    {{-- SUBMISSIONS TABLE ----}}
    <div class="card">
        <div class="card-head">
            <div class="card-title">Your OPCRF submissions</div>
        </div>
        @if ($this->submissions->isEmpty())
            <p class="card-text">
                Nothing submitted yet. Open the <strong>OPCRF Template</strong>
                card on the <a href="{{ route('home') }}">Dashboard</a> and click
                <strong>Upload your OPCR</strong> — your submissions will be
                listed here.
            </p>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Submitted</th>
                            <th scope="col">Review Period</th>
                            <th scope="col">Employee</th>
                            <th scope="col">Position</th>
                            <th scope="col">Self Rating</th>
                            <th scope="col">Status</th>
                            <th scope="col">Remarks</th>
                            <th scope="col" class="users-actions-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->submissions as $submission)
                            <tr wire:key="dash-opcrf-{{ $submission->id }}">
                                <td>
                                    <span class="cell-strong">{{ $submission->submitted_at->format('M j, Y g:i A') }}</span>
                                </td>
                                <td>{{ $submission->review_period !== '' ? $submission->review_period : '—' }}</td>
                                <td>{{ $submission->employee_name !== '' ? $submission->employee_name : '—' }}</td>
                                <td>{{ $submission->position !== '' ? $submission->position : '—' }}</td>
                                <td>
                                    <span class="badge">{{ $submission->self_rating > 0 ? number_format($submission->self_rating, 2) : '—' }}</span>
                                </td>
                                <td>
                                    @php($latestReview = $submission->latestReview)
                                    <span
                                        class="badge {{ $submission->statusBadgeClass() }}"
                                        @if ($latestReview !== null)
                                            title="{{ $latestReview->reviewer?->username ?? 'Superadmin' }} · {{ $latestReview->actionLabel() }} · {{ $latestReview->reviewed_at->format('M j, Y g:i A') }}"
                                        @endif
                                    >{{ $submission->statusLabelFor(auth()->user()) }}</span>
                                </td>
                                <td class="opcrf-remarks-cell">
                                    @if ($latestReview !== null && $latestReview->remarks !== null && trim($latestReview->remarks) !== '')
                                        {{ $latestReview->remarks }}
                                        <span class="opcrf-review-owner">— {{ $latestReview->reviewer?->username ?? 'reviewer' }}</span>
                                    @else
                                        {{ $submission->remarks ?: '—' }}
                                    @endif
                                </td>
                                <td class="users-actions-col">
                                    @if ($submission->status === \App\Models\OpcrfSubmission::STATUS_RETURNED)
                                        <button
                                            type="button"
                                            class="users-action"
                                            wire:click="openRevise({{ $submission->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openRevise"
                                            title="Revise and resubmit this OPCRF"
                                        >
                                            <span class="users-action-inner">
                                                <x-icon name="pen-line" :size="14" />
                                                <span>Revise &amp; Resubmit</span>
                                            </span>
                                        </button>
                                    @elseif ($submission->isApproved() && $submission->hasFile())
                                        <a
                                            class="users-action"
                                            href="{{ route('opcrf.submission.download', $submission) }}"
                                            title="Download the approved copy of your OPCRF"
                                        >
                                            <span class="users-action-inner">
                                                <x-icon name="download" :size="14" />
                                                <span>Download</span>
                                            </span>
                                        </a>
                                    @else
                                        <span class="opcrf-review-owner">—</span>
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

    {{-- REVISE & RESUBMIT: opened from a returned row — the reviewer's
         remarks are shown first (what to correct), then the revised
         workbook replaces the returned one (previous upload archived as
         Version N) and the submission re-enters review as 'resubmitted',
         routed to the superadmin who returned it. --}}
    @if ($this->resubmitSubmission !== null)
        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.closeRevise()"
            @click.self="$wire.closeRevise()"
            role="presentation"
        >
            <div class="modal modal--opcr-revise" role="dialog" aria-modal="true" aria-labelledby="opcrReviseTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="opcrReviseTitle">Revise &amp; Resubmit</h2>
                    <button type="button" class="modal-close" wire:click="closeRevise" aria-label="Close" title="Close">×</button>
                </div>

                <div class="modal-form">
                    <div class="opcrf-action-required-remarks opcrf-revise-remarks">
                        <div class="opcrf-action-required-remarks-label">
                            Superadmin Remarks @if ($this->resubmitSubmission->latestReview?->reviewer)
                                — {{ $this->resubmitSubmission->latestReview->reviewer->username }}@endif
                        </div>
                        <p class="opcrf-action-required-remarks-text">
                            {{ $this->resubmitSubmission->latestReview?->remarks !== null && trim((string) $this->resubmitSubmission->latestReview?->remarks) !== ''
                                ? $this->resubmitSubmission->latestReview->remarks
                                : 'No remarks were provided — please review your submission and correct any missing or inconsistent details.' }}
                        </p>
                    </div>

                    <p class="opcrf-review-note">
                        Attach your corrected <strong>OPCRF .xlsx</strong> below
                        (the returned copy is kept as a version), then resubmit
                        it back to
                        <strong>{{ $this->resubmitSubmission->returningReviewer()?->username ?? 'the superadmin' }}</strong>
                        for review.
                    </p>

                    <form wire:submit.prevent class="opcrf-upload-form">
                        <label class="opcrf-dropzone {{ $errors->has('revisionFile') ? 'has-error' : '' }}">
                            <input
                                type="file"
                                accept=".xlsx"
                                wire:model="revisionFile"
                                wire:loading.attr="disabled"
                                wire:target="revisionFile"
                                class="opcrf-file-input"
                                aria-label="Upload your revised OPCRF xlsx file"
                            >

                            <span class="opcrf-dropzone-inner" wire:loading.remove wire:target="revisionFile">
                                <span class="opcrf-dropzone-badge" aria-hidden="true">
                                    <x-icon name="upload" :size="20" />
                                </span>
                                <span class="opcrf-dropzone-title">Click to choose your revised OPCRF file</span>
                                <span class="opcrf-dropzone-sub">.xlsx · the returned copy is preserved as Version {{ max(1, $this->resubmitSubmission->versions()->count() + 1) }} · resubmitting without a new file keeps the current one</span>
                            </span>

                            <span class="opcrf-dropzone-inner" wire:loading wire:target="revisionFile">
                                <span class="opcrf-dropzone-badge" aria-hidden="true">
                                    <x-icon name="file-spreadsheet" :size="20" />
                                </span>
                                <span class="opcrf-dropzone-title">Uploading your revision…</span>
                                <span class="opcrf-dropzone-sub">Storing the revised workbook</span>
                            </span>
                        </label>

                        @error('revisionFile') <div class="error-text">{{ $message }}</div> @enderror
                    </form>

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeRevise">Cancel</button>
                        <button
                            type="button"
                            class="btn-primary"
                            wire:click="confirmResubmit"
                            wire:loading.attr="disabled"
                            wire:target="confirmResubmit"
                        >
                            <x-icon name="send" :size="14" />
                            <span wire:loading.remove wire:target="confirmResubmit">Resubmit for Review</span>
                            <span wire:loading wire:target="confirmResubmit">Resubmitting…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
