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
            <a href="{{ route('wfp.matrices.index') }}" wire:navigate.hover>Monthly Expenditure Matrix</a>
            <span aria-hidden="true">›</span>
            <span>{{ $matrix->periodLabel() }}</span>
        </div>
        <h1>{{ $matrix->periodLabel() }}</h1>
        <p>
            The source of truth for your Work and Financial Plan.
            · {{ count($items) }} {{ count($items) === 1 ? 'line' : 'lines' }}
        </p>
    </div>

    {{-- THE ALLOTMENT BALANCE: the annual budget the Budget office set for
         this school, read against the year's matrices. The month being edited
         contributes its unsaved grid, so the balance moves as lines are added
         or removed — the SH sees what is left before saving. The month tabs
         below carry the same figure after each month. --}}
    @php($allocation = $this->allocation)
    <section
        class="allotment-summary{{ $allocation['is_over'] ? ' is-over' : '' }}"
        aria-label="Allotment budget for {{ $matrix->year }}"
    >
        <div class="allotment-summary__item">
            <span class="allotment-summary__label">Allotted budget</span>
            <strong class="allotment-summary__value">
                {{ $allocation['allotted'] === null ? 'Not set' : number_format($allocation['allotted'], 2) }}
            </strong>
            <span class="allotment-summary__hint">Set by the Budget office for CY {{ $matrix->year }}</span>
        </div>

        <div class="allotment-summary__item">
            <span class="allotment-summary__label">Consumed by monthly expenditures</span>
            <strong class="allotment-summary__value">{{ number_format($allocation['consumed'], 2) }}</strong>
            <span class="allotment-summary__hint">
                Your CY {{ $matrix->year }} matrices’ total — this month includes unsaved lines
            </span>
        </div>

        <div class="allotment-summary__item">
            <span class="allotment-summary__label">
                {{ $allocation['is_over'] ? 'Over allotment' : 'Remaining' }}
            </span>
            <strong @class([
                'allotment-summary__value',
                'is-over' => $allocation['is_over'],
                'is-unset' => $allocation['allotted'] === null,
            ])>
                {{ $allocation['allotted'] === null
                    ? '—'
                    : number_format(abs($allocation['remaining']), 2) }}
            </strong>
            <span class="allotment-summary__hint">
                {{ $allocation['allotted'] === null
                    ? 'No ceiling set for this year'
                    : ($allocation['is_over'] ? 'More spent than allotted' : 'Still available to spend') }}
            </span>
        </div>

        @if ($allocation['allotted'] !== null)
            <div
                class="allotment-summary__progress"
                role="progressbar"
                aria-label="Allotment used"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="{{ min(100, $allocation['percent']) }}"
            >
                <span
                    class="allotment-summary__progress-bar{{ $allocation['is_over'] ? ' is-over' : '' }}"
                    style="width: {{ min(100, max(0, $allocation['percent'])) }}%"
                ></span>
            </div>
            <p class="allotment-summary__note">
                {{ number_format($allocation['percent'], 2) }}% of the allotted budget consumed.
                Each month tab shows the running balance after that month.
            </p>
        @else
            <p class="allotment-summary__note">
                No allotment budget has been set for CY {{ $matrix->year }} yet.
                The Budget office sets it in School Allotments; this month’s lines are recorded either way.
            </p>
        @endif
    </section>

    {{-- MONTH NAVIGATION — the twelve months of this matrix's year, so moving
         through the year never means going back to the list. The month being
         edited is marked; the others link straight to their own matrix. A month
         that is validated or finalized carries a small status dot, so the year's
         progress reads at a glance; a month whose matrix was deleted is
         inactive. Each tab also carries that month's total — the same figure as
         the monthly total at the bottom — so the year's spending reads without
         opening the months one by one. When the Budget office has set an annual
         allotment, the tab also carries the running balance after that month. --}}
    <nav class="mem-month-tabs" aria-label="Months of CY {{ $matrix->year }}">
        @foreach ($this->months as $tab)
            @php($tabTotal = $tab['total'] !== null ? number_format($tab['total'], 2) : null)

            @if ($tab['current'])
                <span class="mem-month-tab is-current" aria-current="page" title="{{ $tab['label'] }}">
                    <span class="mem-month-tab__head">
                        <span class="mem-month-tab__abbr">{{ $tab['abbr'] }}</span>
                        @if (in_array($tab['status'], ['validated', 'finalized'], true))
                            <span class="mem-month-tab__dot mem-month-tab__dot--{{ $tab['status'] }}" aria-hidden="true"></span>
                        @endif
                    </span>
                    <span class="mem-month-tab__total">
                        @if ($tabTotal !== null)
                            <span class="sr-only">Monthly total </span>{{ $tabTotal }}
                        @else
                            <span aria-hidden="true">—</span>
                        @endif
                    </span>
                    @if ($tab['balance'] !== null)
                        <span @class(['mem-month-tab__balance', 'is-over' => $tab['balance'] < 0])
                              title="{{ $tab['balance'] < 0 ? 'Over allotment by ' : 'Balance after '.$tab['label'].': ' }}{{ number_format(abs($tab['balance']), 2) }}">
                            {{ $tab['balance'] < 0 ? 'Over ' : 'Bal. ' }}{{ number_format(abs($tab['balance']), 2) }}
                        </span>
                    @endif
                </span>
            @elseif ($tab['id'] !== null)
                <a
                    href="{{ route('wfp.matrices.edit', ['matrix' => $tab['id']]) }}"
                    wire:navigate.hover
                    class="mem-month-tab"
                    title="Open {{ $tab['label'] }}"
                >
                    <span class="mem-month-tab__head">
                        <span class="mem-month-tab__abbr">{{ $tab['abbr'] }}</span>
                        @if (in_array($tab['status'], ['validated', 'finalized'], true))
                            <span class="mem-month-tab__dot mem-month-tab__dot--{{ $tab['status'] }}" aria-hidden="true"></span>
                        @endif
                    </span>
                    <span class="mem-month-tab__total">
                        @if ($tabTotal !== null)
                            <span class="sr-only">Monthly total </span>{{ $tabTotal }}
                        @else
                            <span aria-hidden="true">—</span>
                        @endif
                    </span>
                    @if ($tab['balance'] !== null)
                        <span @class(['mem-month-tab__balance', 'is-over' => $tab['balance'] < 0])
                              title="{{ $tab['balance'] < 0 ? 'Over allotment by ' : 'Balance after '.$tab['label'].': ' }}{{ number_format(abs($tab['balance']), 2) }}">
                            {{ $tab['balance'] < 0 ? 'Over ' : 'Bal. ' }}{{ number_format(abs($tab['balance']), 2) }}
                        </span>
                    @endif
                </a>
            @else
                <span class="mem-month-tab is-empty" title="{{ $tab['label'] }} — no matrix yet">
                    <span class="mem-month-tab__head">
                        <span class="mem-month-tab__abbr">{{ $tab['abbr'] }}</span>
                    </span>
                    <span class="mem-month-tab__total" aria-hidden="true">—</span>
                    @if ($tab['balance'] !== null)
                        <span @class(['mem-month-tab__balance', 'is-over' => $tab['balance'] < 0])
                              title="{{ $tab['balance'] < 0 ? 'Over allotment by ' : 'Balance after '.$tab['label'].': ' }}{{ number_format(abs($tab['balance']), 2) }}">
                            {{ $tab['balance'] < 0 ? 'Over ' : 'Bal. ' }}{{ number_format(abs($tab['balance']), 2) }}
                        </span>
                    @endif
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
                @if ($this->canEdit())
                    Lines are grouped by section. Drag a row's grip to reorder it — the new order saves automatically. The pencil edits a line; totals update as you go.
                @else
                    Read-only — the School Head edits these lines. Read them here to check their account titles and codes.
                @endif
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

               {{-- The template and the CSV import exist to bring lines in —
                    writing work, so only a writing account is offered them. --}}
                @if ($this->canEdit())
                    <a
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
                    </button>
                @endif
            </div>
        </div>

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

        @if ($items === [])
            <div class="mem-empty">
                <x-icon name="file-spreadsheet" :size="28" />
                <h3>No expenditure lines yet</h3>
                <p>
                    Each line is one activity for the month — what it is, how many, at what cost,
                    and the account it is charged to. Add them one at a time, or bring in a whole
                    month from a CSV.
                </p>

                @if ($this->canEdit())
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
                @endif
            </div>
        @else
            @foreach (['operations' => 'Operations', 'project' => 'Project'] as $sectionKey => $sectionShort)
                @php($sectionRows = $this->rowsForSection($sectionKey))
                @php($sectionLabel = $sectionShort.'-Related Expenditures')

                {{-- ONE SECTION of the matrix, drawn as the printed form draws it:
                     the section banner, the official column headings, then the
                     section's lines. The lines are grouped here rather than
                     marked by a dropdown in every row, so Operations and Project
                     read as two lists, each with its own subtotal and add button. --}}
                <section class="mem-section" aria-label="{{ $sectionLabel }}">
                    <div class="table-wrap">
                        <table class="data-table mem-list">
                            {{-- Explicit column ruler: table-layout: fixed uses these
                                 shares instead of each cell's content, so the eleven
                                 printed-form columns divide the page width and the
                                 table never spills into a horizontal scrollbar. --}}
                            <colgroup>
                                <col class="mem-col mem-col--line">
                                <col class="mem-col mem-col--objective">
                                <col class="mem-col mem-col--activity">
                                <col class="mem-col mem-col--kind">
                                <col class="mem-col mem-col--quantity">
                                <col class="mem-col mem-col--unit">
                                <col class="mem-col mem-col--unit-cost">
                                <col class="mem-col mem-col--total">
                                <col class="mem-col mem-col--account-title">
                                <col class="mem-col mem-col--account-code">
                                <col class="mem-col mem-col--actions">
                            </colgroup>
                            <thead>
                                {{-- The section banner heads the table, as on the
                                     printed form. It is a row of the table (not a
                                     loose element above it), so it spans the same
                                     width as the columns and scrolls with them:
                                     label and line count on the left, subtotal and
                                     add button on the right. --}}
                                <tr class="mem-list__banner">
                                    <td colspan="11">
                                        <div class="mem-list__banner-bar">
                                            <span class="mem-list__banner-title">
                                                <span>{{ $sectionLabel }}</span>
                                                <span class="mem-list__banner-count">
                                                    {{ count($sectionRows) }} {{ count($sectionRows) === 1 ? 'line' : 'lines' }}
                                                </span>
                                            </span>

                                            <span class="mem-list__banner-aside">
                                                <span class="mem-list__banner-subtotal">
                                                    Subtotal <strong>{{ number_format($this->totals[$sectionKey], 2) }}</strong>
                                                </span>

                                                @if ($this->canEdit())
                                                    <button type="button" class="ac-import-btn" wire:click="openLineModal('{{ $sectionKey }}')">
                                                        <x-icon name="plus" :size="15" />
                                                        <span>Add {{ \Illuminate\Support\Str::lower($sectionShort) }} line</span>
                                                    </button>
                                                @endif
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="col" class="mem-list__gutter"><span class="sr-only">Line</span></th>
                                    <th scope="col">Objectives</th>
                                    <th scope="col">Project/<wbr>Activity/<wbr>Expenditure</th>
                                    <th scope="colgroup" colspan="4">Resources Needed</th>
                                    <th scope="col" class="mem-num">Total Estimated Cost</th>
                                    <th scope="col">Account Title</th>
                                    <th scope="col">Account Code</th>
                                    <th scope="col" class="users-actions-col">Actions</th>
                                </tr>
                                <tr>
                                    <th scope="col" class="mem-list__gutter" aria-hidden="true"></th>
                                    <th scope="col" class="mem-list__subhead" aria-hidden="true"></th>
                                    <th scope="col" class="mem-list__subhead" aria-hidden="true"></th>
                                    <th scope="col">Kind</th>
                                    <th scope="col" class="mem-num mem-list__quantity">Quantity</th>
                                    <th scope="col">Unit</th>
                                    <th scope="col" class="mem-num">Unit Cost</th>
                                    <th scope="col" class="mem-list__subhead" aria-hidden="true"></th>
                                    <th scope="col" class="mem-list__subhead" aria-hidden="true"></th>
                                    <th scope="col" class="mem-list__subhead" aria-hidden="true"></th>
                                    <th scope="col" class="users-actions-col" aria-hidden="true"></th>
                                </tr>
                            </thead>
                            {{-- Drag-reordering is a write, so only a writing
                                 account's list is wired for it. --}}
                            <tbody @if ($this->canEdit()) wire:sort="reorderRow" @endif>

                               

                                @forelse ($sectionRows as $entry)
                                    {{-- A read-only LIST row. The line is changed in the
                                         modal (the pencil), not by typing into the grid,
                                         so a month's lines stay compact and scannable. --}}
                                    @php($i = $entry['index'])
                                    @php($row = $entry['row'])
                                    {{-- Server-built rows always carry a _key; a crafted
                                         request body need not, and a missing key must
                                         not blow up the page. --}}
                                    @php($rowKey = (string) ($row['_key'] ?? 'row-'.$i))
                                    @php($activity = (string) ($row['activity_description'] ?? ''))
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
                                    <tr wire:key="row-{{ $rowKey }}" @if ($this->canEdit()) wire:sort:item="{{ $rowKey }}" @endif>
                                        <td class="mem-rownum mem-list__gutter">
                                            <span class="mem-rownum__inner">
                                                @if ($this->canEdit())
                                                    <span
                                                        class="mem-drag-handle"
                                                        wire:sort:handle
                                                        title="Drag to reorder"
                                                        aria-hidden="true"
                                                    >
                                                        <x-icon name="grip-lines" :size="16" />
                                                    </span>
                                                @endif
                                                <span>{{ $i + 1 }}</span>
                                            </span>
                                        </td>
                                        <td class="mem-list__objective" data-label="Objectives">
                                            @if ($objective !== '')
                                                <span title="{{ $objective }}">{{ \Illuminate\Support\Str::limit($objective, 200) }}</span>
                                            @else
                                                <span class="mem-list__muted">—</span>
                                            @endif
                                        </td>
                                        <td data-label="Project/Activity/Expenditure">
                                            <span class="cell-strong">{{ $activity !== '' ? $activity : '—' }}</span>
                                            @if ($funding !== '' || $org !== '')
                                                <div class="mem-list__meta">
                                                    @if ($funding !== '') <span class="mem-list__meta-item">Funding: {{ $funding }}</span> @endif
                                                    @if ($org !== '') <span class="mem-list__meta-item">{{ $org }}</span> @endif
                                                </div>
                                            @endif
                                            @if ($movs !== '')
                                                <div class="mem-list__movs">MOVs: {{ \Illuminate\Support\Str::limit($movs, 100) }}</div>
                                            @endif
                                        </td>
                                        <td data-label="Kind">
                                            @if ($resourceKind !== '')
                                                {{ $resourceKind }}
                                            @else
                                                <span class="mem-list__muted">—</span>
                                            @endif
                                        </td>
                                        <td class="mem-num" data-label="Quantity">
                                            @if ($quantity !== '')
                                                {{ $quantity }}
                                            @else
                                                <span class="mem-list__muted">—</span>
                                            @endif
                                        </td>
                                        <td data-label="Unit">
                                            @if ($unit !== '')
                                                {{ $unit }}
                                            @else
                                                <span class="mem-list__muted">—</span>
                                            @endif
                                        </td>
                                        <td class="mem-num" data-label="Unit Cost">
                                            @if ($unitCost !== '')
                                                {{ number_format((float) $unitCost, 2) }}
                                            @else
                                                <span class="mem-list__muted">—</span>
                                            @endif
                                        </td>
                                        <td class="mem-num mem-linetotal--cell" data-label="Total Estimated Cost">
                                            {{ number_format(\App\Livewire\MemEditor::lineTotal($row), 2) }}
                                        </td>
                                        <td data-label="Account Title">
                                            @if ($accountTitle !== '')
                                                <span class="cell-strong">{{ $accountTitle }}</span>
                                            @else
                                                <span class="mem-list__muted">—</span>
                                            @endif
                                        </td>
                                        <td data-label="Account Code">
                                            @if ($accountCode !== '')
                                                <span class="ac-code">{{ $accountCode }}</span>
                                            @else
                                                <span class="mem-list__muted">—</span>
                                            @endif
                                        </td>
                                        <td class="users-actions-col">
                                            @if ($this->canEdit())
                                                <div class="users-actions-row">
                                                    <button
                                                        type="button"
                                                        class="mem-row-action"
                                                        wire:click="openEditModal('{{ $rowKey }}')"
                                                        title="Edit this line"
                                                    >
                                                        <x-icon name="pencil" :size="15" />
                                                    </button>

                                                    <button
                                                        type="button"
                                                        class="mem-row-action"
                                                        wire:click="duplicateRow('{{ $rowKey }}')"
                                                        title="Duplicate this line"
                                                    >
                                                        <x-icon name="copy" :size="15" />
                                                    </button>

                                                    <button
                                                        type="button"
                                                        class="mem-row-action"
                                                        wire:click="moveRow('{{ $rowKey }}')"
                                                        title="Move to {{ $sectionKey === 'operations' ? 'Project' : 'Operations' }}"
                                                    >
                                                        <x-icon name="arrow-right-left" :size="15" />
                                                    </button>

                                                    <button
                                                        type="button"
                                                        class="mem-row-remove"
                                                        wire:click="removeRow('{{ $rowKey }}')"
                                                        title="Remove this line"
                                                    >
                                                        <x-icon name="trash" :size="15" />
                                                    </button>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr class="mem-list__empty-row">
                                        <td colspan="11" class="table-empty">
                                            No {{ \Illuminate\Support\Str::lower($sectionKey) }} lines yet — add one above, or import a CSV.
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

    </div>

    {{-- The writing modals exist for a writing account only: a read-only
         reader (the Bookkeeper) is never offered a trigger, and each action
         behind them refuses a crafted call. --}}
    @if ($this->canEdit())
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
                {{-- TEMPLATE PICKER: choose a reusable activity template to
                     pre-fill the line. The user can review and adjust every
                     field before adding the line. --}}
                @if ($pendingTemplateId !== null)
                    @php($pendingTemplate = \App\Models\WfpLineTemplate::query()->active()->find($pendingTemplateId))
                    <div class="field mem-line-span ac-import-error mem-banner" role="alert">
                        You’ve edited this line. Apply <strong>{{ $pendingTemplate?->name }}</strong> and replace
                        your edits, or keep your edits?
                        <button type="button" class="btn-primary" wire:click="confirmTemplateReplace" style="margin-left:10px">Replace</button>
                        <button type="button" class="btn-ghost" wire:click="keepTemplateEdits" style="margin-left:6px">Keep edits</button>
                        <button type="button" class="btn-ghost" wire:click="dismissTemplate" style="margin-left:6px">Cancel</button>
                    </div>
                @endif

                @if ($appliedTemplateId !== null && $pendingTemplateId === null)
                    <div class="field mem-line-span wfp-template-applied">
                        <span class="wfp-template-applied__label">Filled from template <strong>{{ $appliedTemplateName }}</strong></span>
                        <button type="button" class="wfp-template-applied__clear" wire:click="clearTemplate" title="Clear template">Clear template</button>
                    </div>
                @endif

                <div class="field mem-line-span">
                    <label for="memLineTemplate">Start from a template <span class="field-optional">(optional)</span></label>

                    <div class="mem-combo" x-data="{ open: false }" @click.outside="open = false">
                        <span class="mem-combo__search" aria-hidden="true">
                            <x-icon name="search" :size="15" />
                        </span>

                        <input
                            id="memLineTemplate"
                            type="text"
                            autocomplete="off"
                            role="combobox"
                            aria-autocomplete="list"
                            aria-controls="memTemplateOptions"
                            :aria-expanded="String(open)"
                            wire:model.live.debounce.300ms="templateSearch"
                            placeholder="Search templates…"
                            @focus="open = true"
                            @keydown.escape="open = false"
                        >

                        @if (trim($templateSearch) !== '')
                            <button
                                type="button"
                                class="mem-combo__clear"
                                wire:click="$set('templateSearch', '')"
                                aria-label="Clear template search"
                                title="Clear"
                            >
                                <x-icon name="x" :size="14" />
                            </button>
                        @endif

                        <div
                            id="memTemplateOptions"
                            class="mem-combo__menu"
                            role="listbox"
                            :class="open ? 'open' : ''"
                        >
                            @forelse ($this->templateOptions as $option)
                                <button
                                    type="button"
                                    role="option"
                                    class="mem-combo__option"
                                    wire:key="tmpl-opt-{{ $option->id }}"
                                    wire:click="chooseTemplate({{ $option->id }})"
                                    @click="open = false"
                                >
                                    <span class="mem-combo__option-main">
                                        <span class="mem-combo__option-title">{{ $option->name }}</span>
                                        @if (trim((string) ($option->objective ?? '')) !== '')
                                            <span class="mem-combo__option-sub">{{ \Illuminate\Support\Str::limit($option->objective, 90) }}</span>
                                        @endif
                                    </span>
                                    <span class="mem-combo__option-meta">
                                        <span class="badge">{{ $option->lineTypeLabel() }}</span>
                                        @if ($option->accountClassification?->uacs_object_code)
                                            <span class="mem-combo__rca">[{{ $option->accountClassification->uacs_object_code }}]</span>
                                        @endif
                                    </span>
                                </button>
                            @empty
                                <div class="mem-combo__empty">No matching templates.</div>
                            @endforelse
                        </div>
                    </div>

                    @error('templateSearch') <div class="error-text">{{ $message }}</div> @enderror
                    <div class="field-hint">Pick a reusable template to pre-fill the line, then adjust any field before adding it.</div>
                </div>

                {{-- Two-column grid (.mem-line-fields) so the whole line fits
                     on screen without scrolling; a few fields span both
                     columns. See dashboard.css. --}}
                <div class="mem-line-fields">
                    <div class="mem-line-sections mem-line-span" role="group" aria-label="Section">
                        @foreach (['operations' => 'Operations-Related Expenditures', 'project' => 'Project-Related Expenditures'] as $key => $label)
                            <label class="mem-line-section">
                                <input type="radio" wire:model.live="lineSection" value="{{ $key }}">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div class="field">
                        <label for="memLineObjective">
                            Objectives
                            @if ($this->templateFieldIsFilled('objective'))
                                <span class="field-template-tag">from template</span>
                            @endif
                        </label>
                        <textarea
                            id="memLineObjective"
                            rows="2"
                            wire:model="lineForm.objective"
                            placeholder="What the activity serves"
                        ></textarea>
                        @error('lineForm.objective') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                     <div class="field mem-line-span">
                        <label for="memLineActivity">
                            Project/Activity/Expenditure <span class="field-required">required</span>
                            @if ($this->templateFieldIsFilled('activity_description'))
                                <span class="field-template-tag">from template</span>
                            @endif
                        </label>
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

                    {{-- What the line buys, in one labeled group: kind, quantity,
                         unit and unit cost are one thought, and the total is
                         their product. Quantity and unit cost are live so the
                         total recalculates while typing instead of waiting for
                         a blur or a save. --}}
                    <div class="mem-line-resource mem-line-span" role="group" aria-labelledby="memLineResourceLabel">
                        <span id="memLineResourceLabel" class="mem-line-resource__label">
                            Resource Needed
                            @if ($this->templateFieldIsFilled('resource_kind') || $this->templateFieldIsFilled('unit') || $this->templateFieldIsFilled('quantity') || $this->templateFieldIsFilled('unit_cost'))
                                <span class="field-template-tag">from template</span>
                            @endif
                        </span>

                        <div class="mem-line-resource__fields">
                            <div class="field">
                                <label for="memLineKind">Kind</label>
                                <input id="memLineKind" type="text" list="memResourceKindOptions" wire:model="lineForm.resource_kind" placeholder="e.g. MOOE">
                                <datalist id="memResourceKindOptions">
                                    @foreach ($this->resourceKindOptions as $option)
                                        <option value="{{ $option->name }}"></option>
                                    @endforeach
                                </datalist>
                                @error('lineForm.resource_kind') <div class="error-text">{{ $message }}</div> @enderror
                            </div>

                            <div class="field">
                                <label for="memLineQty">Quantity</label>
                                <input id="memLineQty" type="number" step="0.01" min="0" inputmode="decimal" wire:model.live.debounce.300ms="lineForm.quantity" placeholder="0">
                                @error('lineForm.quantity') <div class="error-text">{{ $message }}</div> @enderror
                            </div>

                            <div class="field">
                                <label for="memLineUnit">Unit</label>
                                <input id="memLineUnit" type="text" list="memUnitOptions" wire:model="lineForm.unit" placeholder="e.g. ream">
                                <datalist id="memUnitOptions">
                                    @foreach ($this->unitOptions as $option)
                                        <option value="{{ $option->name }}"></option>
                                    @endforeach
                                </datalist>
                                @error('lineForm.unit') <div class="error-text">{{ $message }}</div> @enderror
                            </div>

                            <div class="field">
                                <label for="memLineCost">Unit cost</label>
                                <input id="memLineCost" type="number" step="0.01" min="0" inputmode="decimal" wire:model.live.debounce.300ms="lineForm.unit_cost" placeholder="0.00">
                                @error('lineForm.unit_cost') <div class="error-text">{{ $message }}</div> @enderror
                            </div>

                            <div class="mem-line-total" aria-live="polite">
                                <span class="mem-line-total__label">Total Estimated Cost</span>
                                <span class="mem-line-total__value">{{ number_format(\App\Livewire\MemEditor::lineTotal($lineForm), 2) }}</span>
                            </div>
                        </div>
                    </div>

                    {{-- Account title / code: one searchable dropdown. Typing
                         filters the active account classifications by title or
                         object code; picking one fills the title, the code and
                         the classification link. Free text still works — if
                         nothing is picked, what was typed becomes the title. --}}
                    <div class="field">
                        <label for="memLineAccount">
                            Account title / Account code
                            @if ($this->templateFieldIsFilled('account_title'))
                                <span class="field-template-tag">from template</span>
                            @endif
                        </label>

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
                        <label for="memLineFunding">
                            Funding source
                            @if ($this->templateFieldIsFilled('funding_source'))
                                <span class="field-template-tag">from template</span>
                            @endif
                        </label>
                        <input id="memLineFunding" type="text" list="memFundingSourceOptions" wire:model="lineForm.funding_source" placeholder="e.g. MOOE">
                        <datalist id="memFundingSourceOptions">
                            @foreach ($this->fundingSourceOptions as $option)
                                <option value="{{ $option->name }}"></option>
                            @endforeach
                        </datalist>
                        @error('lineForm.funding_source') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="memLineOrg">
                            Org. classification
                            @if ($this->templateFieldIsFilled('organizational_classification'))
                                <span class="field-template-tag">from template</span>
                            @endif
                        </label>
                        <input id="memLineOrg" type="text" list="memOrgClassificationOptions" wire:model="lineForm.organizational_classification" placeholder="e.g. School">
                        <datalist id="memOrgClassificationOptions">
                            @foreach ($this->orgClassificationOptions as $option)
                                <option value="{{ $option->name }}"></option>
                            @endforeach
                        </datalist>
                        @error('lineForm.organizational_classification') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="memLineMajorOutput">
                            Major output
                            @if ($this->templateFieldIsFilled('major_output'))
                                <span class="field-template-tag">from template</span>
                            @endif
                        </label>
                        <textarea
                            id="memLineMajorOutput"
                            rows="2"
                            wire:model="lineForm.major_output"
                            placeholder="The result this line delivers"
                        ></textarea>
                        @error('lineForm.major_output') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="memLinePerformanceIndicator">
                            Performance indicator
                            @if ($this->templateFieldIsFilled('performance_indicator'))
                                <span class="field-template-tag">from template</span>
                            @endif
                        </label>
                        <textarea
                            id="memLinePerformanceIndicator"
                            rows="2"
                            wire:model="lineForm.performance_indicator"
                            placeholder="How the output is measured"
                        ></textarea>
                        @error('lineForm.performance_indicator') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field mem-line-span">
                        <label for="memLineMovs">
                            Activity MOVs
                            @if ($this->templateFieldIsFilled('activity_movs'))
                                <span class="field-template-tag">from template</span>
                            @endif
                        </label>
                        <textarea
                            id="memLineMovs"
                            rows="2"
                            wire:model="lineForm.activity_movs"
                            placeholder="e.g. PO and RIS"
                        ></textarea>
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
    @endif
</div>
