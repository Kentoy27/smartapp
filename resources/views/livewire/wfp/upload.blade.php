<div class="wfp-upload-component">

    {{-- SUCCESS ALERT: popped once after a stored upload, then cleared. --}}
    @if ($successMessage)
        <div
            wire:key="wfp-alert-{{ md5($successMessage) }}"
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

    {{-- UPLOAD BUTTON: sits beside the card's Download button. --}}
    <button
        type="button"
        class="opcrf-upload-btn {{ $errors->has('file') ? 'has-error' : '' }}"
        wire:click="openUpload"
        aria-haspopup="dialog"
        title="Upload your completed WFP xlsx"
    >
        <x-icon name="upload" :size="16" />
        <span>Upload WFP</span>
    </button>

    {{-- UPLOAD WINDOW --}}
    @if ($showUpload)
        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.closeUpload()"
            @click.self="$wire.closeUpload()"
            role="presentation"
        >
            <div class="modal modal--opcr-upload" role="dialog" aria-modal="true" aria-labelledby="wfpUploadTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="wfpUploadTitle">Upload your WFP in here</h2>
                    <button type="button" class="modal-close" wire:click="closeUpload" aria-label="Close" title="Close">×</button>
                </div>

                <p class="opcrf-review-note">
                    Choose your completed <strong>WFP.xlsx</strong>. It is checked
                    against the WFP structure across every worksheet before saving.
                    The full workbook analysis and sheet contents will appear below.
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
                                aria-label="Upload your completed WFP xlsx file"
                            >

                            <span class="opcrf-dropzone-inner" wire:loading.remove wire:target="file">
                                <span class="opcrf-dropzone-badge" aria-hidden="true">
                                    <x-icon name="upload" :size="20" />
                                </span>
                                <span class="opcrf-dropzone-title">Click to choose your WFP file</span>
                                <span class="opcrf-dropzone-sub">.xlsx · every worksheet is analyzed before saving</span>
                            </span>

                            <span class="opcrf-dropzone-inner" wire:loading wire:target="file">
                                <span class="opcrf-dropzone-badge" aria-hidden="true">
                                    <x-icon name="file-spreadsheet" :size="20" />
                                </span>
                                <span class="opcrf-dropzone-title">Checking your WFP…</span>
                                <span class="opcrf-dropzone-sub">Opening the workbook and verifying its structure</span>
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
</div>
