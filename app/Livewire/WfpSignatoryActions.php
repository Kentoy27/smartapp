<?php

namespace App\Livewire;

use App\Models\School;
use App\Models\User;
use App\Models\WfpPlan;
use App\Support\WfpAllotment;
use App\Support\WfpReportSignoffs;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

class WfpSignatoryActions extends Component
{
    #[Locked]
    public int $schoolId = 0;

    #[Locked]
    public int $year = 0;

    /**
     * Table-cell mode: the index and plan lists render this component inside
     * a narrow column, so it shows the three checkpoints as small pills plus
     * the sign buttons instead of the full card panel.
     */
    public bool $compact = false;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(int $schoolId, int $year): void
    {
        $this->schoolId = $schoolId;
        $this->year = $year;
        $this->viewer();
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless(
            $user instanceof User
                && ($user->canManageWfp() || $user->canCheckLineItems() || $user->canApproveWfp()),
            404,
        );
        abort_unless($user->canAccessDivision($user->activeDivision()), 404);

        if ($user->canManageWfp()) {
            abort_unless((int) $user->school_id === $this->schoolId, 404);
        } elseif (! $user->canApproveWfp()) {
            abort_unless($user->canAccessSchool($this->schoolId), 404);
        }

        $school = School::query()
            ->with('divisionSettings')
            ->findOrFail($this->schoolId);
        abort_unless($school->offersDivision($user->activeDivision()), 404);

        return $user;
    }

    public function signCheck(): void
    {
        $user = $this->viewer();
        abort_unless($user->canCheckWfpReport(), 404);

        try {
            WfpReportSignoffs::check($user, $this->schoolId, $this->year, $user->activeDivision());
        } catch (RuntimeException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $submitted = WfpReportSignoffs::submitReadyPlan(
            $this->schoolId,
            $this->year,
            $user->activeDivision(),
        ) !== null;

        $this->successMessage = $submitted
            ? 'WFP submitted to the Schools Division Superintendent for final approval after the Bookkeeper check.'
            : 'Signed the account-title and code check for CY '.$this->year.'.';
        $this->errorMessage = null;
        $this->dispatch('wfp-signoff-updated', schoolId: $this->schoolId, year: $this->year);
        if ($submitted) {
            $this->dispatch('wfp-plan-submitted');
        }
    }

    public function signCertification(): void
    {
        $user = $this->viewer();
        abort_unless($user->canCertifyAllotments(), 404);

        try {
            WfpReportSignoffs::certify($user, $this->schoolId, $this->year, $user->activeDivision());
        } catch (RuntimeException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $submitted = WfpReportSignoffs::submitReadyPlan(
            $this->schoolId,
            $this->year,
            $user->activeDivision(),
        ) !== null;

        $this->successMessage = $submitted
            ? 'WFP submitted to the Schools Division Superintendent for final approval after the Budget certification.'
            : 'Signed the allotment certification for CY '.$this->year.'.';
        $this->errorMessage = null;
        $this->dispatch('wfp-signoff-updated', schoolId: $this->schoolId, year: $this->year);
        if ($submitted) {
            $this->dispatch('wfp-plan-submitted');
        }
    }

    public function submitForApproval(): void
    {
        $user = $this->viewer();
        abort_unless($user->canCertifyAllotments(), 404);

        $plan = WfpPlan::query()
            ->where('school_id', $this->schoolId)
            ->where('school_division', $user->activeDivision())
            ->where('year', $this->year)
            ->firstOrFail();

        try {
            $plan->submitForApprovalByBudget($user);
        } catch (RuntimeException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $this->successMessage = 'Submitted '.$plan->fiscalYearLabel().' for the Schools Division Superintendent’s final approval.';
        $this->errorMessage = null;
        $this->dispatch('wfp-plan-submitted');
    }

    public function clearMessage(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function render()
    {
        $user = $this->viewer();
        $division = $user->activeDivision();
        $statuses = WfpAllotment::signatoryStatuses($this->schoolId, $this->year, $division);
        $checking = WfpAllotment::checking($this->schoolId, $this->year, $division);
        $certification = WfpAllotment::certification($this->schoolId, $this->year, $division);
        $hasLines = WfpAllotment::lineItems($this->schoolId, $this->year, $division) !== [];
        $hasAllotment = WfpAllotment::allotment($this->schoolId, $this->year, $division) !== null;
        $plan = WfpPlan::query()
            ->where('school_id', $this->schoolId)
            ->where('school_division', $division)
            ->where('year', $this->year)
            ->first();
        $canSubmit = $user->canCertifyAllotments()
            && $plan !== null
            && $plan->isEditable()
            && $plan->items()->exists()
            && ! $plan->isOutOfDate()
            && $plan->hasBudgetReview();

        return view('livewire.wfp.signatory-actions', [
            'statuses' => $statuses,
            'canCheck' => $user->canCheckWfpReport() && $hasLines,
            'canCertify' => $user->canCertifyAllotments() && $hasAllotment && $certification === null,
            'canSubmit' => $canSubmit,
            'hasSubmittablePlan' => $user->canCertifyAllotments() && $plan?->isEditable() === true,
            'checking' => $checking,
            'certification' => $certification,
        ]);
    }
}
