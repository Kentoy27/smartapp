<?php

namespace App\Livewire;

use App\Models\OpcrfMov;
use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * "Review Opcrf" — the superadmin's window into every staff submission.
 *
 * Lists all OPCRF submissions (newest first) with the submitting staff
 * member, and opens a review modal per row: the submitted OPCR details, a
 * download of the full submitted workbook, and the attached MOVs as a
 * view-only record (each MOV can be downloaded; nothing can be uploaded,
 * edited, or deleted from here).
 *
 * The modal's one write action is the review outcome: the superadmin may
 * attach the corrected/filled OPCRF while approving — that workbook
 * replaces the archived submission file and becomes the official approved
 * copy the staff member can then download. Nothing else about a
 * submission can be changed or removed from here.
 *
 * The page is live: it listens for `opcrf-submission-created` (fired the
 * moment a staff member confirms their OPCR) and also polls every 15s, so
 * the superadmin sees new submissions appear without a reload.
 */
#[Title('Review Opcrf — SmartApp')]
class OpcrfReview extends Component
{
    use WithFileUploads;
    use WithPagination;

    /**
     * Review modal state: which submission is being reviewed.
     */
    public bool $showReview = false;

    public ?int $reviewId = null;

    /**
     * The corrected/filled OPCRF workbook the superadmin may attach while
     * approving the submission under review (optional). Livewire
     * temp-uploads it; on approval it is moved into permanent storage and
     * becomes the official copy.
     */
    public $reviewFile;

    /**
     * Success toast after an approval (clearSuccess pattern).
     */
    public ?string $successMessage = null;

    /**
     * Delete-confirmation modal state.
     */
    public bool $showDeleteModal = false;

    public ?int $deleteId = null;

    public string $deleteLabel = '';

    /**
     * The superadmin the submission under review is being handed to (the
     * forward picker's selection).
     */
    public $forward_to = '';

    public function mount(): void
    {
        // Superadmin-only component: the table lists every staff member's
        // submissions, so regular users must never mount it.
        abort_unless(Auth::user()?->is_superadmin, 404);
    }

    /**
     * The submission under review, recomputed on demand.
     */
    #[Computed]
    public function reviewSubmission(): ?OpcrfSubmission
    {
        if (! Auth::user()?->is_superadmin || $this->reviewId === null) {
            return null;
        }

        return OpcrfSubmission::with(['approvedBy', 'reviewer'])
            ->visibleTo(Auth::user())
            ->find($this->reviewId);
    }

    /**
     * Submissions per table page.
     */
    public int $perPage = 10;

    /**
     * Match the app's own table look.
     * (Must be public: Livewire's pagination feature resolves it via __call.)
     */
    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    /**
     * Every submission routed to the signed-in superadmin (plus unassigned
     * ones), newest first (fresh on each render, so new submissions appear
     * without a reload). A submission sent to another superadmin is simply
     * not in the query.
     */
    #[Computed]
    public function submissions()
    {
        return OpcrfSubmission::query()
            ->visibleTo(Auth::user())
            ->with(['user', 'movs', 'approvedBy', 'reviewer'])
            ->withCount('movs')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    /**
     * Fired by the staff upload window the moment a submission is
     * confirmed — the table re-renders live with the new row.
     */
    #[On('opcrf-submission-created')]
    public function refreshSubmissions(): void
    {
        unset($this->submissions);
    }

    /**
     * Open the review modal for a submission (row button). The modal shows
     * the recorded summary, the MOVs, and the approval step — the full
     * submitted workbook itself is reviewed by downloading it.
     */
    public function openReview(int $submissionId): void
    {
        abort_if(! Auth::user()?->is_superadmin, 404);

        // Routing is the authorization boundary: a submission sent to another
        // superadmin must not open, not even by a crafted call.
        abort_unless(
            OpcrfSubmission::visibleTo(Auth::user())->whereKey($submissionId)->exists(),
            404
        );

        $this->reviewId = $submissionId;
        $this->reviewFile = null;
        $this->forward_to = '';
        $this->resetValidation();
        $this->showReview = true;
    }

    public function closeReview(): void
    {
        $this->showReview = false;
        $this->reviewId = null;
        $this->reviewFile = null;
        $this->forward_to = '';
        $this->resetValidation();
    }

    /**
     * The superadmin's review outcome. The updated workbook is mandatory:
     * `reviewFile` (`.xlsx`, ≤10 MB) must be attached, it is archived, and it
     * becomes the official approved copy the staff member downloads.
     */
    public function approveSubmission(): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

        $submission = $this->reviewSubmission();

        if ($submission === null) {
            abort(403);
        }

        $this->validate([
            'reviewFile' => ['required', 'file', 'max:10240', 'extensions:xlsx'],
        ], [
            'reviewFile.required' => 'Attach the updated OPCRF workbook to approve — the approved copy is the file you upload here.',
            'reviewFile.file' => 'Choose a valid .xlsx workbook.',
            'reviewFile.max' => 'The approved copy must be 10 MB or smaller.',
            'reviewFile.extensions' => 'The approved copy must be an .xlsx workbook.',
        ]);

        // Approval always ships a document: the uploaded workbook *is* the
        // official copy, replacing whatever was archived at submission time.
        $originalName = $this->reviewFile->getClientOriginalName();
        $storedPath = $this->reviewFile->storeAs(
            'opcrf-submissions/'.$submission->user_id,
            uniqid().'-'.$originalName,
            'local'
        );

        $submission->approve(Auth::user(), $storedPath, $originalName);

        $this->showReview = false;
        $this->reviewId = null;
        $this->reviewFile = null;
        $this->resetValidation();

        // Recomputed on the next render.
        unset($this->submissions, $this->reviewSubmission, $this->reviewMovs);

        $this->successMessage = 'OPCR approved — the updated workbook is now the official copy.';

        // The owning staff member's submissions table shows the approval and
        // its download action without a reload (both the dashboard's and the
        // /opcrf page's instance); the sidebar badge drops in step.
        $this->dispatch('opcrf-submission-approved')->to(OpcrfMovs::class);
        $this->dispatch('opcrf-submission-approved');
    }

    /**
     * Superadmin accounts the submission under review can be handed to: every
     * superadmin except the current recipient.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function forwardRecipients(): Collection
    {
        $current = $this->reviewSubmission();

        if ($current === null) {
            return collect();
        }

        return User::query()
            ->where('is_superadmin', true)
            ->orderBy('username')
            ->get(['id', 'username', 'name'])
            ->reject(fn (User $user): bool => $current->reviewer_id !== null
                && $user->id === $current->reviewer_id)
            ->values();
    }

    /**
     * Hand the submission under review to another superadmin. They become
     * the sole reviewer: this account loses the row (routing), and they
     * review, update and approve it themselves.
     */
    public function forwardSubmission(): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

        $submission = $this->reviewSubmission();

        if ($submission === null) {
            abort(403);
        }

        $this->validate([
            'forward_to' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('is_superadmin', true)
                ),
            ],
        ], [
            'forward_to.required' => 'Choose the superadmin to hand this submission to.',
            'forward_to.exists' => 'That account cannot receive submissions.',
        ]);

        $recipient = User::findOrFail((int) $this->forward_to);
        $wasApproved = $submission->isApproved();

        if (! $submission->forwardTo($recipient)) {
            $this->addError('forward_to', 'That superadmin already reviews this submission.');

            return;
        }

        $this->showReview = false;
        $this->reviewId = null;
        $this->reviewFile = null;
        $this->forward_to = '';
        $this->resetValidation();

        unset(
            $this->submissions,
            $this->reviewSubmission,
            $this->reviewMovs,
            $this->forwardRecipients
        );

        $this->successMessage = 'Submission handed to '.$recipient->username.' for review'
            .($wasApproved ? ' (the earlier approval was cleared).' : '.');

        // This account's sidebar badge drops the row; the new recipient's
        // badge picks it up on its next 2s poll.
        $this->dispatch('opcrf-submission-forwarded')->to(OpcrfMovs::class);
        $this->dispatch('opcrf-submission-forwarded');
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    /**
     * Ask to delete a submission (row action). Opens the confirmation modal
     * — nothing is removed until confirmDelete().
     */
    public function openDelete(int $submissionId): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

        // Same routing boundary as the review modal: a submission sent to
        // another superadmin is not even deletable.
        $submission = OpcrfSubmission::visibleTo(Auth::user())->find($submissionId);
        abort_unless($submission !== null, 404);

        $this->deleteId = $submission->id;
        $this->deleteLabel = ($submission->employee_name !== '' ? $submission->employee_name : 'this submission')
            .' — '.$submission->review_period;
        $this->showDeleteModal = true;
    }

    public function closeDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deleteId = null;
        $this->deleteLabel = '';
    }

    /**
     * Delete the submission for good, with everything hanging off it: the
     * attached MOV rows (each removes its own stored file) and the archived
     * workbook (the model's deleting hook). Routed-only, superadmin-only.
     */
    public function confirmDelete(): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);

        $submission = $this->deleteId === null
            ? null
            : OpcrfSubmission::visibleTo(Auth::user())->find($this->deleteId);

        abort_unless($submission !== null, 404);

        $movs = $submission->movs()->count();

        // The model's deleting hook takes the MOV rows (and their files) and
        // the archived workbook with the row.
        $submission->delete();

        $this->closeDelete();
        $this->showReview = false;
        $this->reviewId = null;
        $this->resetValidation();

        unset($this->submissions, $this->reviewSubmission, $this->reviewMovs);

        $this->successMessage = 'OPCR submission deleted'
            .($movs > 0 ? ' with '.$movs.' MOV file'.($movs === 1 ? '' : 's') : '')
            .'.';

        // The staff member's own table loses the row without a reload; the
        // sidebar badge follows.
        $this->dispatch('opcrf-submission-deleted')->to(OpcrfMovs::class);
        $this->dispatch('opcrf-submission-deleted');
    }

    /**
     * MOVs attached to the submission under review (the view-only record).
     *
     * @return \Illuminate\Support\Collection<int, OpcrfMov>
     */
    #[Computed]
    public function reviewMovs()
    {
        $submission = $this->reviewSubmission();

        return $submission
            ? $submission->movs()->get()
            : collect();
    }

    public function render()
    {
        return view('livewire.opcrf.review');
    }
}

