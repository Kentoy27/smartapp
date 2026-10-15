<?php

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The account menu's "Digital Signature" dialog.
 *
 * It owns nothing but the dialog shell: the signature itself is the
 * `profile.digital-signature` component, mounted inside the modal only while
 * it is open. That keeps one implementation of the upload flow (pick → preview
 * → save, and the confirmed removal) rather than a second copy that could
 * drift from the one the dialogs and the page's deep link share.
 *
 * Opening is driven from the account menu with the `open-signature-modal`
 * event, so the layout owns the trigger and this component owns the dialog.
 * Mounting the inner component on open is also why the dialog can never show a
 * stale preview: it reads the saved signature fresh every time it appears.
 */
class DigitalSignatureModal extends Component
{
    public bool $showModal = false;

    public function mount(): void
    {
        // Dialog chrome for a signed-in account only.
        abort_unless(Auth::check(), 404);
    }

    #[On('open-signature-modal')]
    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    public function render()
    {
        return view('livewire.profile.digital-signature-modal');
    }
}
