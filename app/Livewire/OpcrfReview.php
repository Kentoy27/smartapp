<?php

namespace App\Livewire;

use App\Models\OpcrfMov;
use App\Models\OpcrfSubmission;
use App\Models\OpcrfSubmissionVersion;
use App\Models\User;
use App\Notifications\OpcrfDecided;
use App\Support\OpcrfSubmissionWorkbook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Review Opcrf" — the superadmin's window into every staff submission.
 *
 * Lists all OPCRF submissions (newest first) with the submitting staff
 * member, and opens a review modal per row: the submitted OPCR details
 * (the complete workbook, read in-app), per-objective remarks, and the
 * attached MOVs as a view-only record.
 *
 * The modal's write actions are the review-and-routing workflow: the
 * superadmin adds remarks and either marks the submission
 * compliant/approved — which auto-routes it onward to the workflow's
 * next superadmin (SY by config), no manual forwarding step — or returns
 * it to the staff member for revision. The staff member's original
 * uploaded workbook is never replaced by any review action, and the
 * original reviewer's records keep showing a submission after it routes
 * onward (status "Forwarded to Superadmin …").
 *
 * Every action lands in the submission's review history (reviewer,
 * action, remarks, when, from → to), which the modal renders.
 *
 * The page is live: it listens for `opcrf-submission-created` (fired the
 * moment a staff member confirms their OPCR) and also polls every 15s, so
 * the superadmin sees new submissions appear without a reload.
 */
#[Title('Review Opcrf — SmartApp')]
class OpcrfReview extends Component
{
    use WithPagination;

    /**
     * Review modal state: which submission is being reviewed.
     */
    public bool $showReview = false;

    public ?int $reviewId = null;

    /**
     * The reviewer's remarks, recorded with the next action they take
     * (compliance, forward, or return-for-revision).
     */
    public string $reviewRemarks = '';

    /**
     * Superadmin notes keyed by workbook part and numbered objective row.
     *
     * @var array<int, array<int, string>>
     */
    public array $objectiveRemarks = [];

    /**
     * Success toast after a review action (clearSuccess pattern).
     */
    public ?string $successMessage = null;

    /**
     * Delete-confirmation modal state.
     */
    public bool $showDeleteModal = false;

    public ?int $deleteId = null;

    public string $deleteLabel = '';

    public function mount(): void
    {
        // Superadmin-only component: the table lists every staff member's
        // submissions, so regular users must never mount it.
        abort_unless(Auth::user()?->hasAdminAccess(), 404);
    }

    /**
     * The submission under review, recomputed on demand.
     */
    #[Computed]
    public function reviewSubmission(): ?OpcrfSubmission
    {
        if (! Auth::user()?->hasAdminAccess() || $this->reviewId === null) {
            return null;
        }

        return OpcrfSubmission::with(['approvedBy', 'reviewer', 'assignedTo'])
            ->visibleTo(Auth::user())
            ->find($this->reviewId);
    }

    /**
     * The archived (stamped when available) workbook rendered as the complete
     * Excel-style sheet —
     * the same full layout the staff member saw before submitting (title
     * banner, header + evaluator blocks, every part's objectives table with
     * timelines, performance measures, accomplishments and per-criteria
     * ratings, part totals, overall average, signer block).
     *
     * Null when there is no archived file or it is not a readable .xlsx
     * workbook — the modal then falls back to the recorded summary and the
     * Download action.
     */
    #[Computed]
    public function reviewSheet(): ?array
    {
        $submission = $this->reviewSubmission();

        return $submission === null ? null : OpcrfSubmissionWorkbook::sheet($submission);
    }

    /**
     * The overall (QET) average from the archived workbook, feeding the
     * sheet partial's AVERAGE row — the same number the submission flow
     * recorded as self_rating, recomputed live from the file.
     */
    #[Computed]
    public function reviewSheetRating(): string
    {
        $submission = $this->reviewSubmission();

        if ($submission === null || $submission->self_rating === null) {
            return '';
        }

        return (string) $submission->self_rating;
    }

    /**
     * Every tab of the archived (stamped when available) workbook beyond PART I — PART II's
     * competency sections, PART III's rating summary and agreement block,
     * PART IV's improvement plans, plus any non-standard tabs as raw cell
     * grids — so the review modal shows the whole file. Null when there is
     * no readable workbook (the modal then shows the recorded summary).
     */
    #[Computed]
    public function reviewWorkbook(): ?array
    {
        $submission = $this->reviewSubmission();

        if ($submission === null || ! $submission->hasFile() || $this->reviewSheet === null) {
            return null;
        }

        return OpcrfSubmissionWorkbook::workbook($submission);
    }

    /**
     * Digital signatures from reviewers who have signed this workbook.
     *
     * @return array<string, array{url: string, name: string}>
     */
    #[Computed]
    public function reviewSignatures(): array
    {
        return $this->reviewSubmission()?->reviewerSignatures() ?? [];
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
            ->with(['user', 'movs', 'approvedBy', 'reviewer', 'assignedTo', 'latestReview.reviewer'])
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
     * the submitted workbook, the MOVs, per-objective remarks, and the
     * approval step.
     */
    public function openReview(int $submissionId): void
    {
        abort_if(! Auth::user()?->hasAdminAccess(), 404);

        // Routing is the authorization boundary: a submission sent to another
        // superadmin must not open, not even by a crafted call.
        abort_unless(
            OpcrfSubmission::visibleTo(Auth::user())->whereKey($submissionId)->exists(),
            404
        );

        $this->reviewId = $submissionId;
        $this->reviewRemarks = '';
        $submission = OpcrfSubmission::visibleTo(Auth::user())->findOrFail($submissionId);
        $this->objectiveRemarks = $submission->reviewer_remarks ?? [];
        $this->resetValidation();
        $this->showReview = true;

        unset($this->reviewSheet, $this->reviewSheetRating, $this->reviewWorkbook, $this->reviewHistory);
    }

    public function closeReview(): void
    {
        $this->showReview = false;
        $this->reviewId = null;
        $this->reviewRemarks = '';
        $this->objectiveRemarks = [];
        $this->resetValidation();

        unset($this->reviewSheet, $this->reviewSheetRating, $this->reviewWorkbook, $this->reviewHistory);
    }

    /**
     * The review trail of the submission under review, oldest first —
     * rendered as the modal's history table (Reviewer / Action / Remarks /
     * When / From → To).
     *
     * @return Collection<int, \App\Models\OpcrfReview>
     */
    #[Computed]
    public function reviewHistory()
    {
        $submission = $this->reviewSubmission();

        return $submission
            ? $submission->reviews()->with(['reviewer', 'from', 'to'])->get()
            : collect();
    }

    /**
     * The archived workbook versions of the submission under review,
     * oldest first (Version 1 = the original upload).
     *
     * @return Collection<int, OpcrfSubmissionVersion>
     */
    #[Computed]
    public function reviewVersions()
    {
        $submission = $this->reviewSubmission();

        return $submission
            ? $submission->versions()->orderBy('version_number')->get()
            : collect();
    }

    /**
     * Approve: the submitted OPCRF is signed off as-is — the
     * original uploaded workbook is never replaced — and the same
     * submission auto-routes onward to the workflow's next superadmin (SY
     * by config): one row, one ID, complete history. Eve's records keep
     * showing it ("Forwarded to Superadmin SY"); SY's queue picks it up
     * ("Pending Review").
     */
    public function approveSubmission(): void
    {
        abort_unless(Auth::user()?->hasAdminAccess(), 404);

        $submission = $this->reviewSubmission();

        if ($submission === null) {
            abort(403);
        }

        // Only the superadmin currently holding the review step may act —
        // after an onward route the original reviewer keeps the record
        // visible, but read-only.
        abort_unless($submission->isAssignedTo(Auth::user()), 403);

        $submission->forceFill([
            'reviewer_remarks' => $this->validatedObjectiveRemarks(),
        ])->save();

        $remarks = trim($this->reviewRemarks) !== '' ? trim($this->reviewRemarks) : null;

        // Route onward to the workflow's next superadmin. If there is no next
        // reviewer, the current reviewer's approval ends the chain.
        $routeTo = $this->resolveNextReviewer($submission);

        $signatureStamped = $submission->stampReviewerSignature(Auth::user(), 'RATER');
        $submission->markCompliant(Auth::user(), $remarks, $routeTo);

        // The staff member's bell carries the approval and reviewer's remarks.
        $submission->user?->notify(new OpcrfDecided(
            $submission,
            Auth::user(),
            $remarks,
            $routeTo !== null,
        ));

        $this->showReview = false;
        $this->reviewId = null;
        $this->reviewRemarks = '';
        $this->objectiveRemarks = [];
        $this->resetValidation();

        // Recomputed on the next render.
        unset($this->submissions, $this->reviewSubmission, $this->reviewMovs, $this->reviewSheet, $this->reviewSheetRating, $this->reviewWorkbook, $this->reviewHistory);

        $this->successMessage = $routeTo !== null
            ? 'OPCR approved — routed to '.$routeTo->username.' for the next review step.'
            : 'OPCR approved.';
        if (! $signatureStamped) {
            $this->successMessage .= ' Your digital signature could not be added; check your profile signature and the workbook signer fields.';
        }

        // The owning staff member's submissions table shows the new status
        // without a reload; the sidebar badges follow on both sides.
        $this->dispatch('opcrf-submission-approved')->to(OpcrfMovs::class);
        $this->dispatch('opcrf-submission-approved');

        if ($routeTo !== null) {
            $this->dispatch('opcrf-submission-forwarded');
        }
    }

    /**
     * Return for Revision: back to the staff member with the reviewer's
     * remarks; the status shows it in their own table. Remarks are what
     * makes this actionable, so they are required here.
     */
    public function returnSubmission(): void
    {
        abort_unless(Auth::user()?->hasAdminAccess(), 404);

        $submission = $this->reviewSubmission();

        if ($submission === null) {
            abort(403);
        }

        // Same act-guard as compliance: the current holder decides; the
        // original reviewer of a forwarded submission is read-only.
        abort_unless($submission->isAssignedTo(Auth::user()), 403);

        $this->validate([
            'reviewRemarks' => ['required', 'string', 'max:2000'],
        ], [
            'reviewRemarks.required' => 'Tell the staff member what to revise before returning the submission.',
            'reviewRemarks.max' => 'Keep the remarks under 2000 characters.',
        ], [
            'reviewRemarks' => 'remarks',
        ]);

        $submission->forceFill([
            'reviewer_remarks' => $this->validatedObjectiveRemarks(),
        ])->save();

        $submission->returnForRevision(Auth::user(), trim($this->reviewRemarks));

        $this->showReview = false;
        $this->reviewId = null;
        $this->reviewRemarks = '';
        $this->objectiveRemarks = [];
        $this->resetValidation();

        unset($this->submissions, $this->reviewSubmission, $this->reviewMovs, $this->reviewSheet, $this->reviewSheetRating, $this->reviewWorkbook, $this->reviewHistory);

        $this->successMessage = 'OPCR returned for revision.';

        $this->dispatch('opcrf-submission-approved')->to(OpcrfMovs::class);
        $this->dispatch('opcrf-submission-approved');
    }

    /**
     * The workflow's next superadmin after a compliance mark: the
     * configured account (config opcrf.next_reviewer — SY in this
     * deployment), excluding whoever currently holds the review step. Null
     * when nobody else exists — the compliance mark then stands alone.
     */
    private function resolveNextReviewer(OpcrfSubmission $submission): ?User
    {
        // opcrf.next_reviewer is the workflow's onward hop after a
        // compliance mark (SY in this deployment); opcrf.review_route is
        // where staff submissions land first (Eve) and must never be
        // confused with it.
        //
        // The configured name is authoritative: the chain runs Eve → SY and
        // ends with SY. When the configured account already holds the review
        // step (SY marking compliant, or the row somehow sitting with them)
        // or doesn't exist, the mark stands alone — never a blind fallback
        // that would bounce the row back and forth.
        $configured = trim((string) config('opcrf.next_reviewer', ''));

        if ($configured === '') {
            return null;
        }

        return User::query()
            ->where(function ($query): void {
                $query->where('is_superadmin', true)->orWhere('role', 'administrator');
            })
            ->where('username', $configured)
            ->where('id', '!=', $submission->assigned_to ?? $submission->reviewer_id)
            ->first();
    }

    /**
     * Validate comments against the objective rows in the archived workbook.
     *
     * @return array<int, array<int, string>>
     */
    private function validatedObjectiveRemarks(): array
    {
        $this->validate([
            'objectiveRemarks' => ['array'],
            'objectiveRemarks.*' => ['array'],
            'objectiveRemarks.*.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $validRows = [];
        foreach ($this->reviewSheet['parts'] ?? [] as $partIndex => $part) {
            foreach ($part['rows'] as $rowIndex => $row) {
                $validRows[$partIndex][$rowIndex] = true;
            }
        }

        $remarks = [];
        foreach ($this->objectiveRemarks as $partIndex => $rows) {
            foreach ($rows as $rowIndex => $comment) {
                abort_unless(isset($validRows[$partIndex][$rowIndex]), 422);

                $comment = trim((string) $comment);
                if ($comment !== '') {
                    $remarks[$partIndex][$rowIndex] = $comment;
                }
            }
        }

        return $remarks;
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
        abort_unless(Auth::user()?->hasAdminAccess(), 404);

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
        abort_unless(Auth::user()?->hasAdminAccess(), 404);

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

        unset($this->submissions, $this->reviewSubmission, $this->reviewMovs, $this->reviewSheet, $this->reviewSheetRating, $this->reviewWorkbook);

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
     * @return Collection<int, OpcrfMov>
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
