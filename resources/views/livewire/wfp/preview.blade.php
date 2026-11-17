{{-- THE WFP PREVIEW: the generated Work and Financial Plans this account may
     read, and the plan itself once one is picked.

     The list is the plans in scope (WfpPlan::scopeVisibleTo) — the Bookkeeper
     sees the schools on its assignment list and nobody else's. The preview
     below it is the plan read-only: the School Head's own editor, opened
     without the write controls, so the two can never read differently. --}}
<div class="wfp-preview-component">

    @php($selected = $this->selectedPlan)

    {{-- The page's heading, until a plan is picked: an open plan brings its
         own header, and a page carries one heading. --}}
    @if ($selected === null)
        <div class="page-header">
            <h1>Work and Financial Plan — Preview</h1>
            <p>
                The generated Work and Financial Plans you may read, and the plan itself:
                the lines it is built from, its signatures and its export.
            </p>
        </div>
    @endif

    <section class="card" aria-labelledby="wfp-preview-list-title">
        <div class="card-head">
            <h2 class="card-title" id="wfp-preview-list-title">Generated Work and Financial Plans</h2>
            <p class="mem-toolbar-copy">{{ $this->plans->total() }} plan(s)</p>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">School</th>
                        <th scope="col">Year</th>
                        <th scope="col">Status</th>
                        <th scope="col">Lines</th>
                        <th scope="col">Annual total</th>
                        <th scope="col" class="users-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->plans as $plan)
                        <tr wire:key="wfp-preview-plan-{{ $plan->id }}">
                            <td class="cell-strong">{{ $plan->school?->name ?? '—' }}</td>
                            <td>{{ $plan->fiscalYearLabel() }}</td>
                            <td>
                                <span class="mem-status mem-status--{{ $plan->status }}">{{ $plan->statusLabel() }}</span>
                            </td>
                            <td>{{ $plan->items->count() }}</td>
                            <td class="mem-linetotal">{{ number_format($plan->annualFinancialTotal(), 2) }}</td>
                            <td class="users-actions-col">
                                <button
                                    type="button"
                                    class="users-action"
                                    wire:click="selectPlan({{ $plan->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="selectPlan"
                                    title="Preview {{ $plan->school?->name ?? 'this school' }}’s {{ $plan->fiscalYearLabel() }} WFP"
                                >
                                    <span class="users-action-inner">
                                        <x-icon name="eye" :size="14" />
                                        <span>Preview</span>
                                    </span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="table-empty" colspan="6">
                                No Work and Financial Plan has been generated for your schools yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->plans->hasPages())
            <div class="users-footer">{{ $this->plans->links() }}</div>
        @endif
    </section>

    @if ($selected)
        <div class="mem-actions mem-actions--top">
            <div class="mem-actions__group">
                <button type="button" class="btn-ghost" wire:click="closePreview">
                    <x-icon name="x" :size="16" />
                    <span>Close the preview</span>
                </button>
            </div>
        </div>

        {{-- The plan read-only: the School Head's editor, which re-checks the
             write gate itself — a Bookkeeper sees the plan and its signatures
             and nothing they may change. --}}
        @livewire('wfp-plan-editor', ['plan' => $selected], key('wfp-preview-editor-'.$selected->id))
    @endif
</div>
