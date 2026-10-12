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
        <p>
            Your school’s monthly expenditure lines — the source of truth your WFP is generated from.
            @if ($this->school) <span class="mem-chip">{{ $this->school->name }}</span> @endif
        </p>
    </div>

    @if ($errorMessage)
        <div class="ac-import-error mem-banner" role="alert">
            {{ $errorMessage }}
            <button type="button" class="users-clear" wire:click="clearError" title="Dismiss">×</button>
        </div>
    @endif

    <div class="card">
        <div class="card-head users-toolbar">
            <label class="users-per-page">
                <span>Year</span>
                <select wire:model.live="year" aria-label="Filter by year">
                    <option value="">All</option>
                    @foreach ($this->years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>

            <label class="users-per-page">
                <span>Status</span>
                <select wire:model.live="status" aria-label="Filter by status">
                    <option value="all">All</option>
                    <option value="draft">Draft</option>
                    <option value="validated">Validated</option>
                    <option value="finalized">Finalized</option>
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
                @if ($this->canCreate())
                    <button type="button" class="users-add-btn" wire:click="openCreate">
                        <x-icon name="plus" :size="16" />
                        <span>New Matrix</span>
                    </button>
                @endif
            </div>
        </div>

        @unless ($this->canCreate())
            <div class="ac-import-error mem-banner mem-banner--notice" role="status">
                Your account has no school assigned, so it cannot own a Monthly Expenditure Matrix.
                Ask an administrator to set your school first.
            </div>
        @endunless

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Period</th>
                        <th scope="col">Year</th>
                        <th scope="col">Lines</th>
                        <th scope="col">Status</th>
                        <th scope="col">Updated</th>
                        <th scope="col" class="users-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($matrices as $matrix)
                        <tr wire:key="mem-{{ $matrix->id }}">
                            <td><span class="cell-strong">{{ $matrix->periodLabel() }}</span></td>
                            <td><span class="ac-code">{{ $matrix->fiscalYearLabel() }}</span></td>
                            <td>{{ $matrix->items_count }}</td>
                            <td>
                                <span class="mem-status mem-status--{{ $matrix->status }}">{{ ucfirst($matrix->status) }}</span>
                            </td>
                            <td>{{ $matrix->updated_at?->diffForHumans() ?? '—' }}</td>
                            <td class="users-actions-col">
                                <x-row-menu :label="'Actions for '.$matrix->periodLabel()">
                                    <x-row-menu-item
                                        wire:click="open({{ $matrix->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="open"
                                        title="Open {{ $matrix->periodLabel() }}"
                                    >
                                        <x-icon name="file-text" :size="15" />
                                        <span>Open</span>
                                    </x-row-menu-item>

                                    <x-row-menu-item
                                        wire:click="export({{ $matrix->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="export"
                                        title="Export {{ $matrix->periodLabel() }} as the printed form (Excel)"
                                    >
                                        <x-icon name="file-spreadsheet" :size="15" />
                                        <span>Export XLSX</span>
                                    </x-row-menu-item>

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
                                </x-row-menu>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="table-empty">
                                @if ($status !== 'all' || $year !== '')
                                    No matrices match the current filters.
                                @else
                                    No matrices yet — create this month’s matrix to begin.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="users-footer">
            <span class="users-live-indicator" wire:loading.delay wire:target="year,status,perPage" title="Updating…">
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

    {{-- CREATE MODAL --}}
    <div class="modal-backdrop {{ $showCreate ? 'is-open' : '' }}"
         @if ($showCreate) @click="self && $wire.closeCreate()" @endif
         role="presentation">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="memCreateTitle" @click.stop>
            <div class="modal-head">
                <h2 id="memCreateTitle">New Monthly Expenditure Matrix</h2>
                <button type="button" class="modal-close" wire:click="closeCreate" aria-label="Close" title="Close">×</button>
            </div>

            <form wire:submit="create" class="modal-form">
                @if ($createError)
                    <div class="ac-import-error">{{ $createError }}</div>
                @endif

                <div class="ac-field-grid">
                    <div class="field">
                        <label for="memYear">Year</label>
                        <input
                            id="memYear"
                            type="number"
                            min="2000"
                            max="2100"
                            wire:model="createYear"
                            @class(['error' => $errors->has('createYear')])
                            required
                        >
                        @error('createYear') <div class="error-text">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="memMonth">Month</label>
                        <select id="memMonth" wire:model="createMonth" @class(['error' => $errors->has('createMonth')]) required>
                            @foreach (config('mem.months', []) as $number => $name)
                                <option value="{{ $number }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('createMonth') <div class="error-text">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-ghost" wire:click="closeCreate">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="create">
                        <span wire:loading.remove wire:target="create">Create &amp; Open</span>
                        <span wire:loading wire:target="create">Creating…</span>
                    </button>
                </div>
            </form>
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
</div>
