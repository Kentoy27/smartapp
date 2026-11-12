<div class="wfp-manager">

    @if (auth()->user()?->canManageWfp())
        {{-- THE WORKBOOK: the file this page is about, and the two things done
             to it — the completed copy handed back (Upload, with the file it
             belongs to), and a fresh one built from the year's data (Generate,
             below the rule). --}}
        <div class="card wfp-template-card">
            <div class="card-head">
                <div class="card-title">Work and Financial Plan Template</div>
            </div>
            <div class="opcr-template-row">
                <span class="opcr-file-badge" aria-hidden="true">
                    <x-icon name="file-spreadsheet" :size="22" />
                </span>
                <div class="opcr-file-meta">
                    <span class="opcr-file-name">{{ $fileName }}</span>
                    <span class="opcr-file-sub">{{ $templateDescription }}</span>
                </div>

                {{-- Uploading a completed workbook remains the School Head's action. --}}
                <livewire:wfp-upload :key="'wfp-upload-page'" />
            </div>

            {{-- The generate controls' own sign-off panel is off here: the card
                 below carries the chain, and follows the year this select
                 picks through the `wfp-year-changed` event. --}}
            <livewire:wfp-generate-button :key="'wfp-generate-page'" :show-signatories="false" />
        </div>

        {{-- THE SIGN-OFF CHAIN: where this year's WFP stands with the three
             offices that sign it — the Bookkeeper's check, Budget's
             certification, and the Superintendent's approval. --}}
        @if (auth()->user()?->school_id)
            <div class="card wfp-signatory-card">
                @livewire('wfp-signatory-actions', [
                    'schoolId' => auth()->user()->school_id,
                    'year' => $this->signatoryCardYear,
                ], key('wfp-page-signoffs-'.auth()->user()->school_id.'-'.$this->signatoryCardYear))
            </div>
        @endif

        {{-- MY UPLOADS: the School Head's own list of the workbooks they have
             handed back, each with a download. Refreshes on `wfp-uploaded`. --}}
        <livewire:wfp-my-uploads-list :key="'wfp-my-uploads-page'" />
    @else
        <div class="card">
            <div class="card-head">
                <div class="card-title">Work and Financial Plans</div>
            </div>
            <p class="card-text">
                Open a plan to view its signatures and sign only the section
                assigned to your role. Approved plans show the final approver.
            </p>
        </div>

        <section class="card" aria-labelledby="wfp-review-list-title">
            <div class="card-head">
                <h2 class="card-title" id="wfp-review-list-title">Plans in your scope</h2>
                <p class="mem-toolbar-copy">{{ $this->plans->total() }} plan(s)</p>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">School</th>
                            <th scope="col">Year</th>
                            <th scope="col">Status</th>
                            <th scope="col">Sign-offs</th>
                            <th scope="col" class="users-actions-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->plans as $plan)
                            <tr wire:key="wfp-signoff-plan-{{ $plan->id }}">
                                <td>{{ $plan->school?->name ?? '—' }}</td>
                                <td>{{ $plan->fiscalYearLabel() }}</td>
                                <td><span class="mem-status mem-status--{{ $plan->status }}">{{ $plan->statusLabel() }}</span></td>
                                <td>
                                    @livewire('wfp-signatory-actions', [
                                        'schoolId' => $plan->school_id,
                                        'year' => (int) $plan->year,
                                        'compact' => true,
                                    ], key('wfp-plan-signoffs-'.$plan->id))
                                </td>
                                <td class="users-actions-col">
                                    <a class="users-action" href="{{ route('wfp.plans.edit', ['plan' => $plan->id]) }}" wire:navigate>
                                        <span class="users-action-inner">Open plan</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="table-empty" colspan="5">No WFPs are available for your schools yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($this->plans->hasPages())
                <div class="users-footer">{{ $this->plans->links() }}</div>
            @endif
        </section>
    @endif
</div>
