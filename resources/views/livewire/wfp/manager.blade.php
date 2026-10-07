<div class="wfp-manager">

    {{-- SUCCESS ALERT --}}
    @if ($successMessage)
        <div
            wire:key="wfp-mgr-alert-{{ md5($successMessage) }}"
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

    {{-- TEMPLATE CARD: download the official template / upload a completed one. --}}
    <div class="card">
        <div class="card-head">
            <div class="card-title">WFP Template</div>
        </div>
        <div class="opcr-template-row">
            <span class="opcr-file-badge" aria-hidden="true">
                <x-icon name="file-spreadsheet" :size="22" />
            </span>
            <div class="opcr-file-meta">
                <span class="opcr-file-name">{{ $fileName }}</span>
                <span class="opcr-file-sub">{{ $templateDescription }}</span>
            </div>
            <a href="{{ route('wfp.template') }}" class="opcr-download-btn" download>
                <x-icon name="download" :size="16" />
                <span>Download</span>
            </a>
            <livewire:wfp-upload :key="'wfp-upload-page'" />
        </div>
    </div>

    {{-- STATUS CARD: the uploaded plan's metadata and actions. --}}
    @php($submission = $this->submission)

    <div class="card">
        <div class="card-head">
            <div class="card-title">Uploaded WFP</div>
        </div>

        @if ($submission === null)
            <p class="wfp-empty">
                No WFP uploaded yet — download the template above, complete it, then
                upload it here. Your status and a preview will appear in this space.
            </p>
        @else
            <div class="opcr-template-row">
                <span class="opcr-file-badge" aria-hidden="true">
                    <x-icon name="file-spreadsheet" :size="22" />
                </span>
                <div class="opcr-file-meta">
                    <span class="opcr-file-name">{{ $submission->original_file_name }}</span>
                    @if ($submission->school_year || $submission->school_name)
                        <span class="opcr-file-sub">
                            {{ trim(($submission->school_year ? $submission->school_year.' ' : '').($submission->school_name ?? '')) }}
                        </span>
                    @endif
                </div>

                @if ($submission->hasFile())
                    <a href="{{ route('wfp.download', $submission) }}" class="opcr-download-btn" download>
                        <x-icon name="download" :size="16" />
                        <span>Download</span>
                    </a>
                @endif

                <button
                    type="button"
                    class="wfp-remove-btn"
                    x-data
                    @click="
                        window.smartAlert.confirmDelete({
                            title: 'Remove your WFP?',
                            text: 'The uploaded file will be deleted. You can upload a new one afterward.',
                            confirmButtonText: 'Yes, remove'
                        }).then(r => { if (r.isConfirmed) $wire.deleteSubmission(); });
                    "
                >
                    <x-icon name="trash" :size="16" />
                    <span>Remove</span>
                </button>
            </div>

            <dl class="wfp-status-meta">
                <div class="wfp-status-item">
                    <dt>Status</dt>
                    <dd><span class="wfp-status-pill">{{ ucfirst($submission->status) }}</span></dd>
                </div>
                <div class="wfp-status-item">
                    <dt>Uploaded</dt>
                    <dd>{{ optional($submission->uploaded_at)->format('M j, Y g:i A') ?? '—' }}</dd>
                </div>
                <div class="wfp-status-item">
                    <dt>Uploaded by</dt>
                    <dd>{{ $submission->user?->username ?? '—' }}</dd>
                </div>
                <div class="wfp-status-item">
                    <dt>File type</dt>
                    <dd>{{ $submission->mime_type ?: '.xlsx' }}</dd>
                </div>
                <div class="wfp-status-item">
                    <dt>Size</dt>
                    <dd>{{ $submission->human_size }}</dd>
                </div>
            </dl>
        @endif
    </div>

    {{-- PREVIEW CARD: a summarized, searchable view of the workbook. --}}
    @php($preview = $this->preview)

    @if ($preview !== null)
        @php($grid = $preview['grid'])
        @php($headerRow = $grid['header_index'] !== null ? $grid['rows'][$grid['header_index']] : null)

        <div class="card">
            <div class="card-head">
                <div class="card-title">WFP Preview</div>
            </div>

            <div class="wfp-preview-toolbar">
                <input
                    type="search"
                    class="wfp-search"
                    placeholder="Filter by program, activity, objective…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Filter WFP preview"
                >
                @if ($grid['truncated'])
                    <span class="wfp-preview-note">Showing the first {{ count($grid['rows']) }} rows.</span>
                @endif
            </div>

            @if (count($this->previewRows) === 0)
                <p class="wfp-empty">No rows match “{{ $search }}”.</p>
            @else
                <div class="wfp-preview-scroll">
                    <table class="wfp-preview-table">
                        @if ($headerRow !== null)
                            <thead>
                                <tr>
                                    <th class="wfp-preview-rowhead" scope="col">#</th>
                                    @foreach ($headerRow['cells'] as $cell)
                                        <th scope="col">{{ $cell }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                        @endif
                        <tbody>
                            @foreach ($this->previewRows as $row)
                                @if ($headerRow !== null && $row['number'] === $headerRow['number'])
                                    @continue
                                @endif
                                <tr wire:key="wfp-row-{{ $row['number'] }}">
                                    <th class="wfp-preview-rowhead" scope="row">{{ $row['number'] }}</th>
                                    @foreach ($row['cells'] as $cell)
                                        <td>{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
