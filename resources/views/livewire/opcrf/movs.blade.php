<div class="opcrf-movs-component">

    {{-- SUCCESS ALERT: popped once after attach/remove, then cleared so
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

    {{-- SUBMISSIONS TABLE --}}
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
                                    @if ($submission->isApproved())
                                        <span class="badge badge--ok" title="Approved on {{ $submission->approved_at->format('M j, Y g:i A') }}">Approved</span>
                                    @else
                                        <span class="badge badge-muted">Pending review</span>
                                    @endif
                                </td>
                                <td class="opcrf-remarks-cell">{{ $submission->remarks ?: '—' }}</td>
                                <td class="users-actions-col">
                                    <span class="users-actions-row">
                                        @if ($submission->isApproved() && $submission->hasFile())
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
                                        @endif
                                        <button
                                            type="button"
                                            class="users-action"
                                            wire:click="openMovs({{ $submission->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openMovs"
                                            title="Upload MOVs for {{ $submission->review_period }}"
                                        >
                                            <span class="users-action-inner">
                                                <x-icon name="paperclip" :size="14" />
                                                <span>MOVs</span>
                                                @if ($submission->movs_count > 0)
                                                    <span class="badge">{{ $submission->movs_count }}</span>
                                                @endif
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

    {{-- UPLOAD YOUR MOVS MODAL: opened per submission row. Lists attached
         files, takes several new ones, and removes individually. --}}
    @if ($showModal && $this->currentSubmission())
        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.closeMovs()"
            @click.self="$wire.closeMovs()"
            role="presentation"
        >
            <div class="modal modal--movs" role="dialog" aria-modal="true" aria-labelledby="opcrfMovsTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="opcrfMovsTitle">Upload your MOVs</h2>
                    <button type="button" class="modal-close" wire:click="closeMovs" aria-label="Close" title="Close">×</button>
                </div>

                <p class="opcrf-review-note">
                    Attach your <strong>Means of Verification</strong> for
                    <strong>{{ $submissionLabel }}</strong> — the reports, signed
                    forms, and documents that prove each accomplishment. Up to
                    10 MB per file; PDF, Word, Excel, PowerPoint, images, and ZIP.
                </p>

                <div class="modal-form opcrf-movs-body">
                    {{-- NEW FILES PICKER --}}
                    <label class="opcrf-dropzone opcrf-dropzone--compact {{ $errors->has('newFiles.*') || $errors->has('newFiles') ? 'has-error' : '' }}">
                        <input
                            type="file"
                            multiple
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.zip"
                            wire:model="newFiles"
                            wire:loading.attr="disabled"
                            wire:target="newFiles"
                            class="opcrf-file-input"
                            aria-label="Choose MOV files to attach"
                        >

                        <span class="opcrf-dropzone-inner" wire:loading.remove wire:target="newFiles">
                            <span class="opcrf-dropzone-badge" aria-hidden="true">
                                <x-icon name="upload" :size="18" />
                            </span>
                            <span class="opcrf-dropzone-title">Click to choose MOV files</span>
                            <span class="opcrf-dropzone-sub">You can pick several at once</span>
                        </span>

                        <span class="opcrf-dropzone-inner" wire:loading wire:target="newFiles">
                            <span class="opcrf-dropzone-badge" aria-hidden="true">
                                <x-icon name="paperclip" :size="18" />
                            </span>
                            <span class="opcrf-dropzone-title">Uploading…</span>
                            <span class="opcrf-dropzone-sub">Storing your files securely</span>
                        </span>
                    </label>
                    @error('newFiles') <div class="error-text">{{ $message }}</div> @enderror
                    @error('newFiles.*') <div class="error-text">{{ $message }}</div> @enderror

                    {{-- FILES QUEUED (temp-uploaded, not saved yet) --}}
                    @if (!empty($newFiles))
                        <div class="opcrf-movs-queue">
                            <div class="opcrf-movs-queue-head">
                                <span>{{ count($newFiles) }} file{{ count($newFiles) === 1 ? '' : 's' }} ready to attach</span>
                                <button type="button" class="btn-primary btn-primary--small" wire:click="saveMovs" wire:loading.attr="disabled" wire:target="saveMovs">
                                    <span wire:loading.remove wire:target="saveMovs">Attach file{{ count($newFiles) === 1 ? '' : 's' }}</span>
                                    <span wire:loading wire:target="saveMovs">Attaching…</span>
                                </button>
                            </div>
                            <ul class="opcrf-movs-list">
                                @foreach ($newFiles as $queued)
                                    <li class="opcrf-movs-item" wire:key="queued-{{ $loop->index }}-{{ $queued->getClientOriginalName() }}">
                                        <x-icon name="paperclip" :size="14" class="opcrf-movs-item-icon" />
                                        <span class="opcrf-movs-item-name">{{ $queued->getClientOriginalName() }}</span>
                                        <span class="badge badge-muted">new</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- ALREADY ATTACHED --}}
                    <div class="opcrf-movs-attached">
                        <div class="opcrf-movs-attached-head">Attached files</div>
                        @if ($this->existingMovs->isEmpty())
                            <p class="opcrf-movs-empty">No MOVs attached yet for this submission.</p>
                        @else
                            <ul class="opcrf-movs-list">
                                @foreach ($this->existingMovs as $mov)
                                    <li class="opcrf-movs-item" wire:key="mov-{{ $mov->id }}">
                                        <x-icon name="file-spreadsheet" :size="14" class="opcrf-movs-item-icon" />
                                        <a class="opcrf-movs-item-name" href="{{ route('opcrf.movs.download', $mov) }}">{{ $mov->original_name }}</a>
                                        <span class="opcrf-movs-item-size">{{ $mov->human_size }}</span>
                                        <button
                                            type="button"
                                            class="users-action users-action--danger users-action--tiny"
                                            wire:click="deleteMov({{ $mov->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="deleteMov({{ $mov->id }})"
                                            title="Remove {{ $mov->original_name }}"
                                        >
                                            <x-icon name="trash" :size="12" />
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeMovs">Close</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
