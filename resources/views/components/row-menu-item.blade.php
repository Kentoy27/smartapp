{{-- ONE ITEM INSIDE A row "MORE ACTIONS" MENU.

     The caller's own wire:click / wire:target / confirmation is forwarded
     through $attributes, so the action itself is unchanged — only its
     placement moved into the menu. --}}
@props(['danger' => false])

<button
    type="button"
    role="menuitem"
    @class(['row-menu-item', 'row-menu-item--danger' => $danger])
    {{ $attributes }}
>{{ $slot }}</button>
