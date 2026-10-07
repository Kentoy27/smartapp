{{-- ONE ROW'S "MORE ACTIONS" MENU.

     A row that offers several actions collapses them behind one kebab
     trigger: on a table of fifty submissions, three buttons per row is a
     wall of chrome, and the destructive one (Delete) should not sit in the
     open on every line. The items inside are the caller's own buttons, so
     they keep their wire:click, loading state and confirmations — this
     component only owns the trigger and the open/closed state.

     The menu is positioned on the VIEWPORT (fixed) rather than on the row
     (absolute), because tables live in a horizontally scrolling wrapper that
     clips anything hanging below it. Alpine measures the trigger and the
     menu, keeps the menu inside the viewport, and flips it above when the row
     is low on screen.

     A row with a single action should render that action as a plain button
     instead — a menu holding one item only adds a click. --}}
@props(['label' => 'More actions'])

<div
    class="row-menu"
    x-data="{
        open: false,
        above: false,
        top: 0,
        left: 0,
        toggle() { this.open ? this.close() : this.show() },
        show() { this.open = true; this.$nextTick(() => this.place()) },
        close() { this.open = false },
        place() {
            const trigger = this.$refs.trigger.getBoundingClientRect();
            const menu = this.$refs.list.getBoundingClientRect();
            const gutter = 8;
            const gap = 6;

            // Right-aligned with the trigger, then kept inside the viewport.
            this.left = Math.min(
                Math.max(gutter, trigger.right - menu.width),
                window.innerWidth - menu.width - gutter
            );

            const below = trigger.bottom + gap;
            this.above = below + menu.height > window.innerHeight - gutter;

            this.top = this.above ? trigger.top - menu.height - gap : below;
        },
    }"
    @click.outside="close()"
    @keydown.escape.window="close()"
>
    <button
        type="button"
        class="row-menu-trigger"
        x-ref="trigger"
        @click="toggle()"
        :class="open ? 'is-open' : ''"
        aria-haspopup="menu"
        :aria-expanded="String(open)"
        aria-label="{{ $label }}"
        title="{{ $label }}"
    >
        <x-icon name="more-horizontal" :size="18" />
    </button>

    <div
        class="row-menu-list"
        x-ref="list"
        :class="(open ? 'is-open' : '') + (above ? ' is-above' : '')"
        @click="close()"
        :style="'top: ' + top + 'px; left: ' + left + 'px;'"
        role="menu"
        aria-label="{{ $label }}"
    >
        {{ $slot }}
    </div>
</div>
