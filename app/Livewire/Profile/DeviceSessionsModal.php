<?php

namespace App\Livewire\Profile;

use App\Models\UserDeviceSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class DeviceSessionsModal extends Component
{
    public bool $showModal = false;

    public function mount(): void
    {
        abort_unless(Auth::check(), 404);
    }

    #[On('open-device-sessions-modal')]
    public function openModal(): void
    {
        abort_unless(Auth::check(), 404);

        unset($this->activeSessions);
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    /**
     * Active sessions are scoped to the signed-in account; stale sessions
     * expire according to the application's configured session lifetime.
     *
     * @return Collection<int, UserDeviceSession>
     */
    #[Computed]
    public function activeSessions(): Collection
    {
        abort_unless(Auth::check(), 404);

        return UserDeviceSession::query()
            ->where('user_id', Auth::id())
            ->whereNull('logged_out_at')
            ->where('last_seen_at', '>=', now()->subMinutes((int) config('session.lifetime', 120)))
            ->orderByDesc('last_seen_at')
            ->get();
    }

    public function render()
    {
        return view('livewire.profile.device-sessions-modal');
    }
}
