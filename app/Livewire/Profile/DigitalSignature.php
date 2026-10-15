<?php

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The digital signature block: what the account menu's "Digital Signature"
 * dialog hosts on every page. The same component for a user and for a
 * superadmin, because a signature belongs to an account rather than to a role —
 * and the account menu is the one place every role reaches it from.
 *
 * The workflow is a two-step one on purpose: picking a file shows it (name,
 * size and a real preview) and stores nothing, so the choice can still be
 * changed or cancelled; only "Save signature" validates and writes it. The
 * currently saved signature is never touched until the new file has been
 * written successfully, so a failed replacement leaves the old one working.
 *
 * Files land on the private `local` disk and are read back through the
 * authorised `profile.signature` route — never from the public directory.
 */
#[Title('Profile — SmartApp')]
class DigitalSignature extends Component
{
    use WithFileUploads;

    /**
     * The freshly picked image, held in Livewire's temporary storage until the
     * account saves it. Null means "nothing picked yet".
     */
    public $signature;

    /**
     * Success toast after a save or a removal (clearSuccess pattern).
     */
    public ?string $successMessage = null;

    /**
     * Remove-confirmation modal state.
     */
    public bool $showRemoveModal = false;

    public function mount(): void
    {
        // Every signed-in account — user or superadmin — owns one signature.
        abort_unless(Auth::check(), 404);
    }

    /**
     * The account whose signature this is.
     */
    protected function account()
    {
        return Auth::user();
    }

    /**
     * A URL for the picked-but-unsaved image, so the preview is the real file
     * rather than its name. Livewire serves its temporary upload back through
     * a signed preview route; if that is unavailable the page falls back to the
     * name and size it already knows.
     */
    public function temporaryPreviewUrl(): ?string
    {
        if (! $this->signature) {
            return null;
        }

        try {
            return $this->signature->temporaryUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A file was picked: validate it straight away and hold it for the preview.
     * Nothing is written to disk yet — Save does that.
     *
     * A file that fails the check is dropped rather than left picked, so the
     * card never describes a selection that can never be saved; the error still
     * reaches the page, and the browser clears its picker to match.
     */
    public function updatedSignature(): void
    {
        $this->successMessage = null;

        if (! $this->signature) {
            return;
        }

        try {
            $this->validateSignature();
        } catch (ValidationException $e) {
            $this->reset('signature');
            $this->dispatch('signature-rejected');

            throw $e;
        }
    }

    /**
     * Store the picked image as this account's signature.
     *
     * The new file is written before anything is updated, and the previous one
     * is only deleted once the row points at the new file — so a failure here
     * leaves the saved signature exactly as it was.
     */
    public function save(): void
    {
        $this->validateSignature();

        $account = $this->account();
        $originalName = $this->signature->getClientOriginalName();

        try {
            $path = $this->signature->storeAs(
                'signatures/'.$account->id,
                $this->storedFileName($originalName),
                'local'
            );
        } catch (\Throwable) {
            // Nothing has changed yet: the account keeps the signature it had.
            $this->addError('signature', 'The signature could not be saved. Your previous signature is unchanged — please try again.');

            return;
        }

        $previousPath = $account->signature_path;
        $wasAlreadySigned = $account->hasSignature();

        // The first upload sets both dates; a replacement only moves the
        // "last updated" one, so the profile can tell the two apart.
        $account->forceFill([
            'signature_path' => $path,
            'signature_original_name' => $this->displayName($originalName),
            'signature_mime_type' => $this->signature->getMimeType(),
            'signature_size' => $this->signature->getSize(),
            'signature_uploaded_at' => $wasAlreadySigned
                ? $account->signature_uploaded_at
                : now(),
            'signature_updated_at' => now(),
        ])->save();

        // Only now is the replaced file removed. A failure to delete it must
        // not undo a signature that was saved successfully.
        if ($previousPath !== null && $previousPath !== $path) {
            try {
                Storage::disk('local')->delete($previousPath);
            } catch (\Throwable) {
                // Orphaned file only — the signature itself is correct.
            }
        }

        $this->reset('signature');
        $this->resetValidation();

        $this->successMessage = $wasAlreadySigned
            ? 'Digital signature updated.'
            : 'Digital signature saved.';

        // The header avatar/profile summary reflects the new state without a
        // full page reload (same pattern as the other upload components).
        $this->dispatch('signature-updated');
    }

    /**
     * Dropped a picked-but-unsaved file: back to the saved signature.
     */
    public function cancelSelection(): void
    {
        $this->reset('signature');
        $this->resetValidation();
    }

    /**
     * Ask before removing (the row action only opens the confirmation).
     */
    public function openRemoveModal(): void
    {
        $this->resetValidation();
        $this->showRemoveModal = true;
    }

    public function closeRemoveModal(): void
    {
        $this->showRemoveModal = false;
    }

    /**
     * Remove the saved signature: the file and the columns both go.
     */
    public function removeSignature(): void
    {
        $account = $this->account();

        // A picked-but-unsaved file must not survive the removal either.
        $this->reset('signature');
        $this->resetValidation();

        $account->clearSignature();

        $this->showRemoveModal = false;
        $this->successMessage = 'Digital signature removed.';
        $this->dispatch('signature-updated');
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    /**
     * The one rule for an acceptable signature, used both when a file is picked
     * and again when it is saved (a client can never be trusted to have run the
     * first check).
     *
     * Type is checked twice, the way the rest of the system checks uploads:
     * `mimes` against the file's real content and `extensions` against the name
     * the browser sent, so a renamed file passes neither loosely.
     */
    private function validateSignature(): void
    {
        $maxKb = (int) config('profile.signature_max_kb', 5120);
        $mimes = implode(',', $this->allowedExtensions());

        $this->validate([
            'signature' => [
                'required',
                'file',
                'mimes:'.$mimes,
                'extensions:'.$mimes,
                'max:'.$maxKb,
            ],
        ], [
            'signature.required' => 'Choose a signature image first.',
            'signature.mimes' => 'The signature must be a '.config('profile.signature_format_hint', 'PNG, JPG or WEBP').' image.',
            'signature.extensions' => 'The signature must be a '.config('profile.signature_format_hint', 'PNG, JPG or WEBP').' image.',
            'signature.max' => 'The signature image is too large ('.round($maxKb / 1024, 1).' MB maximum).',
        ]);
    }

    /**
     * The accepted extensions, for the rules and the on-page guidance.
     *
     * @return array<int, string>
     */
    private function allowedExtensions(): array
    {
        return (array) config('profile.signature_mimes', ['png', 'jpg', 'jpeg', 'webp']);
    }

    /**
     * The name the file is stored under: generated on the server, so whatever
     * the browser called the file never reaches the disk (path separators, a
     * second extension, a 300-character name). Only the extension is kept, and
     * only when it is one of the accepted ones.
     */
    private function storedFileName(string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! in_array($extension, $this->allowedExtensions(), true)) {
            $extension = $this->allowedExtensions()[0];
        }

        return uniqid().'-signature.'.$extension;
    }

    /**
     * The name shown back to the account: their own file name, stripped of
     * characters a row should not hold and trimmed to a readable length.
     */
    private function displayName(string $originalName): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $originalName) ?? $originalName;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = Str::limit(trim($name), 120, '');

        return $name !== '' ? $name : 'signature';
    }

    public function render()
    {
        return view('livewire.profile.digital-signature', [
            'account' => $this->account(),
            'maxKb' => (int) config('profile.signature_max_kb', 5120),
            'formatHint' => config('profile.signature_format_hint', 'PNG, JPG or WEBP'),
            'pendingPreviewUrl' => $this->temporaryPreviewUrl(),
        ]);
    }
}
