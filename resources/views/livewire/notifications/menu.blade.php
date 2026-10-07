{{-- THE TOPBAR NOTIFICATION CENTRE: a bell with an unread badge, and a
     panel of what happened while the user was elsewhere. Alpine owns the
     open/close state (the panel is read-only chrome); marking read goes
     through Livewire. It polls so a submission arriving, or a reviewer's
     decision, lands in the badge without a reload. --}}
<div
    class="notif"
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    wire:poll.15s
>
    <button
        type="button"
        class="notif-trigger"
        :class="open ? 'open' : ''"
        @click="open = !open"
        aria-haspopup="true"
        :aria-expanded="String(open)"
        aria-label="{{ $this->unreadCount() > 0 ? $this->unreadCount().' unread notifications' : 'Notifications' }}"
        title="Notifications"
    >
        <x-icon name="bell" :size="20" />

        @if ($this->unreadCount() > 0)
            <span class="notif-badge" aria-hidden="true">{{ $this->unreadCount() > 9 ? '9+' : $this->unreadCount() }}</span>
        @endif
    </button>

    <div class="notif-panel" :class="open ? 'open' : ''" role="dialog" aria-label="Notifications">
        <div class="notif-head">
            <span class="notif-head-title">Notifications</span>

            @if ($this->unreadCount() > 0)
                <button type="button" class="notif-markall" wire:click="markAllRead" wire:loading.attr="disabled">
                    <x-icon name="check" :size="14" />
                    <span>Mark all read</span>
                </button>
            @endif
        </div>

        <div class="notif-list">
            @forelse ($this->lines() as $line)
                <a
                    href="{{ $line['link'] }}"
                    wire:navigate
                    wire:click="markRead('{{ $line['id'] }}')"
                    class="notif-item {{ $line['unread'] ? 'is-unread' : '' }}"
                    wire:key="notif-{{ $line['id'] }}"
                >
                    <span class="notif-icon notif-icon--{{ $line['tone'] }}" aria-hidden="true">
                        <x-icon :name="$line['icon']" :size="16" />
                    </span>

                    <span class="notif-body">
                        <span class="notif-title">{{ $line['title'] }}</span>
                        <span class="notif-detail">{{ $line['detail'] }}</span>
                        <span class="notif-time">{{ $line['at'] }}</span>
                    </span>
                </a>
            @empty
                <p class="notif-empty">
                    <x-icon name="bell" :size="22" class="notif-empty-icon" />
                    <span>You're all caught up.</span>
                    <span class="notif-empty-sub">Submissions and review decisions will show up here.</span>
                </p>
            @endforelse
        </div>

        @if ($this->olderUnread() > 0)
            <p class="notif-more">
                {{ $this->olderUnread() }} older unread {{ \Illuminate\Support\Str::plural('notification', $this->olderUnread()) }}
            </p>
        @endif
    </div>
</div>
