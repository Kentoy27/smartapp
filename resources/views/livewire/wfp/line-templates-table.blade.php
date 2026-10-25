<div class="wfp-setup">
    {{-- SUCCESS ALERT: popped once as a SweetAlert, then cleared so later
         renders never re-fire it (same pattern as the Users table). --}}
    @if ($successMessage)
        <div
            wire:key="wfp-templates-alert-{{ md5($successMessage) }}"
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
        <h1>Activity Templates</h1>
        <p>Reusable combinations of fields for common WFP lines. Selecting one in the Add a Line form fills the fields it has a value for; each saved line keeps its own copy, so editing a template never rewrites a saved plan.</p>
    </div>

    <div class="card">
        <div class="card-head users-toolbar">
            <div class="users-search">
                <input
                    type="search"
                    placeholder="Search templates…"
                    wire:model.live.debounce.300ms="search"
                    aria-label="Search activity templates"
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
                <select wire:model.live="sort" aria-label="Sort templates">
                    <option value="name">Name</option>
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
                    href="{{ route('wfp.setup.templates.template') }}"
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
                    <span>Add Template</span>
                </button>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table wfp-template-table">
                <thead>
                    <tr>
                        <th scope="col">Template</th>
                        <th scope="col">Line type</th>
                        <th scope="col">Project / Activity / Expenditure</th>
                        <th scope="col">Resource / Unit</th>
                        <th scope="col">Account</th>
                        <th scope="col">Funding source</th>
                        <th scope="col">Org. classification</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="users-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr wire:key="wfp-template-{{ $record->id }}">
                            <td>
                                <span class="cell-strong">{{ $record->name }}</span>
                                @if ($record->description !== '')
                                    <div class="wfp-template__meta">{{ \Illuminate\Support\Str::limit($record->description, 120) }}</div>
                                @endif
                            </td>
                            <td><span class="badge">{{ $record->lineTypeLabel() }}</span></td>
                            <td>{{ $record->activity_expenditure ?: '—' }}</td>
                            <td>
                                @if ($record->resourceKind?->name !== '')
                                    {{ $record->resourceKind->name }}
                                    @if ($record->unit?->name !== '')
                                        <span class="wfp-template__meta">/ {{ $record->unit->name }}</span>
                                    @endif
                                @elseif ($record->unit?->name !== '')
                                    {{ $record->unit->name }}
                                @else
                                    <span class="mem-list__muted">—</span>
                                @endif
                            </td>
                            <td>{{ $record->accountClassification?->account_title ?: '—' }}</td>
                            <td>{{ $record->fundingSource?->name ?: '—' }}</td>
                            <td>{{ $record->orgClassification?->name ?: '—' }}</td>
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
                            <td colspan="9" class="table-empty">
                                @if (trim($search) !== '')
                                    No templates match “{{ $search }}”.
                                @elseif ($status !== 'all')
                                    No {{ $status }} templates to display.
                                @else
                                    No activity templates yet — add the first one.
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
                    0 templates
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
            <div class="modal modal--confirmation" role="dialog" aria-modal="true" aria-labelledby="wfpTemplateDeleteTitle" @click.stop>
                <div class="modal-head">
                    <h2 id="wfpTemplateDeleteTitle">Deactivate Template</h2>
                    <button type="button" class="modal-close" wire:click="closeConfirmDelete" aria-label="Close" title="Close">×</button>
                </div>

                <div class="modal-form">
                    <p class="delete-modal-description">
                        Are you sure you want to deactivate <strong>{{ $confirmTitle }}</strong>?
                        It will be hidden from new selections but stays readable on historical lines.
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

    {{-- ADD / EDIT TEMPLATE MODAL (one modal, two modes) --}}
    <div class="modal-backdrop {{ $showForm ? 'is-open' : '' }}"
         @if ($showForm) @click="self && $wire.closeForm()" @endif
         role="presentation">
        <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="wfpTemplateFormTitle" @click.stop>
            <div class="modal-head">
                <h2 id="wfpTemplateFormTitle">{{ $editingId ? 'Edit Template' : 'Add Template' }}</h2>
                <button type="button" class="modal-close" wire:click="closeForm" aria-label="Close" title="Close">×</button>
            </div>

            <form wire:submit="save" class="modal-form">
                <div class="field mem-line-span">
                    <label for="wfpTemplateName">Template name <span class="field-required">required</span></label>
                    <input
                        id="wfpTemplateName"
                        type="text"
                        wire:model="name"
                        placeholder="e.g. Procure office supplies"
                        @class(['error' => $errors->has('name')])
                        required
                        autofocus
                    >
                    @error('name') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="wfpTemplateDescription">Description</label>
                    <textarea
                        id="wfpTemplateDescription"
                        rows="2"
                        wire:model="description"
                        placeholder="What this template is for"
                    ></textarea>
                    @error('description') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field mem-line-span" role="group" aria-label="Line type">
                    <label>Line type</label>
                    @foreach ([\App\Models\WfpLineTemplate::LINE_TYPE_OPERATIONS => 'Operations-Related Expenditures', \App\Models\WfpLineTemplate::LINE_TYPE_PROJECT => 'Project-Related Expenditures'] as $key => $label)
                        <label class="mem-line-section">
                            <input type="radio" wire:model="lineType" value="{{ $key }}">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="field mem-line-span">
                    <label for="wfpTemplateObjective">Objective</label>
                    <textarea
                        id="wfpTemplateObjective"
                        rows="2"
                        wire:model="objective"
                        placeholder="What the activity serves"
                    ></textarea>
                    @error('objective') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field mem-line-span">
                    <label for="wfpTemplateActivity">Project/Activity/Expenditure <span class="field-required">required</span></label>
                    <input
                        id="wfpTemplateActivity"
                        type="text"
                        wire:model="activityExpenditure"
                        placeholder="e.g. Procure office supplies"
                        @class(['error' => $errors->has('activityExpenditure')])
                        required
                    >
                    @error('activityExpenditure') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                {{-- Resource block: kind, quantity, unit, unit cost. --}}
                <div class="mem-line-resource mem-line-span" role="group" aria-labelledby="wfpTemplateResourceLabel">
                    <span id="wfpTemplateResourceLabel" class="mem-line-resource__label">Resource Needed</span>

                    <div class="mem-line-resource__fields">
                        <div class="field">
                            <label for="wfpTemplateResourceKind">Resource kind</label>
                            <select id="wfpTemplateResourceKind" wire:model="resourceKindId">
                                <option value="">— none —</option>
                                @foreach ($this->resourceKindOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                            @error('resourceKindId') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="field">
                            <label for="wfpTemplateSuggestedQuantity">Suggested quantity</label>
                            <input
                                id="wfpTemplateSuggestedQuantity"
                                type="number"
                                step="0.01"
                                min="0"
                                wire:model="suggestedQuantity"
                                placeholder="0"
                                @class(['error' => $errors->has('suggestedQuantity')])
                            >
                            @error('suggestedQuantity') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="field">
                            <label for="wfpTemplateUnit">Unit</label>
                            <select id="wfpTemplateUnit" wire:model="unitId">
                                <option value="">— none —</option>
                                @foreach ($this->unitOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                            @error('unitId') <div class="error-text">{{ $message }}</div> @enderror
                        </div>

                        <div class="field">
                            <label for="wfpTemplateSuggestedUnitCost">Suggested unit cost</label>
                            <input
                                id="wfpTemplateSuggestedUnitCost"
                                type="number"
                                step="0.01"
                                min="0"
                                wire:model="suggestedUnitCost"
                                placeholder="0.00"
                                @class(['error' => $errors->has('suggestedUnitCost')])
                            >
                            @error('suggestedUnitCost') <div class="error-text">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>

                {{-- Account: searchable combobox like the Add a Line form. --}}
                <div class="field">
                    <label for="wfpTemplateAccount">Account title / Account code</label>

                    <div class="mem-combo" x-data="{ open: false }" @click.outside="open = false">
                        <span class="mem-combo__search" aria-hidden="true">
                            <x-icon name="search" :size="15" />
                        </span>

                        <input
                            id="wfpTemplateAccount"
                            type="text"
                            autocomplete="off"
                            role="combobox"
                            aria-autocomplete="list"
                            aria-controls="wfpTemplateAccountOptions"
                            :aria-expanded="String(open)"
                            wire:model.live.debounce.300ms="accountSearch"
                            placeholder="Search title or object code…"
                            @focus="open = true"
                            @keydown.escape="open = false"
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

                        <div
                            id="wfpTemplateAccountOptions"
                            class="mem-combo__menu"
                            role="listbox"
                            :class="open ? 'open' : ''"
                        >
                            @forelse ($this->accountOptions as $option)
                                <button
                                    type="button"
                                    role="option"
                                    class="mem-combo__option"
                                    wire:key="wfp-template-ac-opt-{{ $option->id }}"
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

                    @error('accountClassificationId') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="mem-line-resource__fields">
                    <div class="field">
                        <label for="wfpTemplateFundingSource">Funding source</label>
                        <select id="wfpTemplateFundingSource" wire:model="fundingSourceId">
                            <option value="">— none —</option>
                            @foreach ($this->fundingSourceOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @error('fundingSourceId') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="wfpTemplateOrgClassification">Org. classification</label>
                        <select id="wfpTemplateOrgClassification" wire:model="orgClassificationId">
                            <option value="">— none —</option>
                            @foreach ($this->orgClassificationOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @error('orgClassificationId') <div class="error-text">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="field">
                    <label for="wfpTemplateMajorOutput">Major output</label>
                    <textarea
                        id="wfpTemplateMajorOutput"
                        rows="2"
                        wire:model="majorOutput"
                        placeholder="The result this line delivers"
                    ></textarea>
                    @error('majorOutput') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="wfpTemplatePerformanceIndicator">Performance indicator</label>
                    <textarea
                        id="wfpTemplatePerformanceIndicator"
                        rows="2"
                        wire:model="performanceIndicator"
                        placeholder="How the output is measured"
                    ></textarea>
                    @error('performanceIndicator') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field mem-line-span">
                    <label for="wfpTemplateMovs">Activity MOVs</label>
                    <textarea
                        id="wfpTemplateMovs"
                        rows="2"
                        wire:model="activityMovs"
                        placeholder="e.g. PO and RIS"
                    ></textarea>
                    @error('activityMovs') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="wfpTemplateTags">Tags <span class="field-optional">(optional)</span></label>
                    <input
                        id="wfpTemplateTags"
                        type="text"
                        wire:model="tags"
                        placeholder="e.g. supplies, mooe"
                        @class(['error' => $errors->has('tags')])
                    >
                    @error('tags') <div class="error-text">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="wfpTemplateStatus">Status</label>
                    <select id="wfpTemplateStatus" wire:model="formStatus">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <div class="field-hint">Inactive templates stay readable on historical lines but are not offered for new ones.</div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-ghost" wire:click="closeForm">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save Changes' : 'Add Template' }}</span>
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
        <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="wfpTemplateImportTitle" @click.stop>
            <div class="modal-head">
                <h2 id="wfpTemplateImportTitle">Import Activity Templates</h2>
                <button type="button" class="modal-close" wire:click="closeImport" aria-label="Close" title="Close">×</button>
            </div>

            <div class="modal-form">
                <div class="field">
                    <label for="wfpTemplateImportFile">CSV file</label>
                    <input
                        id="wfpTemplateImportFile"
                        type="file"
                        accept=".csv,text/csv,.txt"
                        wire:model="importFile"
                        @class(['error' => $errors->has('importFile')])
                    >
                    <div class="field-hint">
                        Columns: <strong>Template Name, Line Type, Description, Objective, Project/Activity/Expenditure, Resource Kind, Suggested Quantity, Unit, Suggested Unit Cost, Account Code, Funding Source, Org. Classification, Major Output, Performance Indicator, Activity MOVs, Tags, Active</strong>.
                        Related records (unit, funding source, account code…) are resolved by their code or name among active records.
                        Not sure of the format?
                        <a href="{{ route('wfp.setup.templates.template') }}" class="ac-import-template-link" download>Download the CSV template</a>.
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
                        <option value="insert">Insert only — new templates; duplicates are skipped</option>
                        <option value="update">Update matching — existing templates are updated; unmatched rows are skipped</option>
                        <option value="upsert">Upsert — update matches, insert everything else</option>
                    </select>
                    <div class="field-hint">Templates match by name and line type.</div>
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
                                    <th scope="col">Template</th>
                                    <th scope="col">Activity</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($importRows as $row)
                                    <tr wire:key="wfp-template-import-row-{{ $row['line'] }}" @class(['ac-import-row--bad' => ! $row['valid']])>
                                        <td>{{ $row['line'] }}</td>
                                        <td><span class="cell-strong">{{ $row['data']['name'] ?: '—' }}</span></td>
                                        <td>{{ $row['data']['activity_expenditure'] ?: '—' }}</td>
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
