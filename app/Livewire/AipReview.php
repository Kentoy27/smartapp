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
 * "Review AIP" — the superadmin's window into every school's Annual
 * Implementation Plan.
 *
 * Lists submitted plans (newest first) with the school and the year, and
 * opens a review modal per row: the plan's projects and activities, the
 * archived submission versions, and the review trail. The modal's write
 * actions are approve and return-for-revision with remarks; approving is the
 * only path that marks a plan approved.
 *
 * The page is live: it listens for `aip-plan-submitted` (fired the moment an
 * owner submits) and also polls every 15s, so a new submission appears
 * without a reload. The archived versions are never overwritten by a review.
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
        // Superadmin-only: the table lists every school's plans, so a regular
        // user must never mount it.
        abort_unless(Auth::user()?->hasAdminAccess(), 404);
    }

    /* ------------------------------------------------------------ computed */

    /**
     * Submitted plans the signed-in reviewer may see — routed to them,
     * assigned to them, or unassigned. Drafts are excluded: they are the
     * school's own working copy until they are submitted.
     */
    #[Computed]
    public function plans()
    {
        return AipPlan::query()
            ->visibleTo(Auth::user())
            ->where('status', '!=', AipPlan::STATUS_DRAFT)
            ->with(['school', 'approvedBy', 'reviewer', 'assignedTo', 'latestReview.reviewer'])
            ->withCount('projects', 'activities')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function reviewPlan(): ?AipPlan
    {
        if (! Auth::user()?->hasAdminAccess() || $this->reviewId === null) {
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
        abort_unless(Auth::user()?->hasAdminAccess(), 404);

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
        abort_unless(Auth::user()?->hasAdminAccess(), 404);

        $plan = $this->reviewPlan;

        if ($plan === null) {
            abort(403);
        }

        // Only the reviewer currently holding the plan may act.
        abort_unless($plan->canBeReviewedBy(Auth::user()), 403);

        $remarks = trim($this->reviewRemarks) !== '' ? trim($this->reviewRemarks) : null;

        $plan->markApproved(Auth::user(), $remarks);

        $this->afterDecision('AIP approved.');

        $this->dispatch('aip-plan-reviewed');
    }

    public function returnForRevision(): void
    {
        abort_unless(Auth::user()?->hasAdminAccess(), 404);

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

    /** Fired when an owner submits a plan — the table re-renders live. */
    #[On('aip-plan-submitted')]
    public function deletePlan(int $planId): void
    {
        abort_unless(Auth::user()?->hasAdminAccess(), 404);

        $plan = AipPlan::query()->visibleTo(Auth::user())->find($planId);
        if ($plan === null) {
            return;
        }

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
