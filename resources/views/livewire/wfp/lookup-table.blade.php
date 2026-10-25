<div class="wfp-setup">
    {{-- SUCCESS ALERT: popped once as a SweetAlert, then cleared so later
         renders never re-fire it (same pattern as the Users table). --}}
    @if ($successMessage)
        <div
            wire:key="wfp-setup-alert-{{ md5($successMessage) }}"
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

    <div class="page-header">
        <h1>{{ $spec['label'] }}</h1>
        <p>{{ $spec['blurb'] }}</p>
    </div>

    <div class="card">
        <div class="card-head users-toolbar">
            <div class="users-search">
                <input
                    type="search"
                    placeholder="Search {{ mb_strtolower($spec['singular']) }}…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Search {{ mb_strtolower($spec['label']) }}"
                >

                @if (trim($search) !== '')
                    <button type="button" class="users-clear" wire:click="clearSearch" title="Clear search">×</button>
                @endif
            </div>

            <label class="users-per-page">
                <span>Status</span>
                <select wire:model.live="status" aria-label="Filter by status">
                    <option value="all">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </label>

            <label class="users-per-page">
                <span>Sort</span>
                <select wire:model.live="sort" aria-label="Sort {{ mb_strtolower($spec['label']) }}">
                    <option value="name">Name</option>
                    <option value="code">Code</option>
                    <option value="recent">Recently updated</option>
                </select>
            </label>

            <label class="users-per-page">
                <span>Rows</span>
                <select wire:model.live="perPage" aria-label="Rows per page">
                    @foreach ([10, 25, 50] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>

            <div class="ac-toolbar-actions">
                <a
                    href="{{ route('wfp.setup.lookups.template', ['category' => $categorySlug]) }}"
                    class="ac-import-btn"
                    download
                    title="Download a CSV template"
                >
                    <x-icon name="download" :size="16" />
                    <span>Template</span>
                </a>

                <button type="button" class="ac-import-btn" wire:click="openImport">
                    <x-icon name="upload" :size="16" />
                    <span>Import</span>
                </button>

                <button type="button" class="users-add-btn" wire:click="openCreate">
                    <x-icon name="plus" :size="16" />
                    <span>Add {{ $spec['singular'] }}</span>
                </button>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table wfp-lookup-table">
                <thead>
                    <tr>
                        @foreach ($formFields as $field)
                            <th scope="col">{{ $spec['columns'][$field]['label'] }}</th>
                        @endforeach
                        <th scope="col">Status</th>
                        <th scope="col" class="users-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr wire:key="wfp-lookup-{{ $record->id }}">
                            @foreach ($formFields as $field)
                                <td>
                                    @if (! empty($spec['columns'][$field]['boolean']))
                                        @if ($record->{$field})
                                            <span class="badge">Active</span>
                                        @else
                                            <span class="badge badge-muted">Inactive</span>
                                        @endif
                                    @else
                                        <span class="{{ $field === 'code' ? 'ac-code' : 'cell-strong' }}">{{ $record->{$field} ?: '—' }}</span>
                                    @endif
                                </td>
                            @endforeach
                            <td>
                                @if ($record->is_active)
                                    <span class="badge">Active</span>
                                @else
                                    <span class="badge badge-muted">Inactive</span>
                                @endif
                            </td>
                            <td class="users-actions-col">
                                <x-row-menu :label="'More actions for '.$record->name">
                                    <x-row-menu-item
                                        wire:click="openEdit({{ $record->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="openEdit"
                                        title="Edit {{ $record->name }}"
                                    >
                                        <x-icon name="pencil" :size="15" />
                                        <span>Edit</span>
                                    </x-row-menu-item>

                                    @if ($record->is_active)
                                        <x-row-menu-item
                                            wire:click="openConfirmDelete({{ $record->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openConfirmDelete"
                                            title="Delete {{ $record->name }}"
                                            danger
                                        >
                                            <x-icon name="trash" :size="15" />
                                            <span>Deactivate</span>
                                        </x-row-menu-item>
                                    @else
                                        <x-row-menu-item
                                            wire:click="activate({{ $record->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="activate"
                                            title="Activate {{ $record->name }}"
                                        >
                                            <x-icon name="check" :size="15" />
                                            <span>Activate</span>
                                        </x-row-menu-item>
                                    @endif
                                </x-row-menu>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($formFields) + 2 }}" class="table-empty">
                                @if (trim($search) !== '')
                                    No {{ mb_strtolower($spec['label']) }} match “{{ $search }}”.
                                @elseif ($status !== 'all')
                                    No {{ $status }} {{ mb_strtolower($spec['label']) }} to display.
                                @else
                                    No {{ mb_strtolower($spec['label']) }} yet — add the first one.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="users-footer">
            <span class="users-live-indicator" wire:loading.delay wire:target="search,status,sort,perPage" title="Updating…">
                <span class="users-live-dot"></span> Updating…
            </span>

            <span class="users-count">
                @if ($records->total() > 0)
                    {{ $records->firstItem() }}–{{ $records->lastItem() }} of {{ $records->total() }}
                @else
                    0 {{ mb_strtolower($spec['label']) }}
                @endif
            </span>

            {{ $records->links() }}
        </div>
    </div>

    {{-- DELETE / DEACTIVATE CONFIRMATION --}}
    @if ($showConfirm)
        <div class="modal-backdrop is-open"
             @click="self && $wire.closeConfirmDelete()"
             role="presentation">
            <div class="modal modal--confirmation" role="dialog" aria-modal="true" aria-labelledby="wfpSetupDeleteTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="wfpSetupDeleteTitle">Deactivate {{ $spec['singular'] }}</h2>
                    <button type="button" class="modal-close" wire:click="closeConfirmDelete" aria-label="Close" title="Close">×</button>
                </div>

                <div class="modal-form">
                    <p class="delete-modal-description">
                        Are you sure you want to deactivate <strong>{{ $confirmTitle }}</strong>?
                        It will be hidden from new selections but stays readable on historical lines and templates.
                    </p>

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeConfirmDelete">Cancel</button>
                        <button type="button" class="btn-danger" wire:click="confirmDelete" wire:loading.attr="disabled" wire:target="confirmDelete">
                            <span wire:loading.remove wire:target="confirmDelete">Deactivate</span>
                            <span wire:loading wire:target="confirmDelete">Deactivating…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ADD / EDIT {{ $spec['label'] }} MODAL (one modal, two modes) --}}
    <div class="modal-backdrop {{ $showForm ? 'is-open' : '' }}"
         @if ($showForm) @click="self && $wire.closeForm()" @endif
         role="presentation">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="wfpSetupFormTitle" @click.stop>
            <div class="modal-head">
                <h2 id="wfpSetupFormTitle">{{ $editingId ? 'Edit '.$spec['singular'] : 'Add '.$spec['singular'] }}</h2>
                <button type="button" class="modal-close" wire:click="closeForm" aria-label="Close" title="Close">×</button>
            </div>

            <form wire:submit="save" class="modal-form">
                @foreach ($formFields as $field)
                    <div class="field">
                        <label for="wfpSetupForm{{ ucfirst($field) }}">{{ $spec['columns'][$field]['label'] }}
                            @if (! ($spec['columns'][$field]['required'] ?? false)) <span class="field-optional">(optional)</span> @endif
                        </label>
                        @if (! empty($spec['columns'][$field]['boolean']))
                            <select id="wfpSetupForm{{ ucfirst($field) }}" wire:model="form.{{ $field }}">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        @else
                            <input
                                id="wfpSetupForm{{ ucfirst($field) }}"
                                type="text"
                                placeholder="e.g. {{ $spec['columns'][$field]['label'] }}"
                                autocomplete="off"
                                wire:model="form.{{ $field }}"
                                @class(['error' => $errors->has('form.'.$field)])
                                {{ ($spec['columns'][$field]['required'] ?? false) ? 'required' : '' }}
                            >
                        @endif
                        @error('form.'.$field) <div class="error-text">{{ $message }}</div> @enderror
                    </div>
                @endforeach

                <div class="field">
                    <label for="wfpSetupFormStatus">Status</label>
                    <select id="wfpSetupFormStatus" wire:model="formStatus">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <div class="field-hint">Inactive records stay available on historical data but do not appear in new selections.</div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-ghost" wire:click="closeForm">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save Changes' : 'Add '.$spec['singular'] }}</span>
                        <span wire:loading wire:target="save">{{ $editingId ? 'Saving…' : 'Adding…' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- BULK IMPORT MODAL --}}
    <div class="modal-backdrop {{ $showImport ? 'is-open' : '' }}"
         @if ($showImport) @click="self && $wire.closeImport()" @endif
         role="presentation">
        <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="wfpSetupImportTitle" @click.stop>
            <div class="modal-head">
                <h2 id="wfpSetupImportTitle">Import {{ $spec['label'] }}</h2>
                <button type="button" class="modal-close" wire:click="closeImport" aria-label="Close" title="Close">×</button>
            </div>

            <div class="modal-form">
                <div class="field">
                    <label for="wfpSetupImportFile">CSV file</label>
                    <input
                        id="wfpSetupImportFile"
                        type="file"
                        accept=".csv,text/csv,.txt"
                        wire:model="importFile"
                        @class(['error' => $errors->has('importFile')])
                    >
                    <div class="field-hint">
                        Columns: <strong>{{ implode(', ', array_map(fn ($spec) => $spec['label'], $spec['columns'])) }}</strong>.
                        A header row is optional; the file is read positionally in that order when it has no header.
                        Codes are stored exactly as written — leading zeroes are kept.
                        Not sure of the format?
                        <a href="{{ route('wfp.setup.lookups.template', ['category' => $categorySlug]) }}" class="ac-import-template-link" download>Download the CSV template</a>.
                    </div>
                    @error('importFile') <div class="error-text">{{ $message }}</div> @enderror
                    <div wire:loading wire:target="importFile" class="ac-import-loading">Reading file…</div>
                </div>

                @if ($importError)
                    <div class="ac-import-error">{{ $importError }}</div>
                @endif

                <div class="field">
                    <label>Import mode</label>
                    <select wire:model="importMode">
                        <option value="insert">Insert only — new records; duplicates are skipped</option>
                        <option value="update">Update matching — existing records are updated; unmatched rows are skipped</option>
                        <option value="upsert">Upsert — update matches, insert everything else</option>
                    </select>
                    <div class="field-hint">A row matches an existing record by its code when it carries one, otherwise by its name.</div>
                </div>

                @if ($importRows !== [])
                    <div class="ac-import-summary">
                        <span class="ac-import-pill ac-import-pill--ok">{{ $importValidCount }} ready to import</span>
                        @if ($importSkippedCount > 0)
                            <span class="ac-import-pill">{{ $importSkippedCount }} will be skipped</span>
                        @endif
                        @if ($importInvalidCount > 0)
                            <span class="ac-import-pill ac-import-pill--bad">{{ $importInvalidCount }} have errors</span>
                        @endif
                    </div>

                    <div class="table-wrap ac-import-preview">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th scope="col">Row</th>
                                    @foreach ($spec['columns'] as $field => $columnSpec)
                                        <th scope="col">{{ $columnSpec['label'] }}</th>
                                    @endforeach
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($importRows as $row)
                                    <tr wire:key="wfp-setup-import-row-{{ $row['line'] }}" @class(['ac-import-row--bad' => ! $row['valid']])>
                                        <td>{{ $row['line'] }}</td>
                                        @foreach ($spec['columns'] as $field => $columnSpec)
                                            <td>
                                                @if (! empty($columnSpec['boolean']))
                                                    @if ((bool) ($row['data'][$field] ?? false))
                                                        <span class="badge">Yes</span>
                                                    @else
                                                        <span class="badge-muted">No</span>
                                                    @endif
                                                @else
                                                    <span class="{{ $field === 'code' ? 'ac-code' : '' }}">{{ $row['data'][$field] ?: '—' }}</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td>
                                            @if ($row['valid'])
                                                <span class="badge">{{ ucfirst($row['action']) }}</span>
                                            @else
                                                <span class="badge badge-muted" title="{{ implode('; ', $row['errors']) }}">{{ implode('; ', $row['errors']) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($importTotalRows > count($importRows))
                        <div class="field-hint">Showing the first {{ count($importRows) }} of {{ $importTotalRows }} rows.</div>
                    @endif
                @endif

                @if ($importCommitted)
                    <div class="ac-import-summary">
                        <span class="ac-import-pill ac-import-pill--ok">{{ $lastInserted }} inserted</span>
                        @if ($lastUpdated > 0) <span class="ac-import-pill">{{ $lastUpdated }} updated</span> @endif
                        @if ($lastSkipped > 0) <span class="ac-import-pill">{{ $lastSkipped }} skipped</span> @endif
                        @if ($lastFailed > 0) <span class="ac-import-pill ac-import-pill--bad">{{ $lastFailed }} failed</span> @endif
                    </div>

                    @if ($lastFailed > 0 && $lastImportLogId !== null)
                        <a
                            href="{{ route('wfp.setup.imports.errors', ['log' => $lastImportLogId]) }}"
                            class="ac-import-btn"
                            download
                            title="Download the row-level error report"
                        >
                            <x-icon name="download" :size="16" />
                            <span>Download error report</span>
                        </a>
                    @endif
                @endif

                <div class="modal-actions">
                    <button type="button" class="btn-ghost" wire:click="closeImport">Cancel</button>
                    @if (! $importCommitted)
                        <button
                            type="button"
                            class="btn-primary"
                            wire:click="commitImport"
                            wire:loading.attr="disabled"
                            wire:target="commitImport"
                            @disabled($importValidCount === 0)
                        >
                            <span wire:loading.remove wire:target="commitImport">Import {{ $importValidCount }} row(s)</span>
                            <span wire:loading wire:target="commitImport">Importing…</span>
                        </button>
                    @else
                        <button type="button" class="btn-primary" wire:click="closeImport">Done</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
