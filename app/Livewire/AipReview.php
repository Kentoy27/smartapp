<?php

namespace App\Livewire;

use App\Models\AipPlan;
use App\Models\AipPlanVersion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Review AIP" — reviewers' window into submitted Annual Implementation Plans.
 *
 * Lists submitted plans (newest first) with the school and the year, and
 * opens a review modal per row: the plan's projects and activities, the
 * archived submission versions, and the review trail. The modal's write
 * actions are approve and return-for-revision with remarks; approvals move
 * the plan through the configured chain before the final approval.
 *
 * The page polls every 15s, so a new submission appears without a reload.
 * The archived versions are never overwritten by a review.
 */
#[Title('Review AIP — SmartApp')]
class AipReview extends Component
{
    use WithPagination;

    public bool $showReview = false;

    public ?int $reviewId = null;

    /** The reviewer's remarks, recorded with the next action they take. */
    public string $reviewRemarks = '';

    public ?string $successMessage = null;

    public int $perPage = 10;

    /** Which archived version the modal is showing, or null for the current one. */
    public ?int $previewVersionId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canReviewAip(), 404);
    }

    /* ------------------------------------------------------------ computed */

    /** Submitted plans, including incomplete ones, visible to this reviewer. */
    #[Computed]
    public function plans()
    {
        return AipPlan::query()
            ->visibleTo(Auth::user())
            ->whereNotNull('submitted_at')
            ->with(['school', 'approvedBy', 'reviewer', 'assignedTo', 'latestReview.reviewer'])
            ->withCount('projects', 'activities')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function reviewPlan(): ?AipPlan
    {
        if (! Auth::user()?->canReviewAip() || $this->reviewId === null) {
            return null;
        }

        return AipPlan::query()
            ->visibleTo(Auth::user())
            ->with(['school.district', 'approvedBy', 'reviewer', 'assignedTo'])
            ->find($this->reviewId);
    }

    /** The review trail, oldest first. */
    #[Computed]
    public function reviewHistory(): Collection
    {
        $plan = $this->reviewPlan;

        return $plan ? $plan->reviews()->with('reviewer')->get() : collect();
    }

    /** The archived submission versions, oldest (Version 1) first. */
    #[Computed]
    public function reviewVersions(): Collection
    {
        $plan = $this->reviewPlan;

        return $plan ? $plan->versions()->with('submittedBy')->get() : collect();
    }

    /**
     * What the modal renders: the selected archived version's snapshot, or —
     * when no version is selected — the plan's contents as they stand now.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function reviewContents(): ?array
    {
        $plan = $this->reviewPlan;

        if ($plan === null) {
            return null;
        }

        if ($this->previewVersionId !== null) {
            $version = $plan->versions()->find($this->previewVersionId);

            if ($version !== null) {
                return $version->snapshot;
            }
        }

        return $plan->snapshot();
    }

    /** The version the modal is showing, when one is selected. */
    #[Computed]
    public function previewVersion(): ?AipPlanVersion
    {
        $plan = $this->reviewPlan;

        if ($plan === null || $this->previewVersionId === null) {
            return null;
        }

        return $plan->versions()->find($this->previewVersionId);
    }

    /* -------------------------------------------------------------- modals */

    public function openReview(int $planId): void
    {
        abort_unless(Auth::user()?->canReviewAip(), 404);

        // Routing is the authorization boundary: a plan routed to another
        // reviewer must not open, not even by a crafted call.
        $plan = AipPlan::query()->visibleTo(Auth::user())->find($planId);
        abort_unless($plan !== null, 404);

        $this->reviewId = $plan->id;
        $this->reviewRemarks = '';
        $this->previewVersionId = null;
        $this->resetValidation();
        $this->showReview = true;

        unset($this->reviewPlan, $this->reviewHistory, $this->reviewVersions, $this->reviewContents, $this->previewVersion);
    }

    public function closeReview(): void
    {
        $this->showReview = false;
        $this->reviewId = null;
        $this->reviewRemarks = '';
        $this->previewVersionId = null;
        $this->resetValidation();

        unset($this->reviewPlan, $this->reviewHistory, $this->reviewVersions, $this->reviewContents, $this->previewVersion);
    }

    /** Show one archived version's contents, or null for the current plan. */
    public function showVersion(?int $versionId): void
    {
        $this->previewVersionId = $versionId;

        unset($this->reviewContents, $this->previewVersion);
    }

    /* ------------------------------------------------------------ workflow */

    public function approve(): void
    {
        abort_unless(Auth::user()?->canReviewAip(), 404);

        $plan = $this->reviewPlan;

        if ($plan === null) {
            abort(403);
        }

        // Only the reviewer currently holding the plan may act.
        abort_unless($plan->canBeReviewedBy(Auth::user()), 403);

        $remarks = trim($this->reviewRemarks) !== '' ? trim($this->reviewRemarks) : null;

        $nextReviewer = $plan->markApproved(Auth::user(), $remarks);

        $this->afterDecision(
            $nextReviewer
                ? 'AIP approved and forwarded to '.$nextReviewer->name.'.'
                : 'AIP fully approved.',
        );

        $this->dispatch('aip-plan-reviewed');
    }

    public function returnForRevision(): void
    {
        abort_unless(Auth::user()?->canReviewAip(), 404);

        $plan = $this->reviewPlan;

        if ($plan === null) {
            abort(403);
        }

        abort_unless($plan->canBeReviewedBy(Auth::user()), 403);

        // Remarks are what makes a return actionable, so they are required.
        $this->validate([
            'reviewRemarks' => ['required', 'string', 'max:2000'],
        ], [
            'reviewRemarks.required' => 'Tell the school what to revise before returning the plan.',
            'reviewRemarks.max' => 'Keep the remarks under 2000 characters.',
        ], [
            'reviewRemarks' => 'remarks',
        ]);

        $plan->returnForRevision(Auth::user(), trim($this->reviewRemarks));

        $this->afterDecision('AIP returned for revision.');

        $this->dispatch('aip-plan-reviewed');
    }

    private function afterDecision(string $message): void
    {
        $this->showReview = false;
        $this->reviewId = null;
        $this->reviewRemarks = '';
        $this->previewVersionId = null;
        $this->resetValidation();

        unset(
            $this->plans,
            $this->reviewPlan,
            $this->reviewHistory,
            $this->reviewVersions,
            $this->reviewContents,
            $this->previewVersion,
        );

        $this->successMessage = $message;
    }

    /** Delete a plan only when an authorized reviewer explicitly requests it. */
    public function deletePlan(int $planId): void
    {
        $user = Auth::user();
        abort_unless($user?->canReviewAip(), 404);

        $plan = AipPlan::query()->visibleTo($user)->findOrFail($planId);
        abort_unless($plan->canBeDeletedBy($user), 403);

        $plan->delete();

        unset($this->plans);
        $this->successMessage = 'AIP deleted.';

        if ($this->reviewId === $planId) {
            $this->closeReview();
        }
    }

    #[On('aip-plan-submitted')]
    public function refreshPlans(): void
    {
        unset($this->plans);
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    public function render()
    {
        return view('livewire.aip.review');
    }
}
