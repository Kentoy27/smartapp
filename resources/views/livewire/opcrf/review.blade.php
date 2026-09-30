<div class="opcrf-review-component" wire:poll.15s>

    {{-- SUCCESS ALERT: popped once after an approval, then cleared so later
         renders never re-fire it. --}}
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

    {{-- SUBMISSIONS TABLE: every staff submission, newest first. --}}
    <div class="card">
        <div class="card-head">
            <div class="card-title">All OPCRF submissions</div>
        </div>
        @if ($this->submissions->isEmpty())
            <p class="card-text">
                Nothing to review yet — submissions appear here the moment
                staff upload their OPCR and attach their MOVs.
            </p>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Submitted</th>
                            <th scope="col">Employee</th>
                            <th scope="col">Position</th>
                            <th scope="col">Review Period</th>
                            <th scope="col">Self Rating</th>
                            <th scope="col">Status</th>
                            <th scope="col">MOVs</th>
                            <th scope="col" class="users-actions-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->submissions as $submission)
                            <tr wire:key="review-opcrf-{{ $submission->id }}">
                                <td>
                                    <span class="cell-strong">{{ $submission->submitted_at->format('M j, Y g:i A') }}</span>
                                </td>
                                <td>
                                    <span class="cell-strong">{{ $submission->employee_name !== '' ? $submission->employee_name : '—' }}</span>
                                    <span class="opcrf-review-owner">{{ $submission->user?->username ?? '—' }}</span>
                                    @if ($submission->reviewer_id === null)
                                        <span class="opcrf-review-owner">Unassigned — no recipient on record</span>
                                    @endif
                                </td>
                                <td>{{ $submission->position !== '' ? $submission->position : '—' }}</td>
                                <td>{{ $submission->review_period !== '' ? $submission->review_period : '—' }}</td>
                                <td>
                                    <span class="badge">{{ $submission->self_rating > 0 ? number_format($submission->self_rating, 2) : '—' }}</span>
                                </td>
                                <td>
                                    @if ($submission->isApproved())
                                        <span class="badge badge--ok" title="Approved by {{ $submission->approvedBy?->username ?? 'superadmin' }} on {{ $submission->approved_at->format('M j, Y g:i A') }}">Approved</span>
                                    @else
                                        <span class="badge badge-muted">Pending review</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $submission->movs_count === 0 ? 'badge-muted' : '' }}">
                                        {{ $submission->movs_count }}
                                    </span>
                                </td>
                                <td class="users-actions-col">
                                    <span class="users-actions-row">
                                        <button
                                            type="button"
                                            class="users-action"
                                            wire:click="openReview({{ $submission->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openReview"
                                            title="Review the submission for {{ $submission->review_period }}"
                                        >
                                            <span class="users-action-inner">
                                                <x-icon name="eye" :size="14" />
                                                <span>Review</span>
                                            </span>
                                        </button>
                                        <button
                                            type="button"
                                            class="users-action users-action--danger"
                                            wire:click="openDelete({{ $submission->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openDelete"
                                            title="Delete the submission for {{ $submission->review_period }}"
                                        >
                                            <span class="users-action-inner">
                                                <x-icon name="trash" :size="14" />
                                                <span>Delete</span>
                                            </span>
                                        </button>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $this->submissions->links() }}
        @endif
    </div>

    {{-- REVIEW MODAL: the submission's recorded summary, a download of the
         full submitted workbook, and the MOVs as a view-only record. Its
         only write action is the review outcome — approve the submission,
         optionally attaching the corrected workbook as the official copy;
         nothing else can be edited, replaced, or deleted here. --}}
    @if ($showReview && $this->reviewSubmission())
        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.closeReview()"
            @click.self="$wire.closeReview()"
            role="presentation"
        >
            <div class="modal modal--opcrf-review modal--opcrf-sheet" role="dialog" aria-modal="true" aria-labelledby="opcrfReviewTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="opcrfReviewTitle">Review submission</h2>
                    <button type="button" class="modal-close" wire:click="closeReview" aria-label="Close" title="Close">×</button>
                </div>

                <p class="opcrf-review-note">
                    Submitted by
                    <strong>{{ $this->reviewSubmission()->realNameLabel() ?: ($this->reviewSubmission()->user?->username ?? '—') }}</strong>@if ($this->reviewSubmission()->user?->username && $this->reviewSubmission()->realNameLabel() !== $this->reviewSubmission()->user?->username)
                        ({{ $this->reviewSubmission()->user?->username }})@endif
                    on {{ $this->reviewSubmission()->submitted_at->format('M j, Y g:i A') }} —
                    download the full submitted workbook to review it in Excel,
                    then approve the submission below. The file saves under this
                    staff member's real name.
                </p>

                <div class="modal-form opcrf-review-body">
                    <div class="opcrf-review-summary opcrf-review-summary--sheet">
                        <div class="opcrf-review-toolbar">
                            {{-- The literal original: the very workbook the
                                 staff member uploaded (or the corrected copy
                                 attached at approval). Never a synthesized
                                 stand-in. --}}
                            @if ($this->reviewSubmission()->hasFile())
                                <a
                                    class="btn-primary btn-primary--small"
                                    href="{{ route('opcrf.submission.download', $this->reviewSubmission()) }}"
                                    download
                                    title="Download the staff member's OPCRF ({{ $this->reviewSubmission()->isApproved() ? 'official approved copy' : 'full submitted file' }})"
                                >
                                    <x-icon name="download" :size="14" />
                                    <span>Download</span>
                                </a>
                            @else
                                <span class="opcrf-review-nofile">
                                    <x-icon name="file-spreadsheet" :size="14" />
                                    <span>
                                        No original OPCRF on file for this submission — attach the
                                        full document below to make it the downloadable copy.
                                    </span>
                                </span>
                            @endif
                        </div>

                        {{-- SUBMITTED FORM SUMMARY: the recorded values in
                             the template's header layout — the full submitted
                             workbook itself is reviewed by downloading it. --}}
                        <div class="opcrf-review-sheetblock">
                            <div class="opcrf-movs-attached-head">Submitted form</div>

                            <div class="opcrf-sheet-headerblock opcrf-sheet-headerblock--split">
                                <div class="opcrf-sheet-headercol">
                                    <div class="opcrf-sheet-headrow">
                                        <span class="opcrf-sheet-headlabel">Name of Employee:</span>
                                        <span class="opcrf-sheet-headvalue {{ $this->reviewSubmission()->employee_name === '' ? 'is-empty' : '' }}">
                                            {{ $this->reviewSubmission()->employee_name !== '' ? $this->reviewSubmission()->employee_name : '—' }}
                                        </span>
                                    </div>
                                    <div class="opcrf-sheet-headrow">
                                        <span class="opcrf-sheet-headlabel">Position/Designation:</span>
                                        <span class="opcrf-sheet-headvalue {{ $this->reviewSubmission()->position === '' ? 'is-empty' : '' }}">
                                            {{ $this->reviewSubmission()->position !== '' ? $this->reviewSubmission()->position : '—' }}
                                        </span>
                                    </div>
                                    <div class="opcrf-sheet-headrow">
                                        <span class="opcrf-sheet-headlabel">Review Period:</span>
                                        <span class="opcrf-sheet-headvalue {{ $this->reviewSubmission()->review_period === '' ? 'is-empty' : '' }}">
                                            {{ $this->reviewSubmission()->review_period !== '' ? $this->reviewSubmission()->review_period : '—' }}
                                        </span>
                                    </div>
                                    <div class="opcrf-sheet-headrow">
                                        <span class="opcrf-sheet-headlabel">Strand/Bureau/Center/Service/Region/Division:</span>
                                        <span class="opcrf-sheet-headvalue {{ $this->reviewSubmission()->division_office === '' ? 'is-empty' : '' }}">
                                            {{ $this->reviewSubmission()->division_office !== '' ? $this->reviewSubmission()->division_office : '—' }}
                                        </span>
                                    </div>
                                </div>

                                <div class="opcrf-sheet-headercol opcrf-sheet-headercol--evaluator">
                                    <div class="opcrf-sheet-headrow">
                                        <span class="opcrf-sheet-headlabel">Evaluator:</span>
                                        <span class="opcrf-sheet-headvalue is-empty">Pending review</span>
                                    </div>
                                    <div class="opcrf-sheet-headrow">
                                        <span class="opcrf-sheet-headlabel">Position:</span>
                                        <span class="opcrf-sheet-headvalue is-empty">—</span>
                                    </div>
                                    <div class="opcrf-sheet-headrow">
                                        <span class="opcrf-sheet-headlabel">Approving Authority:</span>
                                        <span class="opcrf-sheet-headvalue is-empty">—</span>
                                    </div>
                                    <div class="opcrf-sheet-headrow">
                                        <span class="opcrf-sheet-headlabel">Date of Review:</span>
                                        <span class="opcrf-sheet-headvalue is-empty">—</span>
                                    </div>
                                </div>
                            </div>

                            @if (trim((string) $this->reviewSubmission()->remarks) !== '')
                                <div class="opcrf-sheet-partnote">
                                    <strong>Remarks:</strong> {{ $this->reviewSubmission()->remarks }}
                                </div>
                            @endif
                        </div>

                        {{-- MOVS — VIEW-ONLY RECORD: each file can be
                             downloaded; nothing can be added or removed. --}}
                        <div class="opcrf-movs-attached">
                            <div class="opcrf-movs-attached-head">MOVs (view only)</div>
                            @if ($this->reviewMovs->isEmpty())
                                <p class="opcrf-movs-empty">No MOVs attached to this submission.</p>
                            @else
                                <ul class="opcrf-movs-list">
                                    @foreach ($this->reviewMovs as $mov)
                                        <li class="opcrf-movs-item" wire:key="review-mov-{{ $mov->id }}">
                                            <x-icon name="paperclip" :size="14" class="opcrf-movs-item-icon" />
                                            <a class="opcrf-movs-item-name" href="{{ route('opcrf.movs.download', $mov) }}">{{ $mov->original_name }}</a>
                                            <span class="opcrf-movs-item-size">{{ $mov->human_size }}</span>
                                            <a
                                                class="users-action"
                                                href="{{ route('opcrf.movs.download', $mov) }}"
                                                title="Download {{ $mov->original_name }}"
                                            >
                                                <span class="users-action-inner">
                                                    <x-icon name="download" :size="12" />
                                                    <span>Download</span>
                                                </span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        {{-- APPROVAL: the review's write step. Marking the
                             submission approved records who/when; attaching
                             the corrected/filled workbook in the same step
                             makes it the official copy, so the download at
                             the top of this modal serves the approved file. --}}
                        <div class="opcrf-approval">
                            <div class="opcrf-movs-attached-head">Approval</div>

                            @if ($this->reviewSubmission()->isApproved())
                                <div class="opcrf-approval-state is-approved">
                                    <x-icon name="shield" :size="15" />
                                    <span>
                                        Approved by
                                        <strong>{{ $this->reviewSubmission()->approvedBy?->username ?? 'superadmin' }}</strong>
                                        on {{ $this->reviewSubmission()->approved_at->format('M j, Y g:i A') }}
                                        — the file downloaded from here is the official approved copy.
                                    </span>
                                </div>
                            @else
                                <div class="opcrf-approval-state">
                                    <x-icon name="shield" :size="15" />
                                    <span>Not approved yet — download the submission, review it in Excel, then attach the updated workbook below to approve it.</span>
                                </div>
                            @endif

                            {{-- FILE LOADER: a button in the same design as
                                 Download, not a big dropzone — it opens the
                                 file picker and shows the picked name. The
                                 updated workbook is required to approve. --}}
                            <div class="opcrf-file-load">
                                <label class="opcrf-file-load-btn {{ $errors->has('reviewFile') ? 'has-error' : '' }}">
                                    <x-icon name="upload" :size="14" />
                                    <span wire:loading.remove wire:target="reviewFile">
                                        {{ $reviewFile
                                            ? 'Change the file'
                                            : ($this->reviewSubmission()->hasFile()
                                                ? 'Upload the updated OPCRF'
                                                : 'Upload the full OPCRF document') }}
                                    </span>
                                    <span wire:loading wire:target="reviewFile">Uploading…</span>

                                    <input
                                        type="file"
                                        accept=".xlsx"
                                        wire:model="reviewFile"
                                        wire:loading.attr="disabled"
                                        wire:target="reviewFile"
                                        class="opcrf-file-input"
                                        aria-label="Attach the updated OPCRF workbook to approve"
                                    >
                                </label>

                                @if ($reviewFile)
                                    <span class="opcrf-file-load-name">
                                        <x-icon name="paperclip" :size="13" />
                                        {{ $reviewFile->getClientOriginalName() }}
                                    </span>
                                @else
                                    <span class="opcrf-file-load-hint">
                                        .xlsx · required to approve · becomes the official copy · max 10 MB
                                    </span>
                                @endif
                            </div>

                            @error('reviewFile') <div class="error-text">{{ $message }}</div> @enderror

                            <div class="opcrf-approval-actions">
                                <button
                                    type="button"
                                    class="btn-ghost"
                                    wire:click="closeReview"
                                    title="Close without approving"
                                >
                                    Close
                                </button>
                                <button
                                    type="button"
                                    class="btn-primary btn-primary--small"
                                    wire:click="approveSubmission"
                                    wire:loading.attr="disabled"
                                    wire:target="approveSubmission,reviewFile"
                                >
                                    <x-icon name="shield" :size="14" />
                                    <span wire:loading.remove wire:target="approveSubmission">
                                        {{ $this->reviewSubmission()->isApproved()
                                            ? 'Save & update the official copy'
                                            : 'Approve & save the official copy' }}
                                    </span>
                                    <span wire:loading wire:target="approveSubmission">Saving…</span>
                                </button>
                            </div>
                        </div>

                        {{-- FORWARD: hand the submission to another superadmin.
                             They become the only one who can review, update,
                             download or approve it — this account loses the
                             row, and a completed approval is reopened. --}}
                        <div class="opcrf-approval">
                            <div class="opcrf-movs-attached-head">Forward to another superadmin</div>

                            @if ($this->forwardRecipients->isEmpty())
                                <p class="opcrf-review-route-empty">
                                    You are the only superadmin right now — there is nobody to
                                    hand this submission to.
                                </p>
                            @else
                                <label for="opcrfForwardTo">Hand this submission to</label>

                                <select
                                    id="opcrfForwardTo"
                                    wire:model="forward_to"
                                    @class(['error' => $errors->has('forward_to')])
                                >
                                    <option value="">Choose a superadmin…</option>
                                    @foreach ($this->forwardRecipients as $recipient)
                                        <option value="{{ $recipient->id }}">
                                            {{ $recipient->username }}@if ($recipient->name !== '' && $recipient->name !== $recipient->username) — {{ $recipient->name }}@endif
                                        </option>
                                    @endforeach
                                </select>

                                <p class="opcrf-review-route-sub">
                                    They become the only one who can open, review, update, or
                                    approve this submission.
                                    @if ($this->reviewSubmission()->isApproved())
                                        <strong>It is approved now — forwarding reopens it for
                                        their review.</strong>
                                    @endif
                                </p>

                                @error('forward_to') <div class="error-text">{{ $message }}</div> @enderror

                                <div class="opcrf-approval-actions">
                                    <button
                                        type="button"
                                        class="btn-primary btn-primary--small"
                                        wire:click="forwardSubmission"
                                        wire:loading.attr="disabled"
                                        wire:target="forwardSubmission"
                                    >
                                        <x-icon name="user-round" :size="14" />
                                        <span wire:loading.remove wire:target="forwardSubmission">Hand it over</span>
                                        <span wire:loading wire:target="forwardSubmission">Handing over…</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- DELETE SUBMISSION: the row's Delete action only opens this
         confirmation — nothing is removed until confirmDelete(). --}}
    @if ($showDeleteModal)
        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.closeDelete()"
            @click.self="$wire.closeDelete()"
            role="presentation"
        >
            <div class="modal modal--confirmation" role="dialog" aria-modal="true" aria-labelledby="deleteOpcrTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="deleteOpcrTitle">Delete OPCR Submission</h2>
                    <button type="button" class="modal-close" wire:click="closeDelete" aria-label="Close" title="Close">×</button>
                </div>

                <div class="modal-form">
                    <p class="delete-modal-description">
                        Are you sure you want to delete
                        <strong>{{ $deleteLabel }}</strong>?
                        The submission, its uploaded workbook, and all its MOV files
                        are removed. This action cannot be undone.
                    </p>

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeDelete">Cancel</button>
                        <button type="button" class="btn-danger" wire:click="confirmDelete" wire:loading.attr="disabled" wire:target="confirmDelete">
                            <span wire:loading.remove wire:target="confirmDelete">Delete Submission</span>
                            <span wire:loading wire:target="confirmDelete">Deleting…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
