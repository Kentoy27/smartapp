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
        title="Upload your completed OPCRF template"
    >
        <x-icon name="upload" :size="16" />
        <span>Upload Completed OPCRF</span>
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
                    <h2 id="opcrUploadTitle">Upload Completed OPCRF</h2>
                    <button type="button" class="modal-close" wire:click="closeUpload" aria-label="Close" title="Close">×</button>
                </div>

                <p class="opcrf-review-note">
                    Complete the OPCRF template you downloaded from this
                    dashboard, then upload the completed file here.
                </p>

                <ul class="opcrf-upload-requirements">
                    <li>Accepted format: <strong>.xlsx</strong></li>
                    <li>Maximum file size: <strong>10 MB</strong></li>
                    <li>Any name will do — if the file does not match your account, your reviewer is simply told.</li>
                </ul>

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
                                <span class="opcrf-dropzone-title">Choose OPCRF File</span>
                                <span class="opcrf-dropzone-sub">The completed .xlsx template · analyzed automatically · nothing is saved before you review it</span>
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

                {{-- UPLOAD CHECK: what the system could tell from the workbook.
                     A match is confirmed plainly; anything else is a heads-up
                     only — it never blocks the upload, and it travels with the
                     submission so the reviewer sees the same thing. --}}
                @if ($nameNote !== null)
                    <div class="opcrf-verified opcrf-verified--note">
                        <x-icon name="alert-triangle" :size="14" />
                        <span>
                            <strong>Heads up</strong> — {{ $nameNote }}
                            You can still submit this form.
                            @if ($templateVersion)
                                <span class="opcrf-verified-version">Template v{{ $templateVersion }}</span>
                            @endif
                        </span>
                    </div>
                @elseif ($verifiedName !== '')
                    <div class="opcrf-verified">
                        <x-icon name="check" :size="14" />
                        <span>
                            <strong>Name verified</strong> — “{{ $verifiedName }}” matches your account.
                            @if ($templateVersion)
                                <span class="opcrf-verified-version">Template v{{ $templateVersion }}</span>
                            @endif
                        </span>
                    </div>
                @endif

                <div class="modal-form opcrf-review-body">
                    <div class="opcrf-review-summary opcrf-review-summary--sheet">
                        {{-- EXCEL-WINDOW: the whole workbook in a landscape
                             frame with a bottom sheet-tab strip (All / PART
                             I-IV / extras) like Excel's own tab bar. --}}
                        @include('livewire.opcrf.partials.opcrf-excelwin', [
                            'sheet' => $sheet,
                            'rating' => $self_rating,
                            'full' => true,
                            'workbook' => $workbook,
                            'uid' => 'up',
                        ])

                        <p class="opcrf-review-confirm-line">
                            Uploaded by <strong>{{ auth()->user()->username }}</strong>
                            on {{ now()->format('M j, Y g:i A') }}.
                        </p>
                    </div>

                    {{-- ROUTING: automatic — the system sends every
                         submission to the assigned superadmin (Eve), so
                         the staff member picks nothing here. --}}
                    <p class="opcrf-review-route-sub">
                        Submitting sends this OPCR to the assigned superadmin
                        for review.
                    </p>

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
                            <span wire:loading.remove wire:target="confirmSubmit">Submit OPCRF</span>
                            <span wire:loading wire:target="confirmSubmit">Submitting…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
