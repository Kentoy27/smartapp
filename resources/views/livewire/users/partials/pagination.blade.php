{{-- Minimal pagination that matches the app's data-table styling. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" class="users-pagination">
        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="users-page-btn is-disabled" aria-disabled="true" aria-label="Previous page">‹</span>
        @else
            <button type="button" class="users-page-btn" wire:click="previousPage" aria-label="Previous page">‹</button>
        @endif

        {{-- Page numbers --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="users-page-btn is-disabled">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="users-page-btn is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <button type="button" class="users-page-btn" wire:click="gotoPage({{ $page }})">{{ $page }}</button>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <button type="button" class="users-page-btn" wire:click="nextPage" aria-label="Next page">›</button>
        @else
            <span class="users-page-btn is-disabled" aria-disabled="true" aria-label="Next page">›</span>
        @endif
    </nav>
@endif
