{{-- LIVE SIDEBAR: rendered by Livewire. Navigation is SPA-style via
     wire:navigate (no full page reloads); the active item is marked from the
     route the request is on, and the users-count / Review Opcrf badges stay
     fresh via the `users-refreshed` and `opcrf-submission-*` events.

     An item may carry `children` — its own navigation tree, which may nest
     any number of levels, and is rendered by the _tree partial. The MOV
     checklist is the three-level case: Part → Category → MOV, built from the
     checklist tables rather than written out here, so a Part, a category or a
     MOV that the config gains appears after one sync.

     An item that carries `children` IS ITS OWN DROPDOWN: one row, clicked to
     open or close what is under it. It is not a link with a dropdown button
     beside it — the tree is how you reach that page, so a second control
     next to it only splits one intent across two targets.

     Nothing here polls. A poll re-morphs the whole nav on a timer, which
     replaces the very row under the pointer mid-click and discards the
     click; the counts on this component are already refreshed by events. --}}
<nav class="sidebar-nav" aria-label="Main navigation">
    <div class="nav-section"><span>Main</span></div>

    @foreach ($this->items as $item)
        @if (isset($item['children']))
            <div class="nav-group" wire:key="sidebar-group-{{ $item['path'] }}">
                <button
                    type="button"
                    class="nav-item nav-item--toggle{{ request()->routeIs($item['route']) ? ' active' : '' }}"
                    wire:click="toggleGroup('{{ $item['path'] }}')"
                    aria-expanded="{{ isset($openGroups[$item['path']]) ? 'true' : 'false' }}"
                    aria-controls="sidebar-group-{{ $item['path'] }}"
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

                    {{-- ▶ closed, ▼ open: it turns, it does not swap, so the
                         row never changes width. --}}
                    <span
                        class="nav-item-chevron{{ isset($openGroups[$item['path']]) ? ' is-open' : '' }}"
                        aria-hidden="true"
                    >
                        <x-icon name="chevron-down" :size="15" />
                    </span>
                </button>

                @if (isset($openGroups[$item['path']]))
                    <div
                        class="nav-subnav"
                        id="sidebar-group-{{ $item['path'] }}"
                        role="group"
                        aria-label="{{ $item['label'] }} sections"
                    >
                        {{-- No search box here: the checklist page carries
                             one, and a second box filtering the same rows
                             left the rail showing a navigation the page
                             beside it was not on. --}}
                        @if (($item['panel'] ?? null) === 'mov' && count($item['children']) === 0)
                            {{-- No Part at all: the catalogue is empty,
                                 not collapsed. --}}
                            <p class="nav-mov-note">
                                No MOVs are configured yet — run <code>php artisan mov:sync</code>.
                            </p>
                        @endif

                        @include('livewire.sidebar._tree', [
                            'nodes' => $item['children'],
                            'depth' => 1,
                            'movFocus' => $movFocus,
                        ])
                    </div>
                @endif
            </div>
        @else
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
        @endif
    @endforeach
</nav>