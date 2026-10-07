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

    {{-- ANALYSIS + FULL WORKBOOK REVIEW: findings and every worksheet are
         persisted on the upload, then displayed to the School Head. --}}
    @php($preview = $this->preview)

    @if ($preview !== null)
        @if (isset($preview['error']))
            <div class="card wfp-analysis-error" role="alert">
                <div class="card-title">WFP analysis unavailable</div>
                <p>{{ $preview['error'] }}</p>
            </div>
        @else
            @php($analysis = $preview['analysis'])
            <section class="card wfp-analysis">
                <div class="card-head">
                    <div>
                        <div class="card-title">WFP Analysis &amp; Review</div>
                        <p class="wfp-analysis-intro">The uploaded workbook has been analyzed across all worksheets. Review the extracted workbook contents below.</p>
                    </div>
                    <span class="wfp-analysis-result{{ $preview['validation']['passed'] ? ' is-valid' : ' is-warning' }}">
                        {{ $preview['validation']['passed'] ? 'Structure checked' : 'Review required' }}
                    </span>
                </div>

                <div class="wfp-analysis-stats">
                    <div><strong>{{ $analysis['sheet_count'] }}</strong><span>Worksheets analyzed</span></div>
                    <div><strong>{{ $analysis['row_count'] }}</strong><span>Populated rows reviewed</span></div>
                    <div><strong>{{ $analysis['populated_cell_count'] }}</strong><span>Populated cells reviewed</span></div>
                    <div><strong>{{ $analysis['sections_found'] }} / {{ count($analysis['sections']) }}</strong><span>WFP sections detected</span></div>
                </div>

                <div class="wfp-analysis-sections">
                    <h3>WFP section checks</h3>
                    <ul>
                        @foreach ($analysis['required_headings'] as $heading)
                            <li class="{{ $heading['found'] ? 'is-found' : 'is-missing' }}">
                                <span aria-hidden="true">{{ $heading['found'] ? '✓' : '!' }}</span>
                                <span>Required heading: {{ $heading['label'] }}</span>
                                <strong>{{ $heading['found'] ? 'Found' : 'Not found' }}</strong>
                            </li>
                        @endforeach
                        @foreach ($analysis['sections'] as $section)
                            <li class="{{ $section['found'] ? 'is-found' : 'is-missing' }}">
                                <span aria-hidden="true">{{ $section['found'] ? '✓' : '!' }}</span>
                                <span>{{ $section['label'] }}</span>
                                <strong>{{ $section['found'] ? 'Found' : 'Not found' }}</strong>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if ($preview['validation']['warnings'] !== [])
                    <div class="wfp-analysis-warnings">
                        <strong>Items to review</strong>
                        <ul>
                            @foreach ($preview['validation']['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>

            <div class="wfp-preview-toolbar">
                <input
                    type="search"
                    class="wfp-search"
                    placeholder="Filter all worksheets…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Filter WFP worksheets"
                >
                <span class="wfp-preview-note">Showing {{ count($preview['sheets']) }} worksheets and their populated rows.</span>
            </div>

            @forelse ($this->previewRows as $sheet)
                @php($headerRow = $sheet['header_index'] !== null ? ($sheet['rows'][$sheet['header_index']] ?? null) : null)
                <section class="card wfp-sheet-review" wire:key="wfp-sheet-{{ md5($sheet['name']) }}">
                    <div class="card-head">
                        <div>
                            <div class="card-title">{{ $sheet['name'] }}</div>
                            <p class="wfp-sheet-meta">{{ count($sheet['rows']) }} populated rows · {{ count($sheet['column_indexes']) }} columns in use</p>
                        </div>
                    </div>

                    @if ($sheet['rows'] === [])
                        <p class="wfp-empty">No rows match “{{ $search }}” in this worksheet.</p>
                    @else
                        <div class="wfp-preview-scroll" role="region" aria-label="{{ $sheet['name'] }} WFP contents" tabindex="0">
                            <table class="wfp-preview-table">
                                @if ($headerRow !== null)
                                    <thead>
                                        <tr>
                                            <th class="wfp-preview-rowhead" scope="col">#</th>
                                            @foreach ($sheet['column_indexes'] as $column)
                                                <th scope="col">{{ $headerRow['cells'][$column] ?? 'Column '.$column }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                @endif
                                <tbody>
                                    @foreach ($sheet['rows'] as $row)
                                        @if ($headerRow !== null && $row['number'] === $headerRow['number'])
                                            @continue
                                        @endif
                                        <tr wire:key="wfp-row-{{ md5($sheet['name']) }}-{{ $row['number'] }}">
                                            <th class="wfp-preview-rowhead" scope="row">{{ $row['number'] }}</th>
                                            @foreach ($sheet['column_indexes'] as $column)
                                                <td>{{ $row['cells'][$column] ?? '' }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @empty
                <p class="wfp-empty">No rows match “{{ $search }}” in the uploaded workbook.</p>
            @endforelse
        @endif
    @endif
</div>
