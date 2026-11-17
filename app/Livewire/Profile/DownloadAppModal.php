<?php

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class DownloadAppModal extends Component
{
    public bool $showModal = false;

    public function mount(): void
    {
        abort_unless(Auth::check(), 404);
    }

    #[On('open-download-app-modal')]
    public function openModal(): void
    {
        abort_unless(Auth::check(), 404);

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    public function render()
    {
        return view('livewire.profile.download-app-modal');
    }
}
