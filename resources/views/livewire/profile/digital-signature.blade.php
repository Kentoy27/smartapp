{{-- The Electronic Signature card — one account, one signature. The same card is
     rendered on the profile page for a user and for a superadmin; only the
     account behind it differs.

     Picking a file shows it here (name, size, real preview) and saves
     nothing: the choice can still be changed or cancelled. "Save signature"
     is what writes it, and it never touches the stored signature until the
     new file is safely on disk. --}}
{{-- The same limits the server enforces are checked here first, in the capture
     phase so a rejected file is never handed to the uploader at all: the user
     gets the message immediately, and nothing is sent for a file that cannot
     be accepted. The server-side rules stay the real boundary. --}}
<div class="sig-card"
     x-data="{
        clientError: null,
        maxKb: {{ (int) $maxKb }},
        allowed: ['image/png', 'image/jpeg', 'image/webp'],
        mode: 'upload',
        drawing: false,
        hasInk: false,
        pixelRatio: 1,
        context: null,
        initCanvas(canvas) {
            if (canvas.clientWidth < 1) return;
            this.pixelRatio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = Math.round(canvas.clientWidth * this.pixelRatio);
            canvas.height = Math.round(canvas.clientHeight * this.pixelRatio);
            this.context = canvas.getContext('2d');
            this.context.scale(this.pixelRatio, this.pixelRatio);
            this.context.strokeStyle = '#0f172a';
            this.context.lineWidth = 2.4;
            this.context.lineCap = 'round';
            this.context.lineJoin = 'round';
        },
        openDrawing() {
            this.mode = 'draw';
            this.$nextTick(() => {
                if (! this.context) this.initCanvas(this.$refs.drawCanvas);
            });
        },
        startDrawing(event) {
            if (this.mode !== 'draw' || ! this.context) return;
            this.drawing = true;
            this.hasInk = true;
            event.currentTarget.setPointerCapture(event.pointerId);
            this.context.beginPath();
            this.context.arc(event.offsetX, event.offsetY, this.context.lineWidth / 2, 0, Math.PI * 2);
            this.context.fill();
            this.context.beginPath();
            this.context.moveTo(event.offsetX, event.offsetY);
        },
        continueDrawing(event) {
            if (! this.drawing || ! this.context) return;
            this.context.lineTo(event.offsetX, event.offsetY);
            this.context.stroke();
        },
        stopDrawing() {
            this.drawing = false;
        },
        clearDrawing() {
            if (! this.context) return;
            this.context.clearRect(0, 0, this.$refs.drawCanvas.clientWidth, this.$refs.drawCanvas.clientHeight);
            this.hasInk = false;
        },
        useDrawing() {
            if (! this.hasInk) {
                this.clientError = 'Please draw your signature before continuing.';
                return;
            }

            const source = this.$refs.drawCanvas;
            const pixels = this.context.getImageData(0, 0, source.width, source.height).data;
            let left = source.width;
            let top = source.height;
            let right = -1;
            let bottom = -1;

            for (let y = 0; y < source.height; y++) {
                for (let x = 0; x < source.width; x++) {
                    if (pixels[(y * source.width + x) * 4 + 3] === 0) continue;
                    left = Math.min(left, x);
                    top = Math.min(top, y);
                    right = Math.max(right, x);
                    bottom = Math.max(bottom, y);
                }
            }

            if (right < left || bottom < top) {
                this.clientError = 'Please draw your signature before continuing.';
                return;
            }

            const padding = Math.round(8 * this.pixelRatio);
            const width = right - left + 1;
            const height = bottom - top + 1;
            const cropped = document.createElement('canvas');
            cropped.width = width + padding * 2;
            cropped.height = height + padding * 2;
            cropped.getContext('2d').drawImage(source, left, top, width, height, padding, padding, width, height);

            cropped.toBlob((blob) => {
                if (! blob) {
                    this.clientError = 'The drawn signature could not be prepared. Please try again.';
                    return;
                }

                const file = new File([blob], 'drawn-signature.png', { type: 'image/png' });
                const transfer = new DataTransfer();
                transfer.items.add(file);
                this.$refs.sigFile.files = transfer.files;
                this.mode = 'upload';
                this.$refs.sigFile.dispatchEvent(new Event('change', { bubbles: true }));
            }, 'image/png');
        },
        check(input) {
            this.clientError = null;

            const file = input.files && input.files[0];
            if (! file) return;

            if (! this.allowed.includes(file.type)) {
                input.value = '';
                this.clientError = 'The signature must be a {{ $formatHint }} image.';
                return;
            }

            if (file.size > this.maxKb * 1024) {
                input.value = '';
                this.clientError = 'The signature image is too large ({{ round($maxKb / 1024, 1) }} MB maximum).';
            }
        }
     }"
     x-on:change.capture="check($event.target)"
     x-on:signature-rejected.window="clientError = null; $refs.sigFile.value = ''">
    <div class="sig-card-head">
        <div class="sig-card-heading">
            <h2 class="sig-card-title">
                <x-icon name="pen-line" :size="18" />
                Electronic Signature
            </h2>
            <p class="sig-card-sub">
                Your signature is stamped onto the documents the system generates for you.
            </p>
        </div>

        <span class="sig-badge {{ $account->hasSignature() ? 'sig-badge--ok' : 'sig-badge--empty' }}">
            <x-icon :name="$account->hasSignature() ? 'check-circle' : 'alert-triangle'" :size="14" />
            {{ $account->signatureStatusLabel() }}
        </span>
    </div>

    <div class="sig-method-switch" role="group" aria-label="Choose how to add your signature">
        <button type="button" class="sig-method-button"
                x-on:click="mode = 'upload'"
                x-bind:class="{ 'is-active': mode === 'upload' }"
                x-bind:aria-pressed="(mode === 'upload').toString()">
            <x-icon name="upload" :size="15" /> Upload an image
        </button>
        <button type="button" class="sig-method-button"
                x-on:click="openDrawing()"
                x-bind:class="{ 'is-active': mode === 'draw' }"
                x-bind:aria-pressed="(mode === 'draw').toString()">
            <x-icon name="pen-line" :size="15" /> Draw a signature
        </button>
    </div>

    {{-- SUCCESS / ERROR MESSAGES --}}
    @if ($successMessage)
        <div class="sig-alert sig-alert--ok" role="status">
            <x-icon name="check-circle" :size="16" />
            <span>{{ $successMessage }}</span>
            <button type="button" class="sig-alert-close" wire:click="clearSuccess" aria-label="Dismiss message" title="Dismiss">×</button>
        </div>
    @endif

    @error('signature')
        <div class="sig-alert sig-alert--error" role="alert">
            <x-icon name="alert-triangle" :size="16" />
            <span>{{ $message }}</span>
        </div>
    @enderror

    {{-- A file the browser itself refused: nothing was uploaded. --}}
    <template x-if="clientError">
        <div class="sig-alert sig-alert--error" role="alert">
            <x-icon name="alert-triangle" :size="16" />
            <span x-text="clientError"></span>
        </div>
    </template>

    <div class="sig-grid" x-show="mode === 'upload'" x-cloak>

        {{-- PREVIEW: the picked file first, then the saved signature, then
             the empty state. --}}
        <div class="sig-preview">
            <span class="sig-preview-label">Preview</span>

            <div class="sig-preview-box">
                @if ($signature)
                    @if ($pendingPreviewUrl)
                        <img src="{{ $pendingPreviewUrl }}" alt="Preview of the signature you selected" class="sig-preview-img">
                    @else
                        <div class="sig-preview-generic">
                            <x-icon name="image" :size="28" />
                            <span>Ready to save</span>
                        </div>
                    @endif
                @elseif ($account->hasSignature())
                    <img src="{{ $account->signatureUrl() }}" alt="Your saved electronic signature" class="sig-preview-img">
                @else
                    <div class="sig-preview-generic">
                        <x-icon name="image" :size="28" />
                        <span>No signature yet</span>
                    </div>
                @endif

                {{-- The uploaded file is being transferred to the server. --}}
                <div class="sig-preview-loading" wire:loading wire:target="signature">
                    <span class="sig-spinner" aria-hidden="true"></span>
                    <span>Uploading…</span>
                </div>
            </div>

            @if ($signature)
                <span class="sig-preview-note sig-preview-note--pending">
                    Not saved yet — choose “Save signature” to keep this image.
                </span>
            @endif
        </div>

        {{-- DETAILS: the state of the saved signature, or the picked file
             waiting to replace it. --}}
        <div class="sig-details">
            <dl class="sig-meta">
                <dt>Status</dt>
                <dd>
                    <span class="sig-status-text {{ $account->hasSignature() ? 'is-ok' : '' }}">
                        {{ $account->signatureStatusLabel() }}
                    </span>
                </dd>

                <dt>File name</dt>
                <dd>{{ $account->signature_original_name ?? '—' }}</dd>

                <dt>File size</dt>
                <dd>{{ $account->signatureHumanSize() ?? '—' }}</dd>

                <dt>Uploaded</dt>
                <dd>{{ $account->signatureUploadedAt()?->format('M j, Y g:i A') ?? '—' }}</dd>

                <dt>Last updated</dt>
                <dd>{{ $account->signatureUpdatedAt()?->format('M j, Y g:i A') ?? '—' }}</dd>
            </dl>

            {{-- The picked file's own details, so it can be checked before it
                 is saved. --}}
            @if ($signature)
                <div class="sig-pending">
                    <span class="sig-pending-title">
                        <x-icon name="upload" :size="14" /> Selected file (not saved yet)
                    </span>
                    <dl class="sig-meta sig-meta--pending">
                        <dt>File name</dt>
                        <dd>{{ $signature->getClientOriginalName() }}</dd>

                        <dt>File size</dt>
                        <dd>{{ number_format($signature->getSize() / 1024, 1) }} KB</dd>
                    </dl>
                    <button type="button" class="sig-link" wire:click="cancelSelection" wire:loading.attr="disabled" wire:target="cancelSelection,save">
                        Cancel this file
                    </button>
                </div>
            @endif
        </div>
    </div>

    <section class="sig-draw-panel" x-show="mode === 'draw'" x-cloak aria-label="Draw your signature">
        <div class="sig-draw-heading">
            <div>
                <h3>Draw your signature</h3>
                <p>Use your mouse, finger, or stylus inside the box.</p>
            </div>
            <span class="sig-draw-hint">Your drawing stays private</span>
        </div>
        <div class="sig-draw-board">
            <canvas
                x-ref="drawCanvas"
                x-init="initCanvas($el)"
                x-on:pointerdown="startDrawing($event)"
                x-on:pointermove="continueDrawing($event)"
                x-on:pointerup="stopDrawing()"
                x-on:pointercancel="stopDrawing()"
                aria-label="Signature drawing board"
                tabindex="0"
            ></canvas>
            <span class="sig-draw-baseline" aria-hidden="true"></span>
            <span class="sig-draw-placeholder" x-show="!hasInk" x-cloak>Sign here</span>
        </div>
        <div class="sig-draw-actions">
            <button type="button" class="btn-ghost" x-on:click="clearDrawing()" x-bind:disabled="!hasInk">
                <x-icon name="trash" :size="15" /> Clear board
            </button>
            <button type="button" class="btn-primary" x-on:click="useDrawing()">
                <x-icon name="check" :size="15" /> Use this signature
            </button>
        </div>
    </section>

    {{-- INSTRUCTIONS --}}
    <div class="sig-instructions">
        <span>Accepted formats: <strong>{{ $formatHint }}</strong>.</span>
        <span>Maximum file size: <strong>{{ round($maxKb / 1024, 1) }} MB</strong>.</span>
        <span>A <strong>transparent PNG</strong> is recommended for official documents.</span>
    </div>

    {{-- ACTIONS --}}
    <div class="sig-actions">
        {{-- The real file input is visually hidden and opened by its label, so
             the button keeps the page's styling while staying a native picker
             (keyboard and screen-reader friendly). --}}
        <label class="btn-primary sig-file-label" for="signatureFile" x-show="mode === 'upload'">
            <x-icon name="upload" :size="16" />
            <span wire:loading.remove wire:target="signature">
                {{ $account->hasSignature() ? 'Change Signature' : 'Upload Electronic Signature' }}
            </span>
            <span wire:loading wire:target="signature">Uploading…</span>
        </label>

        <input
            id="signatureFile"
            x-ref="sigFile"
            type="file"
            class="sig-file-input"
            accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp"
            wire:model="signature"
            aria-describedby="signatureHelp"
        >

        @if ($signature)
            <button type="button" class="btn-primary" wire:click="save"
                    wire:loading.attr="disabled" wire:target="save,signature">
                <span wire:loading.remove wire:target="save">
                    <x-icon name="check" :size="16" /> Save signature
                </span>
                <span wire:loading wire:target="save">
                    <span class="sig-spinner sig-spinner--button" aria-hidden="true"></span> Saving…
                </span>
            </button>

            <button type="button" class="btn-ghost" wire:click="cancelSelection"
                    wire:loading.attr="disabled" wire:target="save">
                Cancel
            </button>
        @endif

        @if ($account->hasSignature() && ! $signature)
            <button type="button" class="btn-danger" wire:click="openRemoveModal">
                <x-icon name="trash" :size="16" /> Remove Signature
            </button>
        @endif
    </div>

    <p id="signatureHelp" class="sig-help">
        Your signature is stored privately and only ever shown to you — it is never placed
        in a public folder. Replacing it keeps the date of your first upload.
    </p>

    {{-- REMOVE CONFIRMATION: rendered only while it is open, so the page never
         carries a hidden "remove" action for an account that has nothing to
         remove. --}}
    @if ($showRemoveModal)
    <div class="modal-backdrop is-open"
         @click="self && $wire.closeRemoveModal()"
         role="presentation">
        <div class="modal modal--confirmation" role="dialog" aria-modal="true" aria-labelledby="signatureRemoveTitle" @click.stop>
            <div class="modal-head">
                <h2 id="signatureRemoveTitle">Remove Electronic Signature</h2>
                <button type="button" class="modal-close" wire:click="closeRemoveModal" aria-label="Close" title="Close">×</button>
            </div>

            <div class="modal-form">
                <p class="delete-modal-description">
                    Are you sure you want to remove your electronic signature?
                    @if ($account->signature_original_name)
                        <strong>{{ $account->signature_original_name }}</strong> will be deleted.
                    @endif
                    Documents generated after this will have no signature until you upload a new one.
                </p>

                <div class="modal-actions">
                    <button type="button" class="btn-ghost" wire:click="closeRemoveModal">Cancel</button>
                    <button type="button" class="btn-danger" wire:click="removeSignature"
                            wire:loading.attr="disabled" wire:target="removeSignature">
                        <span wire:loading.remove wire:target="removeSignature">Remove Signature</span>
                        <span wire:loading wire:target="removeSignature">Removing…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
