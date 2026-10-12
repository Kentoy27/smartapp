{{-- ONE LEVEL of a sidebar navigation tree, rendered by itself so the tree
     can be any depth: the MOV checklist's Part → Category → MOV.

     Three kinds of node, and the difference between them is the whole point
     of the tree:

       · a node with children is a DISCLOSURE — a button that opens and closes
         what is under it, and nothing else: one target per row, so the row
         under the pointer is always the row that answers. Part and category
         are both this, at two depths.
       · a node without children is a LINK — the MOV itself, which opens that
         exact requirement on the checklist page. Nothing is uploaded from
         here: the sidebar navigates, the page uploads.
       · a node with a `url` but no `status` is also a LINK, for a page that
         has no tree of its own one level down — the WFP group's two pages.
         It is marked by the route it is on rather than by a status dot.

     Each level steps in, so Part → category → MOV is legible at a glance, and
     the count on a Part or a category is over exactly the MOVs listed beneath
     it. --}}
@foreach ($nodes as $node)
    @if (($node['children'] ?? []) !== [])
        {{-- A BRANCH: opens what is under it. The row is one target —
             the section itself is reached from the checklist page's own
             rail, not from a second arrow beside the disclosure. --}}
        <div class="nav-tree nav-tree--{{ $depth }}" wire:key="nav-tree-{{ $depth }}-{{ $node['key'] }}">
            <div class="nav-tree-row">
                <button
                    type="button"
                    class="nav-tree-toggle"
                    wire:click="toggleMovNode('{{ $node['key'] }}')"
                    aria-expanded="{{ $node['open'] ? 'true' : 'false' }}"
                    title="{{ $node['open'] ? 'Hide' : 'Show' }} {{ $node['title'] }}"
                >
                    {{-- ▶ collapsed, ▼ expanded: the chevron turns, it does
                         not swap, so the row never changes width. --}}
                    <span class="nav-tree-chevron {{ $node['open'] ? 'is-open' : '' }}" aria-hidden="true">
                        <x-icon name="chevron-down" :size="14" />
                    </span>

                    <span class="nav-tree-label">{{ $node['label'] }}</span>

                    @isset($node['meta'])
                        <span class="nav-tree-count">{{ $node['meta'] }}</span>
                    @endisset
                </button>
            </div>

            @if ($node['open'])
                @include('livewire.sidebar._tree', [
                    'nodes' => $node['children'],
                    'depth' => $depth + 1,
                    'movFocus' => $movFocus,
                ])
            @endif
        </div>
    @elseif (isset($node['status']))
        {{-- A MOV: the deepest level, and a link. The dot is this person's own
             upload state, read from their own rows — and the tooltip carries
             the full trail and title, because a MOV number says nothing on
             its own this deep, and the titles are long. --}}
        @php($onScreen = $movFocus !== null && $movFocus === $node['section'])

        <a
            class="nav-mov{{ $onScreen ? ' is-active' : '' }}"
            href="{{ $node['url'] }}"
            wire:navigate
            title="{{ $node['title'] }} — {{ $node['status_label'] }}"
            aria-label="{{ $node['label'] }} — {{ $node['title'] }} — {{ $node['status_label'] }}"
            aria-current="{{ $onScreen ? 'true' : 'false' }}"
            wire:key="nav-mov-{{ $node['key'] }}"
        >
            <span class="nav-mov-dot nav-mov-dot--{{ $node['status'] }}" aria-hidden="true"></span>

            <span class="nav-mov-body">
                <span class="nav-mov-label">
                    {{ $node['label'] }}

                    @unless ($node['required'])
                        <span class="nav-mov-optional">optional</span>
                    @endunless
                </span>

                {{-- Titles run long. Two lines, then an ellipsis, and the
                     full text in the tooltip above. --}}
                <span class="nav-mov-title">{{ $node['hint'] }}</span>
            </span>
        </a>
    @elseif (isset($node['url']))
        {{-- A PLAIN LINK: a page one level down with no tree under it — the
             WFP group's two pages (the plan, and the Monthly Expenditure
             Matrix it is generated from). It is marked by the route the page
             is on, exactly like the item rows above it, and carries a small
             bullet rather than a status dot: nothing is uploaded from here. --}}
        <a
            class="nav-group-link{{ request()->routeIs($node['route']) ? ' is-active' : '' }}"
            href="{{ $node['url'] }}"
            wire:navigate
            title="{{ $node['title'] ?? $node['label'] }}"
            aria-current="{{ request()->routeIs($node['route']) ? 'true' : 'false' }}"
            wire:key="nav-group-link-{{ $node['key'] }}"
        >
            <span class="nav-group-link-bullet" aria-hidden="true"></span>
            <span class="nav-group-link-label">{{ $node['label'] }}</span>
        </a>
    @endif
@endforeach
