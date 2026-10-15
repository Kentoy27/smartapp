{{-- The "Digital Signature" dialog, opened from the account menu.

     The body is the real signature component, so the upload flow and the
     confirmed removal are the ones DigitalSignatureTest owns. Only the dialog
     is here: a bare close row (the signature's own heading and status badge
     follow it, so they are not repeated), and the card's frame is turned off
     by .modal--signature so the dialog panel is the frame.

     The wrapper is `display: contents` so this always-present Livewire root
     adds no box to the header's flex row while the dialog is closed. --}}
<div class="profile-modal-host">
    @if ($showModal)
        <div class="modal-backdrop is-open"
             @click="self && $wire.closeModal()"
             x-on:keydown.escape.window="$wire.closeModal()"
             role="presentation">
            <div class="modal modal--signature" role="dialog" aria-modal="true" aria-label="Digital Signature" @click.stop>
                <div class="modal-head modal-head--bare">
                    <button type="button" class="modal-close" wire:click="closeModal" aria-label="Close" title="Close">×</button>
                </div>

                <div class="modal-form">
                    <livewire:profile.digital-signature />

                    {{-- The profile page still exists and is worth keeping: it
                         carries the account summary and can be bookmarked. The
                         dialog is the way to the signature, so the link to the
                         account it belongs to sits here. --}}
                    <p class="modal-footnote">
                        <a href="{{ route('profile.index') }}" wire:navigate>Open the full profile page</a>
                        — your account details, on the page you can bookmark.
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
