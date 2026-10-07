<?php

namespace App\Livewire;

use App\Models\OpcrfSubmission;
use App\Support\OpcrfTemplatePersonalizer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * "Your OPCRF submissions" table.
 *
 * The table lists this staff member's submissions (newest first,
 * paginated) with their review status; an approved submission with an
 * archived workbook offers the download of the official copy. A submission
 * returned for revision raises an "Action Required" banner (the reviewer's
 * remarks front and center) with a Revise & Resubmit flow: the staff member
 * uploads their corrected workbook, and the same submission goes back into
 * review as 'resubmitted' — routed to the superadmin who returned it, with
 * the previous workbook archived as a version and the full history intact.
 */
class OpcrfMovs extends Component
{
    use WithFileUploads;
    use WithPagination;

    /**
     * Submissions and their MOV evidence belong to the signed-in account,
     * and a revision here rewrites an existing submission — so a guest is
     * refused here rather than part way through a write.
     */
    public function mount(): void
    {
        abort_unless(Auth::check(), 404);
    }

    /**
     * Submissions per table page (the dashboard shows this table, and so
     * does the /opcrf page — full history instead of a “latest 5” cut).
     */
    public int $perPage = 10;

    /**
     * The returned submission being revised (the Revise & Resubmit modal).
     */
    public ?int $resubmittingId = null;

    /**
     * The staff member's revised workbook (.xlsx), replacing the returned
     * one. Optional: resubmitting without a new file keeps the current
     * workbook and just flips the status back into review.
     */
    public $revisionFile;

    /**
     * Set after a successful resubmission so the view pops a SweetAlert
     * (same pattern as the upload flow's $successMessage).
     */
    public ?string $successMessage = null;

    /**
     * Match the app's own table look instead of Tailwind's.
     * (Must be public: Livewire's pagination feature resolves it via __call.)
     */
    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    /**
     * Fired by the upload window once a submission is confirmed, and by the
     * superadmin's review modal once a submission is approved, forwarded or
     * deleted — the table below shows the new row / the approval badge /
     * drops the deleted one right away (no reload).
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
     * The signed-in user's submissions for the table (fresh on every
     * render).
     */
    #[Computed]
    public function submissions()
    {
        return OpcrfSubmission::where('user_id', Auth::id())
            ->with('latestReview.reviewer')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    /**
     * The returned submission under revision — owner-only, and only while
     * it actually waits for revision.
     */
    #[Computed]
    public function resubmitSubmission(): ?OpcrfSubmission
    {
        if ($this->resubmittingId === null) {
            return null;
        }

        return OpcrfSubmission::where('user_id', Auth::id())
            ->whereKey($this->resubmittingId)
            ->first();
    }

    /**
     * Clicked "Revise & Resubmit" on a returned row: open the revise modal
     * showing the reviewer's remarks.
     */
    public function openRevise(int $submissionId): void
    {
        $submission = OpcrfSubmission::where('user_id', Auth::id())->find($submissionId);

        // Only the owner, and only a submission that actually waits for
        // their revision — anything else is not revisable.
        abort_unless($submission !== null && $submission->status === OpcrfSubmission::STATUS_RETURNED, 404);

        $this->resubmittingId = $submission->id;
        $this->revisionFile = null;
        $this->resetValidation();
    }

    public function closeRevise(): void
    {
        $this->resubmittingId = null;
        $this->revisionFile = null;
        $this->resetValidation();
    }

    /**
     * Confirmed inside the revise modal: the revised workbook (when given)
     * replaces the current one — the previous upload is archived as a
     * version — and the submission returns to review as 'resubmitted',
     * routed to the superadmin who returned it.
     */
    public function confirmResubmit(): void
    {
        $submission = $this->resubmitSubmission();

        abort_unless($submission !== null && $submission->status === OpcrfSubmission::STATUS_RETURNED, 404);

        $this->validate([
            'revisionFile' => ['nullable', 'file', 'max:'.(int) config('opcrf.template.max_kb', 10240), 'extensions:xlsx'],
        ], [
            'revisionFile.extensions' => 'The revised OPCRF must be the .xlsx template file.',
            'revisionFile.max' => 'The file is too large (10 MB maximum).',
        ]);

        $archivedPath = null;
        $originalName = null;

        if ($this->revisionFile instanceof TemporaryUploadedFile) {
            // A revision is an upload like any other. Reading the workbook
            // records what it says about itself; it never refuses. The
            // archive and the previous-version filing below are reached
            // whatever the file contains, so a revision is never held up by
            // anything but its format and size.
            OpcrfTemplatePersonalizer::verify(
                $this->revisionFile->getRealPath(),
                Auth::user()
            );

            $archivedPath = $this->revisionFile->storeAs(
                'opcrf-submissions/'.Auth::id(),
                uniqid().'-'.$this->revisionFile->getClientOriginalName(),
                'local'
            );
            $originalName = $this->revisionFile->getClientOriginalName();
        }

        $submission->resubmit($archivedPath, $originalName);

        $this->closeRevise();

        $this->successMessage = 'Revised OPCRF submitted — it is back with '
            .($submission->returningReviewer()?->username ?? 'the superadmin').' for review.';

        // The reviewer's list and sidebar badge pick the resubmission up
        // without a reload; this table's status badge refreshes too.
        $this->dispatch('opcrf-submission-created');
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
