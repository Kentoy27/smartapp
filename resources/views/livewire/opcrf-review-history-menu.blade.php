<div
    class="opcrf-history-menu"
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    wire:poll.visible.60s
>
    @php($historySubmissions = $this->historySubmissions)

    <button
        type="button"
        class="opcrf-history-menu-trigger"
        :class="open ? 'open' : ''"
        @click="open = !open"
        aria-haspopup="true"
        :aria-expanded="String(open)"
        aria-label="OPCRF remarks history"
        title="OPCRF remarks history"
    >
        <x-icon name="clock" :size="19" />
        <span>History</span>
        @if ($historySubmissions->isNotEmpty())
            <span class="opcrf-history-menu-badge" aria-hidden="true">
                {{ $historySubmissions->count() }}{{ $historySubmissions->count() >= 10 ? '+' : '' }}
            </span>
        @endif
    </button>

    <div class="opcrf-history-menu-panel" :class="open ? 'open' : ''" role="dialog" aria-label="OPCRF remarks history">
        <div class="opcrf-history-menu-head">
            <span class="opcrf-history-menu-title">OPCRF remarks history</span>
            <a href="{{ route('opcrf.review') }}" wire:navigate @click="open = false">Review queue</a>
        </div>

        <div class="opcrf-history-menu-list">
            @forelse ($historySubmissions as $submission)
                <details class="opcrf-history-menu-submission" wire:key="opcrf-history-menu-{{ $submission->id }}">
                    <summary>
                        <span class="opcrf-history-menu-school">
                            {{ $submission->user?->username ?? 'School Head' }}
                            <small>{{ $submission->partLabel() }} · {{ $submission->review_period }}</small>
                        </span>
                        <span class="opcrf-history-menu-events">
                            {{ $submission->reviews->count() }} {{ \Illuminate\Support\Str::plural('event', $submission->reviews->count()) }}
                        </span>
                    </summary>

                    <div class="opcrf-history-menu-timeline">
                        @foreach ($submission->reviews as $entry)
                            <article class="opcrf-history-menu-entry" wire:key="opcrf-history-menu-entry-{{ $entry->id }}">
                                <div class="opcrf-history-menu-entry-head">
                                    <strong>{{ $entry->actionLabel() }}</strong>
                                    <time>{{ $entry->reviewed_at?->format('M j, Y g:i A') }}</time>
                                </div>
                                <div class="opcrf-history-menu-entry-meta">
                                    @if ($entry->action === \App\Models\OpcrfReview::ACTION_RESUBMIT)
                                        Resubmitted by {{ $entry->reviewer?->username ?? 'the School Head' }}
                                    @else
                                        Reviewed by {{ $entry->reviewer?->username ?? '—' }}
                                        @if ($entry->to !== null)
                                            · Forwarded to {{ $entry->to->username }}
                                        @endif
                                    @endif
                                </div>
                                @if ($entry->remarks !== null && trim($entry->remarks) !== '')
                                    <p class="opcrf-history-menu-remarks">{{ $entry->remarks }}</p>
                                @endif
                                @php($objectiveRemarks = $entry->objective_remarks ?? [])
                                @if ($loop->last && $entry->action === \App\Models\OpcrfReview::ACTION_RETURN && $objectiveRemarks === [])
                                    @php($objectiveRemarks = $submission->reviewer_remarks ?? [])
                                @endif
                                @foreach ($objectiveRemarks as $partIndex => $objectives)
                                    @foreach ($objectives as $objectiveIndex => $remark)
                                        @if (is_string($remark) && trim($remark) !== '')
                                            <p class="opcrf-history-menu-remarks">
                                                Part {{ (int) $partIndex + 1 }} — Objective {{ (int) $objectiveIndex + 1 }}: {{ $remark }}
                                            </p>
                                        @endif
                                    @endforeach
                                @endforeach
                            </article>
                        @endforeach
                    </div>
                </details>
            @empty
                <p class="opcrf-history-menu-empty">No OPCRF review history is available for your submissions yet.</p>
            @endforelse
        </div>
    </div>
</div>
