{{-- THE DISTRICT LIST: every district with its school count. Each card opens
     the management modal straight at that district; the header button opens
     the modal at the full list. Rendered both as the dashboard's summary card
     and as the whole Districts & Schools page, so it polls for itself. --}}
<div class="district-list" wire:poll.15s>
    <div class="card">
        <div class="card-head">
            <div class="card-title">District List</div>
            <button
                type="button"
                class="btn-primary"
                x-data
                @click="window.dispatchEvent(new CustomEvent('open-districts'))"
            >
                <x-icon name="school" :size="15" />
                <span>Manage Districts &amp; Schools</span>
            </button>
        </div>

        <div class="district-cards">
            @forelse ($districts as $district)
                <button
                    type="button"
                    class="district-card"
                    x-data
                    @click="window.dispatchEvent(new CustomEvent('open-districts', { detail: { district: {{ $district->id }} } }))"
                >
                    <span class="district-card-icon" aria-hidden="true">
                        <x-icon name="school" :size="18" />
                    </span>
                    <span class="district-card-name">{{ $district->name }}</span>
                    <span class="district-card-count">{{ $district->schools_count }} {{ Str::plural('School', $district->schools_count) }}</span>
                </button>
            @empty
                <p class="card-text">No districts yet — create the first one with “Manage Districts &amp; Schools”.</p>
            @endforelse
        </div>
    </div>
</div>