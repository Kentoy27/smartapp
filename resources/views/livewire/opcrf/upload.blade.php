<div class="opcrf-upload-component">

    {{-- SUCCESS ALERT: popped once after the confirmed upload, then the
         message is cleared so later renders never re-fire it. --}}
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

    {{-- UPLOAD BUTTON: rendered beside the card's Download button. --}}
    <button
        type="button"
        class="opcrf-upload-btn {{ $errors->has('file') ? 'has-error' : '' }}"
        wire:click="openUpload"
        aria-haspopup="dialog"
        title="Upload your filled OPCRF xlsx"
    >
        <x-icon name="upload" :size="16" />
        <span>Upload</span>
    </button>

    {{-- UPLOAD WINDOW: "Upload your OPCR in here". Picking a file analyzes it
         and hands over to the review modal below. --}}
    @if ($showUpload)
        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.closeUpload()"
            @click.self="$wire.closeUpload()"
            role="presentation"
        >
            <div class="modal modal--opcr-upload" role="dialog" aria-modal="true" aria-labelledby="opcrUploadTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="opcrUploadTitle">Upload your OPCR in here</h2>
                    <button type="button" class="modal-close" wire:click="closeUpload" aria-label="Close" title="Close">×</button>
                </div>

                <p class="opcrf-review-note">
                    Choose the <strong>OPCRF-TEMPLATE.xlsx</strong> you already
                    filled in. It is analyzed automatically and shown as an
                    Excel-style sheet — nothing is saved before you review it.
                </p>

                <div class="modal-form opcrf-upload-body">
                    <form wire:submit.prevent class="opcrf-upload-form">
                        <label class="opcrf-dropzone {{ $errors->has('file') ? 'has-error' : '' }}">
                            <input
                                type="file"
                                accept=".xlsx"
                                wire:model="file"
                                wire:loading.attr="disabled"
                                wire:target="file"
                                class="opcrf-file-input"
                                aria-label="Upload your filled OPCRF xlsx file"
                            >

                            <span class="opcrf-dropzone-inner" wire:loading.remove wire:target="file">
                                <span class="opcrf-dropzone-badge" aria-hidden="true">
                                    <x-icon name="upload" :size="20" />
                                </span>
                                <span class="opcrf-dropzone-title">Click to choose your OPCRF file</span>
                                <span class="opcrf-dropzone-sub">.xlsx · analyzed automatically · nothing is saved before you review it</span>
                            </span>

                            <span class="opcrf-dropzone-inner" wire:loading wire:target="file">
                                <span class="opcrf-dropzone-badge" aria-hidden="true">
                                    <x-icon name="file-spreadsheet" :size="20" />
                                </span>
                                <span class="opcrf-dropzone-title">Analyzing your OPCR…</span>
                                <span class="opcrf-dropzone-sub">Reading the template and extracting your details</span>
                            </span>
                        </label>

                        @error('file') <div class="error-text">{{ $message }}</div> @enderror
                    </form>

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeUpload">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- REVIEW BEFORE SUBMITTING: pops up once the upload has been analyzed.
         The analyzed workbook is rendered as an Excel-style sheet (title
         banner, header block, objectives table) mirroring the real OPCRF
         template. Nothing is saved until confirmed. --}}
    @if ($showReview && $analyzed)
        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.cancelReview()"
            @click.self="$wire.cancelReview()"
            role="presentation"
        >
            <div class="modal modal--opcrf-review modal--opcrf-sheet" role="dialog" aria-modal="true" aria-labelledby="opcrfUploadReviewTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="opcrfUploadReviewTitle">Review before submitting</h2>
                    <button type="button" class="modal-close" wire:click="cancelReview" aria-label="Close" title="Close">×</button>
                </div>

                <p class="opcrf-review-note">
                    Your OPCR was analyzed. Below is your form exactly as it
                    appears in the Excel template — check every detail before
                    submitting.
                </p>

                <div class="modal-form opcrf-review-body">
                    <div class="opcrf-review-summary opcrf-review-summary--sheet">
                        @include('livewire.opcrf.partials.opcrf-sheet', [
                            'sheet' => $sheet,
                            'rating' => $self_rating,
                            'full' => true,
                        ])

                        <p class="opcrf-review-confirm-line">
                            Uploaded by <strong>{{ auth()->user()->username }}</strong>
                            on {{ now()->format('M j, Y g:i A') }}.
                        </p>
                    </div>

                    {{-- SEND TO: who reviews this OPCR. Only the chosen
                         superadmin sees it in Review Opcrf, so this is the
                         staff member's routing decision. --}}
                    <div class="opcrf-review-route field">
                        <label for="opcrfReviewer">Send this OPCR to (superadmin)</label>

                        @if ($this->reviewers->isEmpty())
                            <p class="opcrf-review-route-empty">
                                No superadmin account exists yet — ask an administrator to
                                create one before you can send your OPCR.
                            </p>
                        @else
                            <select
                                id="opcrfReviewer"
                                wire:model="reviewer_id"
                                @class(['error' => $errors->has('reviewer_id')])
                            >
                                @foreach ($this->reviewers as $reviewer)
                                    <option value="{{ $reviewer->id }}">
                                        {{ $reviewer->username }}@if ($reviewer->name !== '' && $reviewer->name !== $reviewer->username) — {{ $reviewer->name }}@endif
                                    </option>
                                @endforeach
                            </select>
                            <p class="opcrf-review-route-sub">
                                They are the only one who can open, review, or download this
                                submission.
                            </p>
                        @endif

                        @error('reviewer_id') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    @if ($errors->any())
                        <div class="opcrf-sheet-errors" role="alert">
                            <strong>This OPCR can't be submitted yet:</strong>
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="cancelReview">Cancel</button>
                        <button type="button" class="btn-primary" wire:click="confirmSubmit" wire:loading.attr="disabled" wire:target="confirmSubmit">
                            <span wire:loading.remove wire:target="confirmSubmit">Confirm &amp; Submit</span>
                            <span wire:loading wire:target="confirmSubmit">Submitting…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- LOCKED "UPLOAD YOUR MOVS" WINDOW: pops up as soon as the reviewed
         OPCR is confirmed. It is required — there is no close button and
         ESC/backdrop clicks do nothing — and it only releases once at
         least one MOV file has been attached and Continue is clicked. --}}
    @if ($showMovsModal && $this->movSubmission())
        <div
            class="modal-backdrop is-open"
            x-data
            role="presentation"
        >
            <div class="modal modal--movs" role="dialog" aria-modal="true" aria-labelledby="opcrfMovsLockedTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="opcrfMovsLockedTitle">Upload your MOVs</h2>
                    <span class="opcrf-locked-badge" title="This step is required">
                        <x-icon name="lock" :size="14" />
                        <span>Required</span>
                    </span>
                </div>

                <p class="opcrf-review-note">
                    Your OPCR is saved. Now attach your <strong>Means of
                    Verification</strong> for <strong>{{ $movSubmissionLabel }}</strong> —
                    the reports, signed forms, and documents that prove each
                    accomplishment. At least one MOV is required before you
                    can continue.
                </p>

                <div class="modal-form opcrf-movs-body">
                    <div class="opcrf-locked-note" role="status">
                        <x-icon name="lock" :size="14" />
                        <span>This window is locked until you upload at least one MOV — it can't be closed.</span>
                    </div>

                    <label class="opcrf-dropzone opcrf-dropzone--compact {{ $errors->has('movFiles.*') || $errors->has('movFiles') ? 'has-error' : '' }}">
                        <input
                            type="file"
                            multiple
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.zip"
                            wire:model="movFiles"
                            wire:loading.attr="disabled"
                            wire:target="movFiles"
                            class="opcrf-file-input"
                            aria-label="Choose MOV files to attach"
                        >

                        <span class="opcrf-dropzone-inner" wire:loading.remove wire:target="movFiles">
                            <span class="opcrf-dropzone-badge" aria-hidden="true">
                                <x-icon name="upload" :size="18" />
                            </span>
                            <span class="opcrf-dropzone-title">Click to choose your MOV files</span>
                            <span class="opcrf-dropzone-sub">You can pick several at once · at least one is required</span>
                        </span>

                        <span class="opcrf-dropzone-inner" wire:loading wire:target="movFiles">
                            <span class="opcrf-dropzone-badge" aria-hidden="true">
                                <x-icon name="paperclip" :size="18" />
                            </span>
                            <span class="opcrf-dropzone-title">Uploading…</span>
                            <span class="opcrf-dropzone-sub">Storing your files securely</span>
                        </span>
                    </label>
                    @error('movFiles') <div class="error-text">{{ $message }}</div> @enderror
                    @error('movFiles.*') <div class="error-text">{{ $message }}</div> @enderror

                    {{-- ALREADY ATTACHED --}}
                    <div class="opcrf-movs-attached">
                        <div class="opcrf-movs-attached-head">Attached files</div>
                        @if ($this->movsModalFiles->isEmpty())
                            <p class="opcrf-movs-empty">No MOVs attached yet — at least one is required to continue.</p>
                        @else
                            <ul class="opcrf-movs-list">
                                @foreach ($this->movsModalFiles as $mov)
                                    <li class="opcrf-movs-item" wire:key="upload-mov-{{ $mov->id }}">
                                        <x-icon name="paperclip" :size="14" class="opcrf-movs-item-icon" />
                                        <a class="opcrf-movs-item-name" href="{{ route('opcrf.movs.download', $mov) }}">{{ $mov->original_name }}</a>
                                        <span class="opcrf-movs-item-size">{{ $mov->human_size }}</span>
                                        <button
                                            type="button"
                                            class="users-action users-action--danger users-action--tiny"
                                            wire:click="removeMov({{ $mov->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="removeMov({{ $mov->id }})"
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
                        {{-- One button, two states: locked (no MOVs yet) and
                             unlocked (the requirement is satisfied). --}}
                        <button
                            type="button"
                            class="btn-primary {{ $this->movsModalFiles->isEmpty() ? 'opcrf-waiting-btn' : '' }}"
                            @if ($this->movsModalFiles->isEmpty()) disabled aria-disabled="true" @endif
                            wire:click="finishMovs"
                            wire:loading.attr="disabled"
                            wire:target="finishMovs"
                            title="{{ $this->movsModalFiles->isEmpty() ? 'Attach at least one MOV file to continue' : 'Finish and close' }}"
                        >
                            @if ($this->movsModalFiles->isEmpty())
                                <x-icon name="lock" :size="14" />
                                <span>Upload at least 1 MOV to continue…</span>
                            @else
                                <span wire:loading.remove wire:target="finishMovs">Continue</span>
                                <span wire:loading wire:target="finishMovs">Finishing…</span>
                            @endif
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
