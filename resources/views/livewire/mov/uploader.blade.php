<div class="mov-page" wire:poll.30s>
    @if ($successMessage)
        <div
            wire:key="mov-alert-{{ md5($successMessage) }}"
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

    @php($progress = $this->progress())
    <section class="mov-progress-panel" aria-labelledby="mov-progress-title">
        <div class="mov-progress-panel__head">
            <div>
                <h2 id="mov-progress-title">MOV Submission Progress</h2>
                <p>{{ $progress['done'] }} of {{ $progress['required'] }} required MOVs completed</p>
            </div>
            <strong>{{ $progress['percent'] }}%</strong>
        </div>
        <div
            class="mov-progress-panel__track"
            role="progressbar"
            aria-label="Required MOV submission progress"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="{{ $progress['percent'] }}"
        >
            <span style="width: {{ $progress['percent'] }}%"></span>
        </div>
        @if ($progress['returned'] > 0)
            <p class="mov-progress-panel__note">
                {{ $progress['returned'] }} {{ \Illuminate\Support\Str::plural('picture', $progress['returned']) }} returned for revision.
            </p>
        @endif
    </section>

    @if ($focused !== null && ($mov = $this->focusedMov()))
        <article class="mov-evidence" id="mov-{{ $focused }}" data-mov-focus wire:key="mov-evidence-{{ $focused }}">
            <header class="mov-evidence__header">
                <div>
                    <div class="mov-evidence__eyebrow">{{ $mov['trail'] }}</div>
                    <div class="mov-evidence__title-row">
                        <h1>{{ $mov['label'] }}</h1>
                        @if ($mov['required'])
                            <span class="mov-required">Required</span>
                        @else
                            <span class="mov-optional">Optional</span>
                        @endif
                    </div>
                    <h2 class="mov-evidence__title">{{ $mov['title'] }}</h2>
                    @if ($mov['description'] !== '')
                        <p class="mov-evidence__description">{{ $mov['description'] }}</p>
                    @endif
                </div>
                <div class="mov-evidence__status">
                    <span class="mov-evidence__status-label">Submission status</span>
                    <span class="badge {{ $mov['submission_status_class'] }}">{{ $mov['submission_status'] }}</span>
                </div>
            </header>

            <section class="mov-evidence__records" aria-label="Uploaded evidence">
                @if ($mov['count'] > 0)
                    <div class="mov-evidence__records-head">
                        <div>
                            <h2>Evidence files</h2>
                            <p>{{ $mov['summary'] }}</p>
                        </div>
                        <span>{{ $mov['count'] }} {{ \Illuminate\Support\Str::plural('file', $mov['count']) }}</span>
                    </div>

                    <div class="mov-table-scroll" role="region" aria-label="Evidence files for {{ $mov['trail'] }}" tabindex="0">
                        <table class="mov-evidence-table">
                            <thead>
                                <tr>
                                    <th scope="col">Picture</th>
                                    <th scope="col">File Name</th>
                                    <th scope="col">Date Uploaded</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($mov['pictures'] as $picture)
                                    <tr
                                        class="{{ $picture['status'] === \App\Models\UserMov::STATUS_RETURNED ? 'is-returned' : '' }}"
                                        wire:key="mov-picture-{{ $picture['id'] }}"
                                    >
                                        <td data-label="Picture">
                                            @if ($picture['has_file'])
                                                <a href="{{ route('mov.view', $picture['id']) }}" target="_blank" rel="noopener" class="mov-evidence-thumb-link" aria-label="View {{ $picture['name'] }}">
                                                    <img
                                                        class="mov-evidence-thumb"
                                                        src="{{ route('mov.view', $picture['id']) }}"
                                                        alt="Preview of {{ $picture['name'] }}"
                                                        loading="lazy"
                                                    >
                                                </a>
                                            @else
                                                <span class="mov-evidence-placeholder">
                                                    <x-icon name="image" :size="18" />
                                                    <span>No Picture</span>
                                                </span>
                                            @endif
                                        </td>
                                        <td data-label="File Name">
                                            <span class="mov-evidence-filename" title="{{ $picture['name'] }}">{{ $picture['name'] }}</span>
                                            @if ($picture['remarks'] !== null && trim($picture['remarks']) !== '')
                                                <span class="mov-evidence-remarks"><strong>Remarks:</strong> {{ $picture['remarks'] }}</span>
                                            @endif
                                            @if (! $picture['has_file'])
                                                <span class="mov-evidence-missing">The stored picture is missing. Replace it to restore the evidence.</span>
                                            @endif
                                        </td>
                                        <td data-label="Date Uploaded">
                                            <span class="mov-evidence-date">{{ $picture['uploaded_at'] ?? '—' }}</span>
                                            <span class="mov-evidence-size">{{ $picture['size'] }}</span>
                                        </td>
                                        <td data-label="Status">
                                            <span class="badge {{ $picture['status_class'] }}">{{ $picture['status_label'] }}</span>
                                            @if ($picture['reviewer'] !== null && $picture['status'] !== \App\Models\UserMov::STATUS_UPLOADED)
                                                <span class="mov-evidence-reviewer">Reviewed by {{ $picture['reviewer'] }}</span>
                                            @endif
                                        </td>
                                        <td data-label="Actions">
                                            @if ($removingId === $picture['id'])
                                                <div class="mov-evidence-actions mov-evidence-actions--confirm">
                                                    <span>Delete this picture?</span>
                                                    <button type="button" class="btn-danger btn-danger--small" wire:click="confirmRemove({{ $picture['id'] }})">Delete</button>
                                                    <button type="button" class="btn-ghost btn-ghost--small" wire:click="cancelRemove">Cancel</button>
                                                </div>
                                            @else
                                                <div class="mov-evidence-actions">
                                                    @if ($picture['has_file'])
                                                        <a class="btn-ghost btn-ghost--small" href="{{ route('mov.view', $picture['id']) }}" target="_blank" rel="noopener">
                                                            <x-icon name="eye" :size="14" /><span>View</span>
                                                        </a>
                                                        <a class="btn-ghost btn-ghost--small" href="{{ route('mov.download', $picture['id']) }}">
                                                            <x-icon name="download" :size="14" /><span>Download</span>
                                                        </a>
                                                    @endif
                                                    <button type="button" class="btn-ghost btn-ghost--small" wire:click="beginReplace({{ $picture['id'] }})">
                                                        <x-icon name="upload" :size="14" /><span>Replace</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn-ghost btn-ghost--small mov-evidence-delete"
                                                        wire:click="$set('removingId', {{ $picture['id'] }})"
                                                        title="Delete {{ $picture['name'] }}"
                                                    >
                                                        <x-icon name="trash" :size="14" /><span>Delete</span>
                                                    </button>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="mov-evidence-empty">
                        <span class="mov-evidence-empty__icon" aria-hidden="true"><x-icon name="image" :size="22" /></span>
                        <div>
                            <h2>No MOV evidence uploaded yet</h2>
                            <p>Upload the required picture or document for this MOV to continue.</p>
                        </div>
                    </div>
                @endif
            </section>

            <section class="mov-upload-panel" aria-labelledby="mov-upload-title">
                <div class="mov-upload-panel__head">
                    <div>
                        <span class="mov-upload-panel__eyebrow">{{ $replacingId !== null ? 'Replace selected evidence' : 'Add evidence' }}</span>
                        <h2 id="mov-upload-title">{{ $replacingId !== null ? 'Replace MOV Evidence' : 'Upload MOV Evidence' }}</h2>
                    </div>
                    <span class="mov-upload-panel__trail">{{ $mov['trail'] }}</span>
                </div>

                <label
                    class="mov-dropzone{{ $document ? ' has-file' : '' }}"
                    for="mov-evidence-file"
                    wire:key="mov-dropzone-{{ $focused }}-{{ $replacingId ?? 'new' }}"
                >
                    <input
                        id="mov-evidence-file"
                        type="file"
                        wire:model="document"
                        accept=".jpg,.jpeg,.png,.webp"
                        aria-label="Choose a MOV evidence image"
                    >
                    @if ($document)
                        <img class="mov-dropzone__preview" src="{{ $document->temporaryUrl() }}" alt="Preview of selected file">
                        <span class="mov-dropzone__filename">{{ $document->getClientOriginalName() }}</span>
                        <span class="mov-dropzone__action">Choose a different file</span>
                    @else
                        <span class="mov-dropzone__icon" aria-hidden="true"><x-icon name="upload" :size="24" /></span>
                        <strong>Drag &amp; drop your image here</strong>
                        <span>or <span class="mov-dropzone__action">Choose File</span></span>
                    @endif
                    <span class="mov-dropzone__formats">
                        Supported formats: {{ $this->acceptedExtensions() }}
                        <span>Maximum file size: {{ number_format($this->uploadLimitMegabytes(), 0) }} MB</span>
                    </span>
                </label>

                @if ($uploadFailedFor === $mov['id'] && $errors->has('document'))
                    <div class="error-text" role="alert">{{ $errors->first('document') }}</div>
                @endif

                <div class="mov-upload-panel__actions">
                    <button type="button" class="btn-ghost btn-ghost--small" wire:click="cancelUpload">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn-primary btn-primary--small"
                        wire:click="upload({{ $mov['id'] }})"
                        wire:loading.attr="disabled"
                        wire:target="upload({{ $mov['id'] }})"
                        @disabled(! $document)
                    >
                        <span wire:loading.remove wire:target="upload({{ $mov['id'] }})">{{ $replacingId !== null ? 'Replace Picture' : 'Upload Picture' }}</span>
                        <span wire:loading wire:target="upload({{ $mov['id'] }})">Uploading…</span>
                    </button>
                </div>
            </section>
        </article>

        <div class="mov-focus-script" aria-hidden="true" wire:key="mov-focus-script" x-data x-init="
            setTimeout(function () {
                var card = document.getElementById('mov-{{ $focused }}');
                if (! card) return;
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                card.classList.add('is-mov-focused');
                setTimeout(function () { card.classList.remove('is-mov-focused'); }, 5000);
            }, 150);
        "></div>
    @else
        <section class="mov-empty-selection">
            <h1>Select a MOV from the sidebar</h1>
            <p>Choose a Part, category, and MOV to view its evidence and submission status.</p>
        </section>
    @endif
</div>
