<?php

namespace App\Livewire;

use App\Models\OpcrfMov;
use App\Models\OpcrfSubmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * "Your OPCRF submissions" table + "Upload your MOVs" modal.
 *
 * The table lists this staff member's submissions (newest first,
 * paginated); each row's MOVs button opens the manager modal for that
 * submission. MOVs (Means of
 * Verification) are the evidence documents behind a form — the template's
 * column R (reports, signed forms, ACRs, memoranda…). The modal lists
 * what's already attached, accepts several new files at once (stored on
 * the local disk, never public), and deletes individual files. Every
 * action re-checks ownership server-side.
 */
class OpcrfMovs extends Component
{
    use WithFileUploads;
    use WithPagination;

    /**
     * Modal state: which submission's MOVs are being managed.
     */
    public bool $showModal = false;

    public ?int $submissionId = null;

    public string $submissionLabel = '';

    /**
     * Newly-picked files (multiple). Livewire temp-uploads them; they are
     * moved into permanent storage on save.
     */
    public $newFiles = [];

    /**
     * Info line after saving/removing (popped as a SweetAlert toast).
     */
    public ?string $successMessage = null;

    /**
     * The submission being managed. Null unless the modal is open AND the
     * signed-in user owns it — this is the authorization boundary for
     * every render.
     */
    public function currentSubmission(): ?OpcrfSubmission
    {
        if ($this->submissionId === null) {
            return null;
        }

        $submission = OpcrfSubmission::find($this->submissionId);

        if ($submission === null || ! $submission->canBeManagedBy(Auth::user())) {
            return null;
        }

        return $submission;
    }

    /**
     * Fired by the upload window once a submission is confirmed, and by the
     * superadmin's review modal once a submission is approved or deleted —
     * the table below shows the new row / the approval badge / drops the
     * deleted one right away (no reload).
     */
    #[On('opcrf-submission-created')]
    #[On('opcrf-submission-approved')]
    #[On('opcrf-submission-deleted')]
    #[On('opcrf-submission-forwarded')]
    public function refreshSubmissions(): void
    {
        // Recomputed on render; nothing else to do.
    }

    /**
     * Submissions per table page (the dashboard shows this table, and so
     * does the /opcrf page — full history instead of a “latest 5” cut).
     */
    public int $perPage = 10;

    /**
     * Match the app's own table look instead of Tailwind's.
     * (Must be public: Livewire's pagination feature resolves it via __call.)
     */
    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    /**
     * The signed-in user's submissions for the table (fresh on every
     * render, so MOV counts stay live).
     */
    #[Computed]
    public function submissions()
    {
        return OpcrfSubmission::where('user_id', Auth::id())
            ->withCount('movs')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    /**
     * Files already attached to the open submission.
     *
     * @return Collection<int, OpcrfMov>
     */
    #[Computed]
    public function existingMovs()
    {
        $submission = $this->currentSubmission();

        return $submission
            ? $submission->movs()->get()
            : collect();
    }

    /**
     * Open the modal for a submission (row button). Non-owners get a
     * silently-closed modal — the data simply never renders (belt and
     * braces: currentSubmission() gates the render AND every action).
     */
    public function openMovs(int $submissionId): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);

        $submission = OpcrfSubmission::find($submissionId);

        // Only the owner may open this modal.
        if ($submission === null || ! $submission->canBeManagedBy(Auth::user())) {
            $this->showModal = false;
            $this->submissionId = null;

            return;
        }

        $this->submissionId = $submission->id;
        $this->submissionLabel = $submission->review_period;
        $this->newFiles = [];
        $this->resetValidation();
        $this->showModal = true;
    }

    public function closeMovs(): void
    {
        $this->showModal = false;
        $this->submissionId = null;
        $this->submissionLabel = '';
        $this->newFiles = [];
        $this->resetValidation();
    }

    /**
     * Validate + persist the newly-picked files into permanent storage.
     */
    public function saveMovs(): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);

        $submission = $this->currentSubmission();

        if ($submission === null) {
            abort(403);
        }

        $this->validate([
            'newFiles' => ['required', 'array', 'min:1', 'max:10'],
            'newFiles.*' => [
                'file',
                'max:10240',
                'extensions:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,zip',
            ],
        ], [
            'newFiles.required' => 'Choose at least one MOV file first.',
            'newFiles.max' => 'Attach up to 10 files at a time.',
            'newFiles.*.max' => 'Each file must be 10 MB or smaller.',
            'newFiles.*.extensions' => 'Allowed: PDF, Word, Excel, PowerPoint, images, and ZIP.',
        ]);

        $saved = 0;

        foreach ($this->newFiles as $file) {
            $path = $file->storeAs(
                'opcrf-movs/'.$submission->id,
                uniqid().'-'.$file->getClientOriginalName(),
                'local'
            );

            $submission->movs()->create([
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $path,
                'size_bytes' => $file->getSize(),
            ]);

            $saved++;
        }

        $this->newFiles = [];
        $this->resetValidation();
        unset($this->existingMovs); // recompute on the next render

        $this->successMessage = $saved === 1
            ? '1 MOV file attached.'
            : $saved.' MOV files attached.';
    }

    /**
     * Remove one attached file (row + stored file).
     */
    public function deleteMov(int $movId): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);

        $submission = $this->currentSubmission();

        if ($submission === null) {
            abort(403);
        }

        // Scope to this submission — ids from other users' MOVs do nothing.
        $mov = $submission->movs()->whereKey($movId)->first();

        if ($mov === null) {
            return;
        }

        $name = $mov->original_name;
        $mov->delete(); // model hook removes the stored file
        unset($this->existingMovs);

        $this->successMessage = "Removed {$name}.";
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function render()
    {
        return view('livewire.opcrf.movs');
    }
}
