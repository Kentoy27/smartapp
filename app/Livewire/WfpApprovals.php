<?php

namespace App\Livewire;

use App\Models\School;
use App\Models\User;
use App\Models\WfpPlan;
use App\Support\SchoolDivision;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The Superadmin's WFP final-approval queue.
 *
 * Only plans that have left the School Head's desk appear here: pending
 * submissions, approved plans, and returned ones. Each row opens the same
 * read-only detail the School Head sees, plus Budget's two sign-offs and the
 * full approval history. Approving is final and read-only; returning requires
 * remarks and sends the plan back through Budget re-certification.
 */
#[Title('WFP Final Approvals — SmartApp')]
class WfpApprovals extends Component
{
    use WithPagination;

    public string $search = '';

    public string $schoolFilter = '';

    public string $divisionFilter = '';

    public string $yearFilter = '';

    public string $statusFilter = WfpPlan::STATUS_PENDING_APPROVAL;

    public ?int $reviewId = null;

    public bool $showReview = false;

    public string $remarks = '';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canApproveWfp(), 404);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'schoolFilter', 'divisionFilter', 'yearFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    /* ------------------------------------------------------------ computed */

    #[Computed]
    public function plans(): LengthAwarePaginator
    {
        $query = WfpPlan::query()
            ->with(['school', 'submittedBy', 'finalApproval.decidedBy', 'latestReturn.decidedBy'])
            ->inApprovalQueue();

        if (in_array($this->statusFilter, [
            WfpPlan::STATUS_PENDING_APPROVAL,
            WfpPlan::STATUS_FINALIZED,
            WfpPlan::STATUS_RETURNED,
        ], true)) {
            $query->where('status', $this->statusFilter);
        }

        $schoolId = (int) $this->schoolFilter;

        if ($schoolId > 0) {
            $query->where('school_id', $schoolId);
        }

        if (in_array($this->divisionFilter, SchoolDivision::all(), true)) {
            $query->where('school_division', $this->divisionFilter);
        }

        $year = (int) $this->yearFilter;

        if ($year > 0) {
            $query->where('year', $year);
        }

        $search = trim($this->search);

        if ($search !== '') {
            $query->whereHas('school', fn ($school) => $school->where('name', 'like', '%'.$search.'%'));
        }

        return $query
            ->orderByRaw(
                'CASE WHEN wfp_plans.status = ? THEN 0 ELSE 1 END',
                [WfpPlan::STATUS_PENDING_APPROVAL],
            )
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(10);
    }

    /** @return array<int, int> */
    #[Computed]
    public function years(): array
    {
        return WfpPlan::query()
            ->inApprovalQueue()
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(static fn ($year): int => (int) $year)
            ->all();
    }

    /** @return Collection<int, School> */
    #[Computed]
    public function schools(): Collection
    {
        return School::query()
            ->whereIn('id', WfpPlan::query()->inApprovalQueue()->select('school_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return [
            WfpPlan::STATUS_PENDING_APPROVAL => 'Pending Approval',
            WfpPlan::STATUS_FINALIZED => 'Approved',
            WfpPlan::STATUS_RETURNED => 'Returned for Revision',
            'all' => 'All decisions',
        ];
    }

    #[Computed]
    public function reviewPlan(): ?WfpPlan
    {
        if ($this->reviewId === null) {
            return null;
        }

        return WfpPlan::query()
            ->with([
                'school',
                'submittedBy',
                'finalApproval.decidedBy',
                'latestReturn.decidedBy',
                'approvals.decidedBy',
                'items.sourceMatrix',
            ])
            ->find($this->reviewId);
    }

    /** @return array<int, array<string, mixed>> */
    #[Computed]
    public function reviewGroups(): array
    {
        return $this->reviewPlan?->groupedItems() ?? [];
    }

    #[Computed]
    public function reviewBudgetCertification(): ?array
    {
        return $this->reviewPlan?->budgetCertification();
    }

    #[Computed]
    public function reviewBudgetChecking(): ?array
    {
        return $this->reviewPlan?->budgetChecking();
    }

    #[Computed]
    public function reviewOutOfDate(): bool
    {
        return $this->reviewPlan?->isOutOfDate() ?? false;
    }

    #[Computed]
    public function reviewHasBudgetReview(): bool
    {
        return $this->reviewPlan?->hasBudgetReview() ?? false;
    }

    /**
     * Whether the current detail may be approved. A stale plan, or one whose
     * Budget sign-offs were cleared after submission, can only be returned.
     */
    #[Computed]
    public function canApproveReview(): bool
    {
        return $this->reviewPlan?->isPendingApproval() === true
            && ! $this->reviewOutOfDate
            && $this->reviewHasBudgetReview;
    }

    #[Computed]
    public function canDecideReview(): bool
    {
        return $this->reviewPlan?->isPendingApproval() === true;
    }

    /* -------------------------------------------------------------- modals */

    public function openReview(int $planId): void
    {
        abort_unless(Auth::user()?->canApproveWfp(), 404);

        $plan = WfpPlan::query()->inApprovalQueue()->find($planId);
        abort_unless($plan !== null, 404);

        $this->reviewId = (int) $plan->id;
        $this->showReview = true;
        $this->remarks = '';
        $this->errorMessage = null;
        $this->resetValidation();

        $this->forgetReview();
    }

    public function closeReview(): void
    {
        $this->showReview = false;
        $this->reviewId = null;
        $this->remarks = '';
        $this->errorMessage = null;
        $this->resetValidation();

        $this->forgetReview();
    }

    /** Drop the modal's cached computed properties after state changes. */
    private function forgetReview(): void
    {
        unset(
            $this->reviewPlan,
            $this->reviewGroups,
            $this->reviewBudgetCertification,
            $this->reviewBudgetChecking,
            $this->reviewOutOfDate,
            $this->reviewHasBudgetReview,
            $this->canApproveReview,
            $this->canDecideReview,
        );
    }

    /* ------------------------------------------------------------ workflow */

    public function approve(): void
    {
        $user = $this->reviewer();
        $plan = $this->reviewPlan;
        abort_if($plan === null, 403);

        $remarks = trim($this->remarks);

        try {
            $plan->markApproved($user, $remarks !== '' ? $remarks : null);
        } catch (RuntimeException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->afterDecision('WFP approved and signed off.');
    }

    public function returnForRevision(): void
    {
        $user = $this->reviewer();
        $plan = $this->reviewPlan;
        abort_if($plan === null, 403);

        $this->validate(
            ['remarks' => ['required', 'string', 'max:2000']],
            [
                'remarks.required' => 'Tell the School Head what to revise before returning the WFP.',
                'remarks.max' => 'Keep the remarks under 2000 characters.',
            ],
            ['remarks' => 'remarks'],
        );

        try {
            $plan->returnForRevision($user, trim($this->remarks));
        } catch (RuntimeException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->afterDecision('WFP returned for revision.');
    }

    private function reviewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveWfp(), 404);

        return $user;
    }

    private function afterDecision(string $message): void
    {
        $this->showReview = false;
        $this->reviewId = null;
        $this->remarks = '';
        $this->errorMessage = null;
        $this->resetValidation();

        unset($this->plans, $this->years, $this->schools);
        $this->forgetReview();

        $this->successMessage = $message;
        $this->dispatch('wfp-plan-reviewed');
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    /** A submitted/returned plan should appear without a reload. */
    #[On('wfp-plan-submitted')]
    public function refreshPlans(): void
    {
        unset($this->plans, $this->years, $this->schools);
    }

    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    public function render()
    {
        return view('livewire.wfp.approvals');
    }
}
