{{-- The staff OPCRF parts grid.

     Every state here — open, locked, closed, completed, and the countdown —
     is computed on the SERVER (App\Support\OpcrfAccess) and only rendered
     here. The Access button is emitted for a Part the server says can be
     opened, and the route it points at checks again; the button is a
     convenience, never the control. --}}

<div class="opcrf-parts" wire:poll.60s>

    @if (session('opcrfPartNotice'))
        @php($notice = session('opcrfPartNotice'))
        <div class="opcrf-part-card opcrf-part-card--{{ ($notice['status'] ?? '') === 'scheduled' ? 'scheduled' : 'closed' }}" style="margin-bottom:16px">
            <p class="opcrf-part-message">{{ $notice['message'] }}</p>
            @if (! empty($notice['detail']))
                <p class="opcrf-part-detail">{{ $notice['detail'] }}</p>
            @endif
        </div>
    @endif

    <div class="opcrf-parts-head">
        <h2 class="opcrf-parts-title">OPCRF {{ $year }}</h2>
        <p class="opcrf-parts-sub">
            @if ($openCount > 0)
                {{ $openCount === 1 ? 'One Part is' : $openCount.' Parts are' }} open for you now.
            @else
                No Part is open at the moment — each one below says when it opens.
            @endif
        </p>
    </div>

    <div class="opcrf-parts-grid">
        @foreach ($parts as $part)
            <div class="opcrf-part-card opcrf-part-card--{{ $part['card_tone'] }}" wire:key="{{ $part['is_grouped_term'] ? 'parts-2-4' : 'part-1' }}">

                <div class="opcrf-part-card-head">
                    <span class="opcrf-part-name">{{ $part['is_grouped_term'] ? 'Parts 2–4' : $part['label'] }}</span>
                    <span class="opcrf-part-badge opcrf-part-badge--{{ $part['card_tone'] }}">
                        <span class="opcrf-part-dot" aria-hidden="true"></span>
                        <span>{{ $part['all_group_parts_complete'] ? 'Completed' : $part['badge'] }}</span>
                    </span>
                </div>

                <p class="opcrf-part-message">
                    @if ($part['all_group_parts_complete'])
                        Parts 2–4 are complete — submitted for this cycle.
                    @elseif ($part['is_grouped_term'] && collect($part['group_states'])->contains(fn (array $state): bool => $state['completed']))
                        Your progress in Parts 2–4 is saved. Continue any unfinished Part during the end-of-year term.
                    @elseif ($part['completed'])
                        {{ $part['label'] }} is complete — submitted for this cycle.
                    @elseif ($part['is_grouped_term'])
                        Parts 2, 3, and 4 share the end-of-year term and can be completed independently.
                    @else
                        {{ $part['message'] }}
                    @endif
                </p>

                @if ($part['detail'] !== '')
                    <p class="opcrf-part-detail">{{ $part['detail'] }}</p>
                @endif

                @if ($part['countdown'] !== '')
                    <p class="opcrf-part-countdown">{{ $part['countdown'] }}</p>
                @endif

                @if ($part['is_grouped_term'])
                    <div class="opcrf-parts-group-actions" role="group" aria-label="Access Parts 2 through 4">
                        @foreach ($part['group_states'] as $groupState)
                            @if ($groupState['can_access'])
                                <a href="{{ route('opcrf.part', ['part' => $groupState['part']]) }}" class="opcrf-part-action">
                                    <x-icon name="{{ $groupState['completed'] ? 'check' : 'unlock' }}" :size="15" />
                                    <span>{{ $groupState['completed'] ? 'View' : 'Access' }} Part {{ $groupState['part'] }}</span>
                                    @if ($groupState['completed'])
                                        <span class="opcrf-part-progress-label">Completed</span>
                                    @endif
                                </a>
                            @else
                                <span class="opcrf-part-action opcrf-part-action--locked" aria-disabled="true">
                                    <x-icon name="lock" :size="15" />
                                    <span>Part {{ $groupState['part'] }} locked</span>
                                </span>
                            @endif
                        @endforeach
                    </div>
                @elseif ($part['can_access'])
                    <a href="{{ route('opcrf.part', ['part' => $part['part']]) }}" class="opcrf-part-action">
                        <x-icon name="unlock" :size="15" />
                        <span>Access {{ $part['label'] }}</span>
                    </a>
                @else
                    {{-- A label, not a button: nothing here is focusable or
                         clickable, so there is nothing to trick the page into. --}}
                    <span class="opcrf-part-action opcrf-part-action--locked" aria-disabled="true">
                        <x-icon name="lock" :size="15" />
                        <span>Locked</span>
                    </span>
                @endif
            </div>
        @endforeach
    </div>
</div>