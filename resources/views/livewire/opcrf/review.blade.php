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
                Nothing to review yet — submissions appear here the moment a
                staff member confirms their OPCR.
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
                                    @php($latestReview = $submission->latestReview)
                                    <span
                                        class="badge {{ $submission->statusBadgeClass() }}"
                                        @if ($latestReview !== null)
                                            title="{{ $latestReview->reviewer?->username ?? 'Superadmin' }} · {{ $latestReview->actionLabel() }} · {{ $latestReview->reviewed_at->format('M j, Y g:i A') }}"
                                        @endif
                                    >{{ $submission->statusLabelFor(auth()->user()) }}</span>
                                </td>
                                <td class="users-actions-col">
                                    <x-row-menu :label="'More actions for the submission from '.$submission->submitted_at->format('M j, Y')">
                                        <x-row-menu-item
                                            wire:click="openReview({{ $submission->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openReview"
                                            title="Review the submission for {{ $submission->review_period }}"
                                        >
                                            <x-icon name="eye" :size="15" />
                                            <span>Review</span>
                                        </x-row-menu-item>
                                        <x-row-menu-item
                                            wire:click="openDelete({{ $submission->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openDelete"
                                            title="Delete the submission for {{ $submission->review_period }}"
                                            danger
                                        >
                                            <x-icon name="trash" :size="15" />
                                            <span>Delete</span>
                                        </x-row-menu-item>
                                    </x-row-menu>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $this->submissions->links() }}
        @endif
    </div>

    {{-- REVIEW MODAL: the full submitted workbook (read in-app, Excel
         style) and the review-and-routing workflow: remarks, then a
         compliance/approval mark — which auto-routes the same submission
         onward to the next superadmin (SY), no manual forwarding step — or
         a return for revision. Every action is recorded in the history
         timeline. Nothing is ever uploaded here and the submitted file is
         never replaced. A superadmin the submission has already moved on
         from keeps a read-only view of the record. --}}
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
                    every part of the submitted workbook is below, in the
                    form's own wording: the header block, the statement of
                    purpose, each objective with its planning commitments and
                    reported results, the competency ratings, the summary of
                    ratings and both improvement plans. A field the staff
                    member left empty says so instead of being filled in.
                    Add remarks, then mark it compliant (the submission then
                    routes onward to the next superadmin automatically) or
                    return it for revision — the submitted file is never
                    replaced.
                </p>

                {{-- UPLOAD CHECK: an upload is never held up over what the
                     file says about itself, so this is context, not a
                     verdict. It appears only when the file said something
                     the account did not — the staff member saw the same note
                     before submitting, and could submit regardless. --}}
                @php($uploadCheckNote = $this->reviewSubmission()->uploadCheckNote())
                @if ($uploadCheckNote !== null)
                    <div class="opcrf-verified opcrf-verified--note">
                        <x-icon name="alert-triangle" :size="14" />
                        <span><strong>Upload check</strong> — {{ $uploadCheckNote }}</span>
                    </div>
                @endif

                <div class="modal-form opcrf-review-body">
                    <div class="opcrf-review-summary opcrf-review-summary--sheet">                        {{-- THE SUBMITTED FORM: the staff member's own workbook,
                             rendered through the identical partial the
                             upload review modal uses. Falls back to the
                             recorded summary when no original file exists or
                             it cannot be read. --}}
                        <div class="opcrf-review-sheetblock">
                            <div class="opcrf-review-card-head">
                                <span>Submitted form</span>
                                @if ($this->reviewVersions->isNotEmpty())
                                    <span class="opcrf-version-pill" title="Archived versions from previous resubmissions">
                                        Version {{ $this->reviewVersions->count() + 1 }} of {{ $this->reviewVersions->count() + 1 }} current
                                        · {{ $this->reviewVersions->count() }} archived
                                    </span>
                                @endif
                            </div>

                            @if ($this->reviewSheet !== null)
                                {{-- THE SUBMITTED WORKBOOK, RENDERED EXACTLY
                                     AS THE STAFF MEMBER SAW IT: the same
                                     Excel-window partial, the same sheet-tab
                                     strip, the same columns. The reviewer
                                     reads the file as it was filed, not as a
                                     re-interpretation of it — so nothing can
                                     be approved here that the submitter could
                                     not see on their own screen. --}}
                                @include('livewire.opcrf.partials.opcrf-excelwin', [
                                    'sheet' => $this->reviewSheet,
                                    'rating' => $this->reviewSheetRating,
                                    'full' => false,
                                    'workbook' => $this->reviewWorkbook ?? ['tabs' => [], 'part_two' => ['sections' => [], 'total_rows' => [], 'signers' => [], 'scale' => ['title' => '', 'levels' => []]], 'part_three' => ['components' => [], 'agreement' => [], 'overall' => null, 'rating' => '', 'rating_table' => [], 'signatures' => []], 'part_four' => ['office_plan' => [], 'office_feedback' => '', 'development_plan' => [], 'development_feedback' => '', 'signers' => [], 'sections' => []], 'extra_sheets' => []],
                                    'uid' => 'rv',
                                ])

                                @if (trim((string) $this->reviewSubmission()->remarks) !== '')
                                    <div class="opcrf-sheet-partnote">
                                        <strong>Remarks:</strong> {{ $this->reviewSubmission()->remarks }}
                                    </div>
                                @endif
                            @else
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
                            @endif
                        </div>

                        {{-- REVIEW DECISION: the review's write step. The
                             superadmin holding the review step adds remarks,
                             then either marks the submission
                             compliant/approved — which auto-routes the same
                             submission onward to the workflow's next
                             superadmin (SY), no manual forwarding step — or
                             returns it to the staff member for revision.
                             Nothing is uploaded — the staff member's
                             submitted workbook is never replaced. A
                             superadmin the submission has already moved on
                             from (the original reviewer) sees a read-only
                             note instead of actions. --}}
                        <div class="opcrf-review-card">
                            <div class="opcrf-review-card-head">
                                <span>Review Decision</span>
                                <span class="badge {{ $this->reviewSubmission()->statusBadgeClass() }}">{{ $this->reviewSubmission()->statusLabelFor(auth()->user()) }}</span>
                            </div>

                            @if ($this->reviewSubmission()->isAssignedTo(auth()->user()))
                                <p class="opcrf-review-card-desc">
                                    Review the submission and choose an appropriate action.
                                    Add remarks when necessary.
                                </p>

                                <label for="opcrfReviewRemarks">Remarks / Comments</label>
                                <textarea
                                    id="opcrfReviewRemarks"
                                    class="opcrf-remarks-textarea"
                                    wire:model="reviewRemarks"
                                    rows="6"
                                    placeholder="Enter remarks, compliance notes, or instructions for the next reviewer..."
                                    @class(['error' => $errors->has('reviewRemarks')])
                                ></textarea>
                                @error('reviewRemarks') <div class="error-text">{{ $message }}</div> @enderror

                                <div class="opcrf-decision-actions">
                                    <button
                                        type="button"
                                        class="btn-ghost"
                                        wire:click="returnSubmission"
                                        wire:loading.attr="disabled"
                                        wire:target="returnSubmission"
                                        title="Send it back to the staff member for revision (remarks required)"
                                    >
                                        <x-icon name="undo-2" :size="14" />
                                        <span wire:loading.remove wire:target="returnSubmission">Return for Revision</span>
                                        <span wire:loading wire:target="returnSubmission">Returning…</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-primary"
                                        wire:click="approveSubmission"
                                        wire:loading.attr="disabled"
                                        wire:target="approveSubmission"
                                    >
                                        <x-icon name="shield" :size="14" />
                                        <span wire:loading.remove wire:target="approveSubmission">Approve / Compliance</span>
                                        <span wire:loading wire:target="approveSubmission">Saving…</span>
                                    </button>
                                </div>
                            @else
                                <p class="opcrf-review-card-desc opcrf-review-readonly-note">
                                    This submission has been routed onward to
                                    <strong>Superadmin {{ $this->reviewSubmission()->assignedTo?->username ?? 'the next reviewer' }}</strong>
                                    and now sits in their review queue. This record stays
                                    visible here for reference — the review history below
                                    is read-only.
                                </p>
                            @endif
                        </div>

                        {{-- REVIEW HISTORY: the submission's immutable trail —
                             every review action shown as a timeline: the
                             action, the reviewer, their remarks, the
                             timestamp, and the forward recipient. Existing
                             submissions keep every historical record. --}}
                        <div class="opcrf-review-card">
                            <div class="opcrf-review-card-head"><span>Review History</span></div>

                            @if ($this->reviewHistory->isEmpty())
                                <div class="opcrf-history-timeline">
                                    <div class="opcrf-history-entry">
                                        <span class="opcrf-history-dot" aria-hidden="true"></span>
                                        <p class="opcrf-history-empty-text">No review activity yet.</p>
                                    </div>
                                </div>
                            @else
                                <div class="opcrf-history-timeline">
                                    {{-- The submission's own arrival opens the trail. --}}
                                    <div class="opcrf-history-entry" wire:key="review-history-received">
                                        <span class="opcrf-history-dot opcrf-history-dot--muted" aria-hidden="true"></span>
                                        <div class="opcrf-history-body">
                                            <div class="opcrf-history-action">Pending Review</div>
                                            <div class="opcrf-history-meta">Submission received</div>
                                            <div class="opcrf-history-meta">Date: {{ $this->reviewSubmission()->submitted_at->format('M j, Y g:i A') }}</div>
                                        </div>
                                    </div>

                                    @foreach ($this->reviewHistory as $entry)
                                        <div class="opcrf-history-entry" wire:key="review-history-{{ $entry->id }}">
                                            <span class="opcrf-history-dot" aria-hidden="true"></span>
                                        <div class="opcrf-history-body">
                                            <div class="opcrf-history-action">{{ $entry->actionLabel() }}</div>
                                            @if ($entry->action === \App\Models\OpcrfReview::ACTION_FORWARD && $entry->to !== null)
                                                <div class="opcrf-history-meta">Forwarded to: <strong>{{ $entry->to->username }}</strong></div>
                                                <div class="opcrf-history-meta">By: <strong>{{ $entry->reviewer?->username ?? '—' }}</strong></div>
                                            @elseif ($entry->action === \App\Models\OpcrfReview::ACTION_RESUBMIT)
                                                <div class="opcrf-history-meta">Resubmitted by: <strong>{{ $entry->submission->user?->username ?? 'the staff member' }}</strong></div>
                                            @else
                                                <div class="opcrf-history-meta">Reviewed by: <strong>{{ $entry->reviewer?->username ?? '—' }}</strong></div>
                                            @endif
                                            @if ($entry->transitionLabel() !== null)
                                                <div class="opcrf-history-meta">Status: {{ $entry->transitionLabel() }}</div>
                                            @endif
                                            @if ($entry->remarks !== null && trim($entry->remarks) !== '')
                                                <div class="opcrf-history-remarks">Remarks: {{ $entry->remarks }}</div>
                                            @endif
                                            <div class="opcrf-history-meta">Date: {{ $entry->reviewed_at->format('M j, Y g:i A') }}</div>
                                        </div>
                                        </div>
                                    @endforeach
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
