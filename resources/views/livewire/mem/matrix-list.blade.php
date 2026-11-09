<div class="mem-component">
    @if ($successMessage)
        <div
            wire:key="mem-list-alert-{{ md5($successMessage) }}"
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
        <h1>Monthly Expenditure Matrix</h1>
        {{-- <p>
            Your school’s monthly expenditure lines — the source of truth your WFP is generated from.
            @if ($this->school) <span class="mem-chip">{{ $this->school->name }}</span> @endif
        </p> --}}
    </div>

    @if (Auth::user()?->school_id !== null)
        @php($signatoryStatuses = $this->signatoryStatuses)
        @if (! empty($signatoryStatuses))
            <section class="card wfp-signatory-statuses" aria-label="Signatory status for the selected year">
                <div class="card-head">
                    <div class="card-title">Signatory Status</div>
                </div>

                <div class="wfp-signatory-statuses__list">
                    @foreach ($signatoryStatuses as $status)
                        <div class="wfp-signatory-status">
                            <div class="wfp-signatory-status__header">
                                <span class="wfp-signatory-status__label">{{ $status['label'] }}</span>
                                <span @class([
                                    'wfp-signatory-status__badge',
                                    'wfp-signatory-status__badge--pending' => ($status['state'] ?? 'pending') === 'pending',
                                    'wfp-signatory-status__badge--checked' => ($status['state'] ?? 'pending') === 'checked',
                                    'wfp-signatory-status__badge--certified' => ($status['state'] ?? 'pending') === 'certified',
                                    'wfp-signatory-status__badge--approved' => ($status['state'] ?? 'pending') === 'approved',
                                    'wfp-signatory-status__badge--returned' => ($status['state'] ?? 'pending') === 'returned',
                                ])>{{ $status['badge'] }}</span>
                            </div>

                            @if (($status['state'] ?? 'pending') === 'pending')
                                <div class="wfp-signatory-status__meta">{{ $status['pending_text'] ?? 'Pending' }}</div>
                            @else
                                @if (! empty($status['name']))
                                    <div class="wfp-signatory-status__meta">{{ $status['name'] }}</div>
                                @endif
                                @if (! empty($status['position']))
                                    <div class="wfp-signatory-status__meta">{{ $status['position'] }}</div>
                                @endif
                                @if (! empty($status['date']))
                                    <div class="wfp-signatory-status__meta">{{ $status['date']->format('M j, Y g:i A') }}</div>
                                @endif
                                @if (! empty($status['remarks']))
                                    <div class="wfp-signatory-status__remarks"><strong>Remarks:</strong> {{ $status['remarks'] }}</div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endif

    @if ($errorMessage)
        <div class="ac-import-error mem-banner" role="alert">
            {{ $errorMessage }}
            <button type="button" class="users-clear" wire:click="clearError" title="Dismiss">×</button>
        </div>
    @endif

    <div class="card">
        <div class="card-head users-toolbar mem-list-toolbar">
            <label class="users-per-page">
                <span>Year</span>
                <select wire:model.live="year" aria-label="Filter by year">
                    <option value="">All</option>
                    @foreach ($this->years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>

            <div class="ac-toolbar-actions">
                @if ($this->canCreate() && $this->canEdit())
                    <label class="users-per-page mem-add-year">
                        <span>Add year</span>
                        <input
                            type="number"
                            inputmode="numeric"
                            min="{{ \App\Support\MemYearInitializer::MIN_YEAR }}"
                            max="{{ \App\Support\MemYearInitializer::MAX_YEAR }}"
                            step="1"
                            wire:model="newYear"
                            wire:keydown.enter.prevent="addYear"
                            placeholder="{{ now()->year + 1 }}"
                            aria-label="New planning year to add"
                        >
                    </label>

                    <button
                        type="button"
                        class="users-add-btn"
                        wire:click="addYear"
                        wire:loading.attr="disabled"
                        wire:target="addYear"
                        title="Create all twelve months for a new planning year"
                    >
                        <x-icon name="plus" :size="16" />
                        <span wire:loading.remove wire:target="addYear">Add Year</span>
                        <span wire:loading wire:target="addYear">Generating…</span>
                    </button>

                    {{-- <button
                        type="button"
                        class="ac-import-btn"
                        wire:click="openWorkbookImport"
                        title="Import a whole-year matrix workbook (.xlsx with JAN–DEC sheets)"
                    >
                        <x-icon name="upload" :size="16" />
                        <span>Import Matrix Workbook</span>
                    </button> --}}
                @endif
            </div>

            @if ($this->canCreate())
                {{-- The whole year is one workbook: twelve worksheets, JAN…DEC,
                     each the month's printed form. The selected year on this
                     page is the one exported. The signed copy stamps the
                     workflow signers' registered profile signatures; the
                     unsigned copy leaves the signature bands blank. --}}
                <div class="mem-export-actions" aria-label="Export the selected year">
                    <button
                        type="button"
                        class="ac-import-btn"
                        wire:click="exportYear('with')"
                        wire:loading.attr="disabled"
                        wire:target="exportYear"
                        @disabled($year === '')
                        title="{{ $year === ''
                            ? 'Choose a specific year to export'
                            : 'Download all twelve months of CY '.$year.' with the available e-signatures' }}"
                    >
                        <x-icon name="file-spreadsheet" :size="16" />
                        <span wire:loading.remove wire:target="exportYear">Export with E-Signatures</span>
                        <span wire:loading wire:target="exportYear">Preparing…</span>
                    </button>

                    <button
                        type="button"
                        class="ac-import-btn"
                        wire:click="exportYear('without')"
                        wire:loading.attr="disabled"
                        wire:target="exportYear"
                        @disabled($year === '')
                        title="{{ $year === ''
                            ? 'Choose a specific year to export'
                            : 'Download all twelve months of CY '.$year.' for wet signing' }}"
                    >
                        <x-icon name="download" :size="16" />
                        <span wire:loading.remove wire:target="exportYear">Export without E-Signatures</span>
                        <span wire:loading wire:target="exportYear">Preparing…</span>
                    </button>
                </div>
            @endif
        </div>

        @unless ($this->canCreate())
            <div class="ac-import-error mem-banner mem-banner--notice" role="status">
                Your account has no school assigned, so it cannot own a Monthly Expenditure Matrix.
                Ask an administrator to set your school first.
            </div>
        @endunless

        @unless ($this->canEdit())
            <div class="ac-import-error mem-banner mem-banner--notice" role="status">
                Read-only — the School Head edits the school’s matrices. Open a month
                to read its lines and check their account titles and codes.
            </div>
        @endunless

        {{-- Adding a year is a server round-trip. The table is dimmed and
             covered by a spinner while it runs, so the click reads as
             progress rather than as a stall. --}}
        <div
            class="table-wrap mem-table-shell"
            wire:loading.class="is-generating"
            wire:target="addYear"
        >
            <div
                class="mem-table-loading"
                wire:loading.flex
                wire:target="addYear"
                role="status"
                aria-live="polite"
            >
                <span class="mem-table-loading__spinner" aria-hidden="true"></span>
                <span>Generating the year’s twelve months…</span>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Period</th>
                        <th scope="col">Year</th>
                        <th scope="col">Lines</th>
                        <th scope="col">Updated</th>
                        <th scope="col" class="users-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($matrices as $matrix)
                        <tr
                            wire:key="mem-{{ $matrix->id }}"
                        >
                            <td>
                                <a
                                    class="cell-strong"
                                    href="{{ route('wfp.matrices.edit', ['matrix' => $matrix->id]) }}"
                                    wire:navigate.hover
                                    title="Open {{ $matrix->periodLabel() }}"
                                >{{ $matrix->periodLabel() }}</a>
                            </td>
                            <td><span class="ac-code">{{ $matrix->fiscalYearLabel() }}</span></td>
                            <td>{{ $matrix->items_count }}</td>
                            <td>{{ $matrix->updated_at?->diffForHumans() ?? '—' }}</td>
                            <td class="users-actions-col">
                                <x-row-menu :label="'Actions for '.$matrix->periodLabel()">
                                    <a
                                        role="menuitem"
                                        class="row-menu-item"
                                        href="{{ route('wfp.matrices.edit', ['matrix' => $matrix->id]) }}"
                                        wire:navigate.hover
                                        title="Open {{ $matrix->periodLabel() }}"
                                    >
                                        <x-icon name="file-text" :size="15" />
                                        <span>Open</span>
                                    </a>

                                    <x-row-menu-item
                                        wire:click="export({{ $matrix->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="export"
                                        title="Export {{ $matrix->periodLabel() }} as the printed form (Excel)"
                                    >
                                        <x-icon name="file-spreadsheet" :size="15" />
                                        <span>Export XLSX</span>
                                    </x-row-menu-item>

                                    {{-- Deleting is a write: read-only readers
                                         never see it (the action refuses too). --}}
                                    @if ($this->canEdit())
                                        <x-row-menu-item
                                            wire:click="openConfirmDelete({{ $matrix->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openConfirmDelete"
                                            title="Delete {{ $matrix->periodLabel() }}"
                                            danger
                                        >
                                            <x-icon name="trash" :size="15" />
                                            <span>Delete</span>
                                        </x-row-menu-item>
                                    @endif
                                </x-row-menu>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="table-empty">
                                @if ($year !== '')
                                    No matrices for CY {{ $year }} yet — use “Add year” to create its twelve months.
                                @else
                                    No matrices yet — use “Add year” to create a planning year.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="users-footer">
            <span class="users-live-indicator" wire:loading.delay wire:target="year" title="Updating…">
                <span class="users-live-dot"></span> Updating…
            </span>

            <span class="users-count">
                @if ($matrices->total() > 0)
                    {{ $matrices->firstItem() }}–{{ $matrices->lastItem() }} of {{ $matrices->total() }}
                @else
                    0 matrices
                @endif
            </span>

            {{ $matrices->links() }}
        </div>
    </div>

    {{-- DELETE CONFIRMATION --}}
    @if ($showConfirm)
        <div class="modal-backdrop is-open"
             @click="self && $wire.closeConfirmDelete()"
             role="presentation">
            <div class="modal modal--confirmation" role="dialog" aria-modal="true" aria-labelledby="memDeleteTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="memDeleteTitle">Delete Matrix</h2>
                    <button type="button" class="modal-close" wire:click="closeConfirmDelete" aria-label="Close" title="Close">×</button>
                </div>

                <div class="modal-form">
                    <p class="delete-modal-description">
                        Delete the matrix for <strong>{{ $confirmTitle }}</strong> and every line in it?
                        A WFP generated from it keeps the lines it already has (they simply lose their source link),
                        unless the plan has been finalized.
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

    {{-- WORKBOOK IMPORT — a single .xlsx whose sheets are JAN..DEC. --}}
    <div class="modal-backdrop {{ $showWorkbookImport ? 'is-open' : '' }}"
         @if ($showWorkbookImport) @click="self && $wire.closeWorkbookImport()" @endif
         role="presentation">
        <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="memWorkbookTitle" @click.stop>
            <div class="modal-head">
                <h2 id="memWorkbookTitle">Import Matrix Workbook</h2>
                <button type="button" class="modal-close" wire:click="closeWorkbookImport" aria-label="Close" title="Close">×</button>
            </div>

            <div class="modal-form">
                <div class="field">
                    <label for="memWorkbookFile">Matrix workbook (.xlsx)</label>
                    <input
                        id="memWorkbookFile"
                        type="file"
                        accept=".xlsx"
                        wire:model="workbookFile"
                        @class(['error' => $errors->has('workbookFile')])
                    >
                    <div class="field-hint">
                        One workbook whose worksheets are the months — <strong>JAN … DEC</strong>.
                        Each month it carries REPLACES that month's stored lines for
                        <strong>CY {{ $this->year !== '' ? $this->year : now()->year }}</strong>;
                        a month it does not carry is left as it is. Section banners (gray rows) and
                        TOTAL rows are skipped.
                    </div>
                    @error('workbookFile') <div class="error-text">{{ $message }}</div> @enderror
                    <div wire:loading wire:target="workbookFile" class="ac-import-loading">Reading workbook…</div>
                </div>

                @if ($workbookError)
                    <div class="ac-import-error">{{ $workbookError }}</div>
                @endif

                @if ($workbookMonths !== [])
                    <div class="ac-import-summary">
                        <span class="ac-import-pill ac-import-pill--ok">{{ $workbookValidCount }} ready to import</span>
                        @if ($workbookInvalidCount > 0)
                            <span class="ac-import-pill ac-import-pill--bad">{{ $workbookInvalidCount }} will be skipped</span>
                        @endif
                    </div>

                    <div class="table-wrap ac-import-preview">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th scope="col">Month</th>
                                    <th scope="col">Sheet</th>
                                    <th scope="col">Ready</th>
                                    <th scope="col">Skipped</th>
                                    <th scope="col" class="mem-num">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($workbookMonths as $month)
                                    <tr wire:key="wb-month-{{ $month['month'] }}">
                                        <td>{{ \App\Models\MonthlyExpenditureMatrix::MONTH_ABBREVIATIONS[$month['month']] ?? $month['month'] }}</td>
                                        <td><span class="ac-code">{{ $month['name'] }}</span></td>
                                        <td>{{ $month['valid'] }}</td>
                                        <td>{{ $month['invalid'] }}</td>
                                        <td class="mem-num">{{ number_format($month['total'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($workbookWarnings !== [])
                        <div class="field-hint">
                            @foreach ($workbookWarnings as $warning)
                                <div>{{ $warning }}</div>
                            @endforeach
                        </div>
                    @endif
                @endif

                <div class="modal-actions">
                    <button type="button" class="btn-ghost" wire:click="closeWorkbookImport">Cancel</button>
                    <button
                        type="button"
                        class="btn-primary"
                        wire:click="commitWorkbookImport"
                        wire:loading.attr="disabled"
                        wire:target="commitWorkbookImport"
                        @disabled($workbookValidCount === 0)
                    >
                        <span wire:loading.remove wire:target="commitWorkbookImport">Import {{ $workbookValidCount }} line(s)</span>
                        <span wire:loading wire:target="commitWorkbookImport">Importing…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
