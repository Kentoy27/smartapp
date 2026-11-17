<div class="profile-modal-host">
    @if ($showModal)
        @php($currentSessionId = session()->getId())
        <div
            class="modal-backdrop is-open"
            wire:click.self="closeModal"
            x-on:keydown.escape.window="$wire.closeModal()"
            role="presentation"
        >
            <section
                class="modal device-sessions-modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="deviceSessionsTitle"
            >
                <div class="modal-head">
                    <div>
                        <h2 id="deviceSessionsTitle">Devices logged in</h2>
                        <p class="device-sessions-modal__count">
                            {{ number_format($this->activeSessions()->count()) }}
                            {{ $this->activeSessions()->count() === 1 ? 'active session' : 'active sessions' }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="modal-close"
                        wire:click="closeModal"
                        aria-label="Close device sessions"
                        title="Close"
                    >×</button>
                </div>

                <div class="device-sessions-modal__body">
                    <p class="device-sessions-modal__note">
                        You can stay signed in on as many devices as you need. Each browser session is listed separately.
                    </p>

                    @forelse ($this->activeSessions() as $deviceSession)
                        <article class="device-session-row" wire:key="device-session-{{ $deviceSession->id }}">
                            <span class="device-session-row__icon" aria-hidden="true">
                                <x-icon name="phone" :size="19" />
                            </span>
                            <div class="device-session-row__details">
                                <strong>{{ $deviceSession->device_type }} · {{ $deviceSession->browser }}</strong>
                                <span>{{ $deviceSession->platform }}</span>
                                <span>Last active {{ $deviceSession->last_seen_at->diffForHumans() }}</span>
                            </div>
                            @if ($deviceSession->isCurrentSession($currentSessionId))
                                <span class="device-session-row__current">This device</span>
                            @endif
                        </article>
                    @empty
                        <p class="device-sessions-modal__empty">No active device sessions were found.</p>
                    @endforelse
                </div>
            </section>
        </div>
    @endif
</div>
