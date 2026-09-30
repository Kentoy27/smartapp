{{-- LIVE SIDEBAR: rendered by Livewire. Navigation is SPA-style via
     wire:navigate (no full page reloads); wire:current re-evaluates on every
     navigation and toggles the active classes; the users-count badge stays
     fresh via the `users-refreshed` event + its own poll, and the Review
     Opcrf badge updates when a staff member confirms a submission. --}}
<nav class="sidebar-nav" aria-label="Main navigation" wire:poll.2s>
    <div class="nav-section"><span>Main</span></div>

    @foreach ($this->items as $item)
        <a
            href="{{ route($item['route']) }}"
            class="nav-item"
            wire:navigate
            wire:current.exact="active"
            wire:key="sidebar-{{ $item['path'] }}"
        >
            <x-icon name="{{ $item['icon'] }}" class="icon" />

            <span>{{ $item['label'] }}</span>

            @isset($item['badge'])
                <span
                    class="nav-badge"
                    title="{{ $item['path'] === 'opcrf-review'
                        ? $item['badge'].' submissions to review'
                        : $item['badge'].' accounts' }}"
                >{{ $item['badge'] }}</span>
            @endisset
        </a>
    @endforeach
</nav>
