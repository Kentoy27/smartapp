<div class="sip-table-component">
    @php($uploadableDivisions = $this->uploadableDivisions())

    @if ($successMessage)
        <div
            wire:key="sip-success-{{ md5($successMessage) }}"
            x-data
            x-init="if (window.smartAlert) { smartAlert.success({{ \Illuminate\Support\Js::from($successMessage) }}); } $wire.clearSuccess();"
            class="success-alert-sentinel"
            aria-hidden="true"
        ></div>
    @endif

    <div class="card">
        <div class="card-head sip-toolbar">
            <label class="sip-search">
                <span class="sr-only">Search SIP records</span>
                <input
                    type="search"
                    placeholder="Search school, district, or School ID…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Search SIP records"
                >
            </label>
            <span class="sip-table-count">
                {{ $this->schools->total() }} {{ $this->schools->total() === 1 ? 'school' : 'schools' }}
            </span>
            @if ($uploadableDivisions !== [])
                <button type="button" class="btn-primary sip-bulk-upload-trigger" wire:click="openBulkUploadModal">
                    <x-icon name="upload" :size="14" />
                    <span>Upload SIP</span>
                </button>
            @endif
        </div>

        @if (auth()->user()?->role === 'user' && auth()->user()?->school_id === null)
            <p class="card-text">Your account is not assigned to a school yet. Ask an administrator to assign your school.</p>
        @elseif ($this->schools->isEmpty())
            <p class="card-text">
                @if (trim($search) !== '')
                    No schools match that search.
                @else
                    No schools are available.
                @endif
            </p>
        @else
            <div class="table-wrap">
                <table class="data-table sip-table">
                    <thead>
                        <tr>
                            <th scope="col">Division</th>
                            <th scope="col">School</th>
                            <th scope="col">District</th>
                            <th scope="col">SIP file</th>
                            <th scope="col">Uploaded by</th>
                            <th scope="col">Last uploaded</th>
                            <th scope="col" class="users-actions-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->schools as $school)
                            @foreach ($this->visibleDivisions($school) as $division)
                                @php($submission = $school->sipSubmissions->firstWhere('division', $division))
                                <tr wire:key="sip-{{ $school->id }}-{{ $division }}">
                                    <td>{{ \App\Support\SchoolDivision::label($division) }}</td>
                                    <td>
                                        <span class="cell-strong">{{ $school->name }}</span>
                                        @if ($school->school_id)
                                            <span class="cell-sub">School ID: {{ $school->school_id }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $school->district?->name ?? '—' }}</td>
                                    <td>
                                        @if ($submission)
                                            <span class="cell-strong">{{ $submission->original_file_name }}</span>
                                            <span class="cell-sub">{{ number_format($submission->file_size / 1024, 1) }} KB</span>
                                        @else
                                            <span class="badge badge-muted">Not uploaded</span>
                                        @endif
                                    </td>
                                    <td>{{ $submission?->uploader?->name ?: ($submission?->uploader?->username ?? '—') }}</td>
                                    <td>{{ $submission?->uploaded_at?->format('M j, Y g:i A') ?? '—' }}</td>
                                    <td class="users-actions-col">
                                        <div class="sip-row-actions">
                                            @if ($submission?->hasFile())
                                                @if (in_array(strtolower(pathinfo($submission->original_file_name, PATHINFO_EXTENSION)), ['pdf', 'doc', 'docx'], true))
                                                    <button
                                                        type="button"
                                                        class="btn-ghost"
                                                        wire:click="openPreview({{ $submission->id }})"
                                                        title="Preview {{ $school->name }} {{ \App\Support\SchoolDivision::label($division) }} SIP"
                                                    >
                                                        <span>Preview</span>
                                                    </button>
                                                @endif
                                                <a
                                                    href="{{ route('sip.download', $submission) }}"
                                                    class="btn-ghost"
                                                    title="Download {{ $school->name }} {{ \App\Support\SchoolDivision::label($division) }} SIP"
                                                >
                                                    <x-icon name="download" :size="14" />
                                                    <span>Download</span>
                                                </a>
                                            @endif

                                            @if ($this->canUploadTo($school, $division))
                                                <button
                                                    type="button"
                                                    class="users-action"
                                                    wire:click="openUploadModal({{ $school->id }}, '{{ $division }}')"
                                                    title="{{ $submission ? 'Replace' : 'Upload' }} this division's SIP"
                                                >
                                                    <x-icon name="upload" :size="14" />
                                                    <span>{{ $submission ? 'Replace' : 'Upload' }}</span>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($this->schools->lastPage() > 1)
                <div class="sip-pagination" aria-label="SIP table pagination">
                    <span class="users-count">
                        Page {{ $this->schools->currentPage() }} of {{ $this->schools->lastPage() }}
                    </span>
                    <div class="sip-pagination__actions">
                        <button
                            type="button"
                            class="btn-ghost"
                            wire:click="previousPage"
                            @disabled($this->schools->onFirstPage())
                        >Previous</button>
                        <button
                            type="button"
                            class="btn-ghost"
                            wire:click="nextPage"
                            @disabled(! $this->schools->hasMorePages())
                        >Next</button>
                    </div>
                </div>
            @endif
        @endif
    </div>

    @if ($showBulkUploadModal)
        <div class="modal-backdrop is-open" @mousedown.self="$wire.closeBulkUploadModal()" @keydown.escape.window="$wire.closeBulkUploadModal()" role="presentation">
            <section class="modal sip-bulk-upload-modal" role="dialog" aria-modal="true" aria-labelledby="sipBulkUploadTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="sipBulkUploadTitle">Upload SIP</h2>
                    <button type="button" class="modal-close" wire:click="closeBulkUploadModal" aria-label="Close" title="Close">×</button>
                </div>

                <form wire:submit="uploadMultipleSip" class="modal-form">
                    <p class="field-hint">
                        @if (count($uploadableDivisions) > 1)
                            Add one file for each division you want to upload. Each file replaces the current SIP for its selected division.
                        @else
                            Upload the SIP for your division. This file replaces the current SIP.
                        @endif
                    </p>

                    <div class="sip-bulk-upload-rows">
                        @foreach ($bulkUploads as $index => $upload)
                            @php($otherDivisions = array_column(array_diff_key($bulkUploads, [$index => true]), 'division'))
                            <div class="sip-bulk-upload-row {{ count($uploadableDivisions) === 1 ? 'sip-bulk-upload-row--single-division' : '' }}" wire:key="sip-bulk-upload-{{ $index }}">
                                @if (count($uploadableDivisions) > 1)
                                    <div class="field">
                                        <label for="bulkSipDivision{{ $index }}">Division</label>
                                        <select id="bulkSipDivision{{ $index }}" wire:model="bulkUploads.{{ $index }}.division" required>
                                            @foreach ($uploadableDivisions as $divisionOption)
                                                @if ($divisionOption === $upload['division'] || ! in_array($divisionOption, $otherDivisions, true))
                                                    <option value="{{ $divisionOption }}">{{ \App\Support\SchoolDivision::label($divisionOption) }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                        @error("bulkUploads.{$index}.division") <div class="error-text">{{ $message }}</div> @enderror
                                    </div>
                                @endif
                                <div class="field">
                                    <label for="bulkSipFile{{ $index }}">SIP document</label>
                                    <input
                                        id="bulkSipFile{{ $index }}"
                                        type="file"
                                        wire:model="bulkUploads.{{ $index }}.file"
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
                                        required
                                    >
                                    <div class="field-hint">PDF, Word, Excel, or PowerPoint; maximum 12 MB.</div>
                                    @error("bulkUploads.{$index}.file") <div class="error-text">{{ $message }}</div> @enderror
                                </div>
                                @if (count($bulkUploads) > 1)
                                    <button
                                        type="button"
                                        class="btn-ghost sip-bulk-upload-remove"
                                        wire:click="removeBulkUploadRow({{ $index }})"
                                        aria-label="Remove SIP file {{ $index + 1 }}"
                                    >Remove</button>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if (count($uploadableDivisions) > 1)
                        <button
                            type="button"
                            class="btn-ghost sip-bulk-upload-add"
                            wire:click="addBulkUploadRow"
                            @disabled(count($bulkUploads) >= count($uploadableDivisions))
                        >Add another SIP</button>
                    @endif
                    @error('bulkUploads') <div class="error-text">{{ $message }}</div> @enderror

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeBulkUploadModal">Cancel</button>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="uploadMultipleSip">
                            <span wire:loading.remove wire:target="uploadMultipleSip">Upload SIP</span>
                            <span wire:loading wire:target="uploadMultipleSip">Uploading…</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>
    @endif

    @if ($showUploadModal)
        <div class="modal-backdrop is-open" @mousedown.self="$wire.closeUploadModal()" role="presentation">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="sipUploadTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="sipUploadTitle">Upload SIP</h2>
                    <button type="button" class="modal-close" wire:click="closeUploadModal" aria-label="Close" title="Close">×</button>
                </div>

                <form wire:submit="uploadSip" class="modal-form">
                    <p class="field-hint">
                        Upload the {{ \App\Support\SchoolDivision::label($uploadDivision) }} SIP.
                        A new upload replaces the current file for this division.
                    </p>
                    <div class="field">
                        <label for="sipFile">SIP document</label>
                        <input
                            id="sipFile"
                            type="file"
                            wire:model="sipFile"
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
                            required
                        >
                        <div class="field-hint">PDF, Word, Excel, or PowerPoint; maximum 12 MB.</div>
                        @error('sipFile') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeUploadModal">Cancel</button>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="uploadSip">
                            <span wire:loading.remove wire:target="uploadSip">Save SIP</span>
                            <span wire:loading wire:target="uploadSip">Uploading…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($previewSubmissionId !== null)
        @php($previewSubmission = $this->previewSubmission)
        <div
            class="modal-backdrop is-open"
            wire:key="sip-preview-backdrop-{{ $previewSubmission->id }}"
            @mousedown.self="$wire.closePreviewModal()"
            @keydown.escape.window="$wire.closePreviewModal()"
            role="presentation"
        >
            <section
                class="modal sip-preview-modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="sipPreviewTitle"
                @click.stop
            >
                <div class="modal-head sip-preview-modal__head">
                    <div>
                        <h2 id="sipPreviewTitle">{{ $previewSubmission->school?->name }} — SIP Preview</h2>
                        <p>{{ $previewSubmission->original_file_name }}</p>
                    </div>
                    <div class="sip-preview-modal__actions">
                        <a class="btn-ghost" href="{{ route('sip.download', $previewSubmission) }}">
                            <x-icon name="download" :size="14" />
                            <span>Download</span>
                        </a>
                        <button
                            type="button"
                            class="modal-close"
                            wire:click="closePreviewModal"
                            aria-label="Close preview"
                            title="Close preview"
                        >×</button>
                    </div>
                </div>

                @if (strtolower(pathinfo($previewSubmission->original_file_name, PATHINFO_EXTENSION)) === 'pdf')
                    <div
                        class="sip-pdf-preview"
                        x-data="sipPdfPreview()"
                        x-init="load($el)"
                        data-pdf-url="{{ route('sip.preview', $previewSubmission) }}"
                        data-pdfjs-url="{{ public_asset('vendor/pdfjs/pdf.min.js') }}"
                        data-pdf-worker-url="{{ public_asset('vendor/pdfjs/pdf.worker.min.js') }}"
                        data-cmap-url="{{ public_asset('vendor/pdfjs/cmaps').'/' }}"
                        data-standard-font-url="{{ public_asset('vendor/pdfjs/standard_fonts').'/' }}"
                        wire:key="sip-pdf-preview-{{ $previewSubmission->id }}"
                    >
                        <div class="sip-pdf-preview__status" x-show="loading" role="status">Loading SIP PDF…</div>
                        <div class="sip-pdf-preview__status sip-pdf-preview__status--error" x-show="error" x-text="error" role="alert"></div>
                        <div class="sip-pdf-preview__viewport" x-show="!loading && !error">
                            <div class="sip-pdf-preview__pages"></div>
                        </div>
                    </div>
                @else
                    <iframe
                        class="sip-preview-modal__frame"
                        wire:key="sip-preview-frame-{{ $previewSubmission->id }}"
                        src="{{ route('sip.preview', $previewSubmission) }}"
                        title="Preview of {{ $previewSubmission->original_file_name }}"
                    ></iframe>
                @endif
            </section>
        </div>
    @endif
</div>
