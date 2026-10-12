<div class="ac-component">
    {{-- SUCCESS ALERT: popped once as a SweetAlert, then cleared so later
         renders never re-fire it (same pattern as the Users table). --}}
    @if ($successMessage)
        <div
            wire:key="ac-alert-{{ md5($successMessage) }}"
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
        <h1>Account Classifications</h1>
        <p>Manage the RCA and UACS account reference data used across the app.</p>
    </div>

    <div class="card">
        <div class="card-head users-toolbar">
            <div class="users-search">
                <input
                    type="search"
                    placeholder="Search title, code, or PPAS…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Search account classifications"
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
                <select wire:model.live="sort" aria-label="Sort classifications">
                    <option value="title">Account Title</option>
                    <option value="rca">RCA Code</option>
                    <option value="object">UACS Object Code</option>
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
                    href="{{ route('account-classifications.template') }}"
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
                    <span>Add Account</span>
                </button>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table ac-table">
                <thead>
                    <tr>
                        <th scope="col">Account Title</th>
                        <th scope="col">RCA Code</th>
                        <th scope="col">Sub-Object</th>
                        <th scope="col">Object Code</th>
                        <th scope="col">Covering PPAS</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="users-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($classifications as $classification)
                        <tr wire:key="ac-{{ $classification->id }}">
                            <td><span class="cell-strong">{{ $classification->account_title }}</span></td>
                            <td><span class="ac-code">{{ $classification->rca_code }}</span></td>
                            <td><span class="ac-code">{{ $classification->uacs_sub_object_code }}</span></td>
                            <td><span class="ac-code">{{ $classification->uacs_object_code }}</span></td>
                            <td>{{ $classification->covering_ppas ?: '—' }}</td>
                            <td>
                                @if ($classification->is_active)
                                    <span class="badge">Active</span>
                                @else
                                    <span class="badge badge-muted">Inactive</span>
                                @endif
                            </td>
                            <td class="users-actions-col">
                                <x-row-menu :label="'More actions for '.$classification->account_title">
                                    <x-row-menu-item
                                        wire:click="openEdit({{ $classification->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="openEdit"
                                        title="Edit {{ $classification->account_title }}"
                                    >
                                        <x-icon name="pencil" :size="15" />
                                        <span>Edit</span>
                                    </x-row-menu-item>

                                    @if ($classification->is_active)
                                        <x-row-menu-item
                                            wire:click="openConfirmDelete({{ $classification->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openConfirmDelete"
                                            title="Delete {{ $classification->account_title }}"
                                            danger
                                        >
                                            <x-icon name="trash" :size="15" />
                                            <span>Delete</span>
                                        </x-row-menu-item>
                                    @else
                                        <x-row-menu-item
                                            wire:click="activate({{ $classification->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="activate"
                                            title="Activate {{ $classification->account_title }}"
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
                            <td colspan="7" class="table-empty">
                                @if (trim($search) !== '')
                                    No classifications match “{{ $search }}”.
                                @elseif ($status !== 'all')
                                    No {{ $status }} classifications to display.
                                @else
                                    No account classifications yet — add the first one.
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
                @if ($classifications->total() > 0)
                    {{ $classifications->firstItem() }}–{{ $classifications->lastItem() }} of {{ $classifications->total() }}
                @else
                    0 classifications
                @endif
            </span>

            {{ $classifications->links() }}
        </div>
    </div>

    {{-- DELETE / DEACTIVATE CONFIRMATION --}}
    @if ($showConfirm)
        <div class="modal-backdrop is-open"
             @click="self && $wire.closeConfirmDelete()"
             role="presentation">
            <div class="modal modal--confirmation" role="dialog" aria-modal="true" aria-labelledby="acDeleteTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="acDeleteTitle">Delete Account Classification</h2>
                    <button type="button" class="modal-close" wire:click="closeConfirmDelete" aria-label="Close" title="Close">×</button>
                </div>

                <div class="modal-form">
                    <p class="delete-modal-description">
                        Are you sure you want to delete <strong>{{ $confirmTitle }}</strong>?
                        This will deactivate the classification and hide it from new selections.
                        This action may affect records that use this classification.
                    </p>

                    <div class="modal-actions">
                        <button type="button" class="btn-ghost" wire:click="closeConfirmDelete">Cancel</button>
                        <button type="button" class="btn-danger" wire:click="confirmDelete" wire:loading.attr="disabled" wire:target="confirmDelete">
                            <span wire:loading.remove wire:target="confirmDelete">Delete</span>
                            <span wire:loading wire:target="confirmDelete">Deleting…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ADD / EDIT CLASSIFICATION MODAL (one modal, two modes) --}}
    <div class="modal-backdrop {{ $showForm ? 'is-open' : '' }}"
         @if ($showForm) @click="self && $wire.closeForm()" @endif
         role="presentation">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="acFormTitle" @click.stop>
            <div class="modal-head">
                <h2 id="acFormTitle">{{ $editingId ? 'Edit Account Classification' : 'Add Account Classification' }}</h2>
                <button type="button" class="modal-close" wire:click="closeForm" aria-label="Close" title="Close">×</button>
            </div>

            <form wire:submit="save" class="modal-form">
                <div class="field">
                    <label for="acTitle">Account Title</label>
                    <input
                        id="acTitle"
                        type="text"
                        placeholder="e.g. account title"
                        autocomplete="off"
                        wire:model="account_title"
                        @class(['error' => $errors->has('account_title')])
                        required
                    >
                    @error('account_title') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="ac-field-grid">
                    <div class="field">
                        <label for="acRca">RCA Code</label>
                        <input
                            id="acRca"
                            type="text"
                            placeholder="e.g. 50203010"
                            autocomplete="off"
                            wire:model="rca_code"
                            @class(['error' => $errors->has('rca_code')])
                            required
                        >
                        @error('rca_code') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="acSub">UACS Sub-Object Code</label>
                        <input
                            id="acSub"
                            type="text"
                            placeholder="e.g. 00"
                            autocomplete="off"
                            wire:model="uacs_sub_object_code"
                            @class(['error' => $errors->has('uacs_sub_object_code')])
                            required
                        >
                        @error('uacs_sub_object_code') <div class="error-text">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="field">
                    <label for="acObject">UACS Object Code</label>
                    <input
                        id="acObject"
                        type="text"
                        placeholder="e.g. 5020301000"
                        autocomplete="off"
                        wire:model="uacs_object_code"
                        @class(['error' => $errors->has('uacs_object_code')])
                        required
                    >
                    @error('uacs_object_code') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="acPpas">Covering PPAS <span class="field-optional">(optional)</span></label>
                    <input
                        id="acPpas"
                        type="text"
                        placeholder="e.g. PPAS 1"
                        autocomplete="off"
                        wire:model="covering_ppas"
                        @class(['error' => $errors->has('covering_ppas')])
                    >
                    @error('covering_ppas') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="acStatus">Status</label>
                    <select id="acStatus" wire:model="formStatus">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <div class="field-hint">Inactive classifications stay available on historical records but do not appear in new selections.</div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-ghost" wire:click="closeForm">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save Changes' : 'Add Account' }}</span>
                        <span wire:loading wire:target="save">{{ $editingId ? 'Saving…' : 'Adding…' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- BULK IMPORT MODAL: choose a CSV, preview every row's validation, then
         commit. The file is re-analyzed server-side on confirm, so the rows
         are never trusted from the browser. --}}
    <div class="modal-backdrop {{ $showImport ? 'is-open' : '' }}"
         @if ($showImport) @click="self && $wire.closeImport()" @endif
         role="presentation">
        <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="acImportTitle" @click.stop>
            <div class="modal-head">
                <h2 id="acImportTitle">Import Account Classifications</h2>
                <button type="button" class="modal-close" wire:click="closeImport" aria-label="Close" title="Close">×</button>
            </div>

            <div class="modal-form">
                <div class="field">
                    <label for="acImportFile">CSV file</label>
                    <input
                        id="acImportFile"
                        type="file"
                        accept=".csv,text/csv,.txt"
                        wire:model="importFile"
                        @class(['error' => $errors->has('importFile')])
                    >
                    <div class="field-hint">
                        Columns: <strong>Account Title, RCA Code, UACS Sub-Object Code, UACS Object Code, Covering PPAS</strong>.
                        A header row is optional, and codes are stored exactly as written (leading zeroes are kept).
                        Not sure of the format?
                        <a href="{{ route('account-classifications.template') }}" class="ac-import-template-link" download>Download the CSV template</a>.
                    </div>
                    @error('importFile') <div class="error-text">{{ $message }}</div> @enderror
                    <div wire:loading wire:target="importFile" class="ac-import-loading">Reading file…</div>
                </div>

                @if ($importError)
                    <div class="ac-import-error">{{ $importError }}</div>
                @endif

                @if ($importRows !== [])
                    <div class="ac-import-summary">
                        <span class="ac-import-pill ac-import-pill--ok">{{ $importValidCount }} ready to import</span>
                        @if ($importInvalidCount > 0)
                            <span class="ac-import-pill ac-import-pill--bad">{{ $importInvalidCount }} will be skipped</span>
                        @endif
                    </div>

                    <div class="table-wrap ac-import-preview">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th scope="col">Row</th>
                                    <th scope="col">Account Title</th>
                                    <th scope="col">RCA</th>
                                    <th scope="col">Sub-Object</th>
                                    <th scope="col">Object</th>
                                    <th scope="col">PPAS</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($importRows as $row)
                                    <tr wire:key="ac-import-row-{{ $row['line'] }}" @class(['ac-import-row--bad' => ! $row['valid']])>
                                        <td>{{ $row['line'] }}</td>
                                        <td><span class="cell-strong">{{ $row['data']['account_title'] ?: '—' }}</span></td>
                                        <td><span class="ac-code">{{ $row['data']['rca_code'] ?: '—' }}</span></td>
                                        <td><span class="ac-code">{{ $row['data']['uacs_sub_object_code'] ?: '—' }}</span></td>
                                        <td><span class="ac-code">{{ $row['data']['uacs_object_code'] ?: '—' }}</span></td>
                                        <td>{{ $row['data']['covering_ppas'] ?: '—' }}</td>
                                        <td>
                                            @if ($row['valid'])
                                                <span class="badge">Ready</span>
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

                <div class="modal-actions">
                    <button type="button" class="btn-ghost" wire:click="closeImport">Cancel</button>
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
                </div>
            </div>
        </div>
    </div>
</div>
