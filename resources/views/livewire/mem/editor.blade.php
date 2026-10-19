<div class="mem-component">
    {{-- SUCCESS ALERT: popped once as a SweetAlert, then cleared. --}}
    @if ($successMessage)
        <div
            wire:key="mem-alert-{{ md5($successMessage) }}"
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
        <div class="memory-breadcrumb">
            <a href="{{ route('wfp.matrices.index') }}" wire:navigate>Monthly Expenditure Matrix</a>
            <span aria-hidden="true">›</span>
            <span>{{ $matrix->periodLabel() }}</span>
        </div>
        <h1>{{ $matrix->periodLabel() }}</h1>
        <p>
            The source of truth for your Work and Financial Plan.
            <span class="mem-status mem-status--{{ $matrixStatus }}">{{ ucfirst($matrixStatus) }}</span>
            · {{ count($items) }} {{ count($items) === 1 ? 'line' : 'lines' }}
        </p>
    </div>

    {{-- MONTH NAVIGATION — the twelve months of this matrix's year, so moving
         through the year never means going back to the list. The month being
         edited is marked; the others link straight to their own matrix. A month
         that is validated or finalized carries a small status dot, so the year's
         progress reads at a glance; a month whose matrix was deleted is
         inactive. --}}
    <nav class="mem-month-tabs" aria-label="Months of CY {{ $matrix->year }}">
        @foreach ($this->months as $tab)
            @if ($tab['current'])
                <span class="mem-month-tab is-current" aria-current="page" title="{{ $tab['label'] }}">
                    <span class="mem-month-tab__abbr">{{ $tab['abbr'] }}</span>
                    @if (in_array($tab['status'], ['validated', 'finalized'], true))
                        <span class="mem-month-tab__dot mem-month-tab__dot--{{ $tab['status'] }}" aria-hidden="true"></span>
                    @endif
                </span>
            @elseif ($tab['id'] !== null)
                <a
                    href="{{ route('wfp.matrices.edit', ['matrix' => $tab['id']]) }}"
                    wire:navigate
                    class="mem-month-tab"
                    title="Open {{ $tab['label'] }}"
                >
                    <span class="mem-month-tab__abbr">{{ $tab['abbr'] }}</span>
                    @if (in_array($tab['status'], ['validated', 'finalized'], true))
                        <span class="mem-month-tab__dot mem-month-tab__dot--{{ $tab['status'] }}" aria-hidden="true"></span>
                    @endif
                </a>
            @else
                <span class="mem-month-tab is-empty" title="{{ $tab['label'] }} — no matrix yet">
                    <span class="mem-month-tab__abbr">{{ $tab['abbr'] }}</span>
                </span>
            @endif
        @endforeach
    </nav>

    @if ($errorMessage)
        <div class="ac-import-error mem-banner" role="alert">
            {{ $errorMessage }}
            <button type="button" class="users-clear" wire:click="clearError" title="Dismiss">×</button>
        </div>
    @endif

    @error('items')
        <div class="ac-import-error mem-banner" role="alert">{{ $message }}</div>
    @enderror

    <div class="card">
        <div class="card-head users-toolbar">
            <p class="mem-toolbar-copy">
                Lines are grouped by section. Use a row's pencil to edit it; the totals below update as you go.
            </p>

            <div class="ac-toolbar-actions mem-toolbar">
                <a
                    href="{{ route('wfp.matrices.export', ['matrix' => $matrix->id]) }}"
                    class="ac-import-btn"
                    download
                    title="Export the matrix as the printed DepEd form (Excel)"
                >
                    <x-icon name="file-spreadsheet" :size="16" />
                    <span>Export XLSX</span>
                </a>
               {{-- kw<a
                    href="{{ route('wfp.matrices.template') }}"
                    class="ac-import-btn"
                    download
                    title="Download a CSV template"
                >
                    <x-icon name="download" :size="16" />
                    <span>Template</span>
                </a>

                <button type="button" class="ac-import-btn" wire:click="openImport">
                    <x-icon name="upload" :size="16" />
                    <span>Import CSV</span>
                </button> --}}
            </div>
        </div>

        @if ($items === [])
            <div class="mem-empty">
                <x-icon name="file-spreadsheet" :size="28" />
                <h3>No expenditure lines yet</h3>
                <p>
                    Each line is one activity for the month — what it is, how many, at what cost,
                    and the account it is charged to. Add them one at a time, or bring in a whole
                    month from a CSV.
                </p>

                <div class="mem-empty__actions">
                    <button type="button" class="users-add-btn" wire:click="openLineModal('operations')">
                        <x-icon name="plus" :size="16" />
                        <span>Add the first line</span>
                    </button>

                    <button type="button" class="ac-import-btn" wire:click="openImport">
                        <x-icon name="upload" :size="16" />
                        <span>Import CSV</span>
                    </button>
                </div>

                <p class="mem-empty__hint">
                    Not sure of the CSV format?
                    <a href="{{ route('wfp.matrices.template') }}" class="ac-import-template-link" download>Download the CSV template</a>.
                </p>
            </div>
        @else
            @foreach (['operations' => 'Operations', 'project' => 'Project'] as $sectionKey => $sectionLabel)
                @php($sectionRows = $this->rowsForSection($sectionKey))

                {{-- ONE SECTION of the matrix. The lines are grouped here rather
                     than marked by a dropdown in every row, so Operations and
                     Project read as two lists, each with its own subtotal and
                     its own add button. --}}
                <section class="mem-section" aria-label="{{ $sectionLabel }} lines">
                    <div class="mem-section__head">
                        <div class="mem-section__title">
                            <span class="mem-group__section">{{ $sectionLabel }}</span>
                            <span class="mem-section__count">
                                {{ count($sectionRows) }} {{ count($sectionRows) === 1 ? 'line' : 'lines' }}
                            </span>
                        </div>

                        <div class="mem-section__aside">
                            <span class="mem-section__subtotal">
                                Subtotal <strong>{{ number_format($this->totals[$sectionKey], 2) }}</strong>
                            </span>

                            <button type="button" class="ac-import-btn" wire:click="openLineModal('{{ $sectionKey }}')">
                                <x-icon name="plus" :size="15" />
                                <span>Add {{ \Illuminate\Support\Str::lower($sectionLabel) }} line</span>
                            </button>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table class="data-table mem-list">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Activity</th>
                                    <th scope="col">Account</th>
                                    <th scope="col" class="mem-num">Qty</th>
                                    <th scope="col" class="mem-num">Unit Cost</th>
                                    <th scope="col" class="mem-num">Total</th>
                                    <th scope="col" class="users-actions-col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($sectionRows as $entry)
                                    {{-- A read-only LIST row. The line is changed in the
                                         modal (the pencil), not by typing into the grid,
                                         so a month's lines stay compact and scannable. --}}
                                    @php($i = $entry['index'])
                                    @php($row = $entry['row'])
                                    @php($objective = trim((string) ($row['objective'] ?? '')))
                                    @php($resourceKind = trim((string) ($row['resource_kind'] ?? '')))
                                    @php($funding = trim((string) ($row['funding_source'] ?? '')))
                                    @php($org = trim((string) ($row['organizational_classification'] ?? '')))
                                    @php($movs = trim((string) ($row['activity_movs'] ?? '')))
                                    @php($accountTitle = trim((string) ($row['account_title'] ?? '')))
                                    @php($accountCode = trim((string) ($row['account_code'] ?? '')))
                                    @php($quantity = trim((string) ($row['quantity'] ?? '')))
                                    @php($unit = trim((string) ($row['unit'] ?? '')))
                                    @php($unitCost = trim((string) ($row['unit_cost'] ?? '')))
                                    <tr wire:key="row-{{ $row['_key'] }}">
                                        <td class="mem-rownum">{{ $i + 1 }}</td>
                                        <td>
                                            <span class="cell-strong">{{ $row['activity_description'] !== '' ? $row['activity_description'] : '—' }}</span>
                                            @if ($objective !== '')
                                                <span class="cell-sub">{{ \Illuminate\Support\Str::limit($objective, 140) }}</span>
                                            @endif
                                            @if ($resourceKind !== '' || $funding !== '' || $org !== '')
                                                <div class="mem-list__meta">
                                                    @if ($resourceKind !== '') <span class="mem-chip">{{ $resourceKind }}</span> @endif
                                                    @if ($funding !== '') <span class="mem-list__meta-item">Funding: {{ $funding }}</span> @endif
                                                    @if ($org !== '') <span class="mem-list__meta-item">{{ $org }}</span> @endif
                                                </div>
                                            @endif
                                            @if ($movs !== '')
                                                <div class="mem-list__movs">MOVs: {{ \Illuminate\Support\Str::limit($movs, 100) }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($accountTitle !== '' || $accountCode !== '')
                                                <span class="cell-strong">{{ $accountTitle !== '' ? $accountTitle : '—' }}</span>
                                                @if ($accountCode !== '') <span class="cell-sub ac-code">{{ $accountCode }}</span> @endif
                                            @else
                                                <span class="mem-list__muted">—</span>
                                            @endif
                                        </td>
                                        <td class="mem-num">
                                            @if ($quantity !== '')
                                                {{ $quantity }}@if ($unit !== '') <span class="mem-unit">{{ $unit }}</span>@endif
                                            @else
                                                <span class="mem-list__muted">—</span>
                                            @endif
                                        </td>
                                        <td class="mem-num">
                                            @if ($unitCost !== '')
                                                {{ number_format((float) $unitCost, 2) }}
                                            @else
                                                <span class="mem-list__muted">—</span>
                                            @endif
                                        </td>
                                        <td class="mem-num mem-linetotal--cell">
                                            {{ number_format(\App\Livewire\MemEditor::lineTotal($row), 2) }}
                                        </td>
                                        <td class="users-actions-col">
                                            <div class="users-actions-row">
                                                <button
                                                    type="button"
                                                    class="mem-row-action"
                                                    wire:click="openEditModal('{{ $row['_key'] }}')"
                                                    title="Edit this line"
                                                >
                                                    <x-icon name="pencil" :size="15" />
                                                </button>

                                                <button
                                                    type="button"
                                                    class="mem-row-action"
                                                    wire:click="duplicateRow('{{ $row['_key'] }}')"
                                                    title="Duplicate this line"
                                                >
                                                    <x-icon name="copy" :size="15" />
                                                </button>

                                                <button
                                                    type="button"
                                                    class="mem-row-action"
                                                    wire:click="moveRow('{{ $row['_key'] }}')"
                                                    title="Move to {{ $sectionKey === 'operations' ? 'Project' : 'Operations' }}"
                                                >
                                                    <x-icon name="arrow-right-left" :size="15" />
                                                </button>

                                                <button
                                                    type="button"
                                                    class="mem-row-remove"
                                                    wire:click="removeRow('{{ $row['_key'] }}')"
                                                    title="Remove this line"
                                                >
                                                    <x-icon name="trash" :size="15" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="table-empty">
                                            No {{ \Illuminate\Support\Str::lower($sectionLabel) }} lines yet — add one below, or import a CSV.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        @endif

        {{-- THE TOTALS, on one full-width strip: the old tfoot put them at the
             far right of a 1500px-wide table, where they were the first thing
             scrolled past and the last thing seen. --}}
        @if ($items !== [])
            <div class="mem-summary">
                <div class="mem-summary__item">
                    <span class="mem-summary__label">Operations subtotal</span>
                    <span class="mem-summary__value">{{ number_format($this->totals['operations'], 2) }}</span>
                </div>

                <div class="mem-summary__item">
                    <span class="mem-summary__label">Project subtotal</span>
                    <span class="mem-summary__value">{{ number_format($this->totals['project'], 2) }}</span>
                </div>

                <div class="mem-summary__item mem-summary__item--grand">
                    <span class="mem-summary__label">Monthly total</span>
                    <span class="mem-summary__value">{{ number_format($this->totals['grand'], 2) }}</span>
                </div>
            </div>
        @endif

        <div class="mem-actions">
            <div class="mem-actions__group">
                <button type="button" class="btn-ghost" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">
                    <span wire:loading.remove wire:target="saveDraft">Save Draft</span>
                    <span wire:loading wire:target="saveDraft">Saving…</span>
                </button>
                <button type="button" class="ac-import-btn" wire:click="validateMatrix" wire:loading.attr="disabled" wire:target="validateMatrix">
                    Validate
                </button>
                <button type="button" class="ac-import-btn" wire:click="finalizeMatrix" wire:loading.attr="disabled" wire:target="finalizeMatrix">
                    Finalize
                </button>

                <span class="mem-actions__hint">
                    Save keeps your lines; Validate and Finalize record this month's status.
                </span>
            </div>

            <button type="button" class="btn-primary" wire:click="generateWfp" wire:loading.attr="disabled" wire:target="generateWfp">
                <span wire:loading.remove wire:target="generateWfp">Generate WFP</span>
                <span wire:loading wire:target="generateWfp">Generating…</span>
            </button>
        </div>
    </div>

    {{-- ADD / EDIT A LINE --}}
    {{-- One focused form is used for both: opened empty from an "Add … line"
         button, or pre-filled from a row's pencil. The section is chosen here
         and can still be switched before saving. --}}
    <div class="modal-backdrop {{ $showLineModal ? 'is-open' : '' }}"
         @if ($showLineModal) @click="self && $wire.closeLineModal()" @endif
         role="presentation">
        <div class="modal modal--wide modal--sticky-actions" role="dialog" aria-modal="true" aria-labelledby="memLineTitle" @click.stop>
            <div class="modal-head">
                <h2 id="memLineTitle">{{ $editingKey !== null ? 'Edit line' : 'Add a line' }}</h2>
                <button type="button" class="modal-close" wire:click="closeLineModal" aria-label="Close" title="Close">×</button>
            </div>

            <form wire:submit="saveLineFromModal" class="modal-form">
                {{-- Two-column grid (.mem-line-fields) so the whole line fits
                     on screen without scrolling; a few fields span both
                     columns. See dashboard.css. --}}
                <div class="mem-line-fields">
                    <div class="mem-line-sections mem-line-span" role="group" aria-label="Section">
                        @foreach (['operations' => 'Operations', 'project' => 'Project'] as $key => $label)
                            <label class="mem-line-section">
                                <input type="radio" wire:model.live="lineSection" value="{{ $key }}">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div class="field">
                        <label for="memLineObjective">Objective</label>
                        <textarea
                            id="memLineObjective"
                            rows="2"
                            wire:model="lineForm.objective"
                            placeholder="What the activity serves"
                        ></textarea>
                        @error('lineForm.objective') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                     <div class="field mem-line-span">
                        <label for="memLineActivity">Activity description <span class="field-required">required</span></label>
                        <input
                            id="memLineActivity"
                            type="text"
                            wire:model="lineForm.activity_description"
                            placeholder="e.g. Procure office supplies"
                            @class(['error' => $errors->has('lineForm.activity_description')])
                            autofocus
                        >
                        @error('lineForm.activity_description') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="memLineKind">Resource kind</label>
                        <input id="memLineKind" type="text" wire:model="lineForm.resource_kind" placeholder="e.g. MOOE">
                        @error('lineForm.resource_kind') <div class="error-text">{{ $message }}</div> @enderror
                    </div>
                    

                    {{-- Quantity, unit, unit cost and the computed line total sit
                         on one row — they are read together. --}}
                    <div class="mem-line-money mem-line-span">
                        <div class="field">
                            <label for="memLineQty">Quantity</label>
                            <input id="memLineQty" type="number" step="0.01" min="0" inputmode="decimal" wire:model="lineForm.quantity" placeholder="0">
                            @error('lineForm.quantity') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="field">
                            <label for="memLineUnit">Unit</label>
                            <input id="memLineUnit" type="text" wire:model="lineForm.unit" placeholder="e.g. ream">
                            @error('lineForm.unit') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="field">
                            <label for="memLineCost">Unit cost</label>
                            <input id="memLineCost" type="number" step="0.01" min="0" inputmode="decimal" wire:model="lineForm.unit_cost" placeholder="0.00">
                            @error('lineForm.unit_cost') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="mem-line-total" aria-live="polite">
                            <span class="mem-line-total__label">Line total</span>
                            <span class="mem-line-total__value">{{ number_format(\App\Livewire\MemEditor::lineTotal($lineForm), 2) }}</span>
                        </div>
                    </div>

                    {{-- Account title / code: one searchable dropdown. Typing
                         filters the active account classifications by title or
                         object code; picking one fills the title, the code and
                         the classification link. Free text still works — if
                         nothing is picked, what was typed becomes the title. --}}
                    <div class="field">
                        <label for="memLineAccount">Account title / code</label>

                        <div class="mem-combo" x-data="{ open: false }" @click.outside="open = false">
                            <span class="mem-combo__search" aria-hidden="true">
                                <x-icon name="search" :size="15" />
                            </span>

                            <input
                                id="memLineAccount"
                                type="text"
                                autocomplete="off"
                                role="combobox"
                                aria-autocomplete="list"
                                aria-controls="memAccountOptions"
                                :aria-expanded="String(open)"
                                wire:model.live.debounce.300ms="accountSearch"
                                placeholder="Search title or object code…"
                                @focus="open = true"
                                @keydown.escape="open = false"
                                @class(['error' => $errors->has('lineForm.account_title')])
                            >

                            @if (trim($accountSearch) !== '')
                                <button
                                    type="button"
                                    class="mem-combo__clear"
                                    wire:click="clearAccount"
                                    aria-label="Clear account"
                                    title="Clear"
                                >
                                    <x-icon name="x" :size="14" />
                                </button>
                            @endif

                            {{-- The open/closed state is a class (like the
                                 notifications panel), so Livewire re-renders
                                 never resurrect the menu mid-typing. --}}
                            <div
                                id="memAccountOptions"
                                class="mem-combo__menu"
                                role="listbox"
                                :class="open ? 'open' : ''"
                            >
                                @forelse ($this->accountOptions as $option)
                                    <button
                                        type="button"
                                        role="option"
                                        class="mem-combo__option"
                                        wire:key="ac-opt-{{ $option->id }}"
                                        wire:click="chooseAccount({{ $option->id }})"
                                        @click="open = false"
                                    >
                                        <span class="mem-combo__option-title">{{ $option->account_title }}</span>
                                        <span class="mem-combo__option-meta">
                                            <span class="ac-code">{{ $option->uacs_object_code }}</span>
                                            @if ($option->rca_code)
                                                <span class="mem-combo__rca">RCA {{ $option->rca_code }}</span>
                                            @endif
                                        </span>
                                    </button>
                                @empty
                                    <div class="mem-combo__empty">No matching account classifications.</div>
                                @endforelse
                            </div>
                        </div>

                        <input type="text" wire:model="lineForm.account_code" placeholder="Object code" aria-label="Account code">
                        @error('lineForm.account_title') <div class="error-text">{{ $message }}</div> @enderror
                        @error('lineForm.account_code') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="memLineFunding">Funding source</label>
                        <input id="memLineFunding" type="text" wire:model="lineForm.funding_source" placeholder="e.g. MOOE">
                        @error('lineForm.funding_source') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="memLineOrg">Org. classification</label>
                        <input id="memLineOrg" type="text" wire:model="lineForm.organizational_classification" placeholder="e.g. School">
                        @error('lineForm.organizational_classification') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                     <div class="field">
                        <label for="memLineMovs">Activity MOVs</label>
                        <input id="memLineMovs" type="text" wire:model="lineForm.activity_movs" placeholder="e.g. PO and RIS">
                        @error('lineForm.activity_movs') <div class="error-text">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-ghost" wire:click="closeLineModal">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="saveLineFromModal">
                        <span wire:loading.remove wire:target="saveLineFromModal">{{ $editingKey !== null ? 'Save changes' : 'Add line' }}</span>
                        <span wire:loading wire:target="saveLineFromModal">Saving…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- CSV IMPORT --}}
    <div class="modal-backdrop {{ $showImport ? 'is-open' : '' }}"
         @if ($showImport) @click="self && $wire.closeImport()" @endif
         role="presentation">
        <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="memImportTitle" @click.stop>
            <div class="modal-head">
                <h2 id="memImportTitle">Import Expenditure Lines</h2>
                <button type="button" class="modal-close" wire:click="closeImport" aria-label="Close" title="Close">×</button>
            </div>

            <div class="modal-form">
                <div class="field">
                    <label for="memImportFile">CSV file</label>
                    <input
                        id="memImportFile"
                        type="file"
                        accept=".csv,text/csv,.txt"
                        wire:model="importFile"
                        @class(['error' => $errors->has('importFile')])
                    >
                    <div class="field-hint">
                        Columns: <strong>Section, Objective, Activity Description, Resource Kind, Quantity, Unit, Unit Cost, Account Title, Account Code, Funding Source, Organizational Classification, Activity MOVs</strong>.
                        The total is recomputed server-side. Not sure of the format?
                        <a href="{{ route('wfp.matrices.template') }}" class="ac-import-template-link" download>Download the CSV template</a>.
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
                                    <th scope="col">Section</th>
                                    <th scope="col">Activity</th>
                                    <th scope="col">Qty</th>
                                    <th scope="col">Unit Cost</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($importRows as $row)
                                    <tr wire:key="mem-import-row-{{ $row['line'] }}" @class(['ac-import-row--bad' => ! $row['valid']])>
                                        <td>{{ $row['line'] }}</td>
                                        <td>{{ $row['data']['section'] ?: '—' }}</td>
                                        <td><span class="cell-strong">{{ $row['data']['activity_description'] ?: '—' }}</span></td>
                                        <td>{{ $row['data']['quantity'] ?? '—' }}</td>
                                        <td>{{ $row['data']['unit_cost'] ?? '—' }}</td>
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
                        <span wire:loading.remove wire:target="commitImport">Import {{ $importValidCount }} line(s)</span>
                        <span wire:loading wire:target="commitImport">Importing…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
