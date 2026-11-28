<?php

namespace App\Livewire;

use App\Models\School;
use App\Models\User;
use App\Models\WfpPlan;
use App\Support\SchoolDivision;
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
     * The plan's division when the caller already knows which plan it is
     * showing. A school may operate more than one division, so school + year
     * alone cannot identify the plan; null falls back to the first plan row.
     */
    #[Locked]
    public ?string $division = null;

    /**
     * Table-cell mode: the index and plan lists render this component inside
     * a narrow column, so it shows the three checkpoints as small pills plus
     * the sign buttons instead of the full card panel.
     */
    public bool $compact = false;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    /** The plan this panel describes; resolved once per request. */
    private ?WfpPlan $signoffPlan = null;

    private bool $signoffPlanResolved = false;

    public function mount(int $schoolId, int $year, ?string $division = null): void
    {
        $this->schoolId = $schoolId;
        $this->year = $year;
        $this->division = $division !== null && SchoolDivision::isValidDivision($division)
            ? $division
            : null;
        $this->viewer();
    }

    /** The plan for this school and year, or null when none exists yet. */
    private function plan(): ?WfpPlan
    {
        if (! $this->signoffPlanResolved) {
            $query = WfpPlan::query()
                ->where('school_id', $this->schoolId)
                ->where('year', $this->year);

            if ($this->division !== null) {
                $query->where('school_division', $this->division);
            }

            $this->signoffPlan = $query->first();
            $this->signoffPlanResolved = true;
        }

        return $this->signoffPlan;
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless(
            $user instanceof User
                && ($user->canManageWfp() || $user->canCheckLineItems() || $user->canApproveWfp()),
            404,
        );

        // Read the sign-offs under the plan's own division, not the viewer's
        // active division: the Superadmin works ELEMENTARY while a plan may
        // belong to JUNIOR_HIGH or SENIOR_HIGH.
        $division = $this->plan()?->schoolDivision() ?? $this->division ?? $user->activeDivision();

        abort_unless($user->canAccessDivision($division), 404);

        if ($user->canManageWfp()) {
            abort_unless((int) $user->school_id === $this->schoolId, 404);
        } elseif (! $user->canApproveWfp()) {
            abort_unless($user->canAccessSchool($this->schoolId), 404);
        }

        $school = School::query()
            ->with('divisionSettings')
            ->findOrFail($this->schoolId);
        abort_unless($school->offersDivision($division), 404);

        return $user;
    }

    public function signCheck(): void
    {
        $user = $this->viewer();
        abort_unless($user->canCheckWfpReport(), 404);

        // Sign-offs are stored under the plan's own division so they are
        // visible to hasBudgetReview() regardless of which division the
        // signer happens to be working in at the moment.
        $division = $this->plan()?->schoolDivision() ?? $this->division ?? $user->activeDivision();

        try {
            WfpReportSignoffs::check($user, $this->schoolId, $this->year, $division);
        } catch (RuntimeException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $submitted = WfpReportSignoffs::submitReadyPlan(
            $this->schoolId,
            $this->year,
            $division,
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

        // Sign-offs are stored under the plan's own division so they are
        // visible to hasBudgetReview() regardless of which division the
        // signer happens to be working in at the moment.
        $division = $this->plan()?->schoolDivision() ?? $this->division ?? $user->activeDivision();

        try {
            WfpReportSignoffs::certify($user, $this->schoolId, $this->year, $division);
        } catch (RuntimeException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $submitted = WfpReportSignoffs::submitReadyPlan(
            $this->schoolId,
            $this->year,
            $division,
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
            $submitted = WfpReportSignoffs::submitReadyPlan(
                $this->schoolId,
                $this->year,
                $plan->schoolDivision(),
            );
        } catch (RuntimeException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        if ($submitted === null) {
            $this->errorMessage = 'This WFP is not ready to submit — both Budget sign-offs must still be recorded and valid.';

            return;
        }

        $this->successMessage = 'Submitted '.$submitted->fiscalYearLabel().' for the Schools Division Superintendent’s final approval.';
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

        // The panel reads the plan's own division (see viewer()): the
        // Superadmin's activeDivision() is always ELEMENTARY, so a plan in
        // JUNIOR_HIGH/SENIOR_HIGH would otherwise show every sign-off Pending.
        $plan = $this->plan();
        $division = $plan?->schoolDivision() ?? $this->division ?? $user->activeDivision();

        $statuses = WfpAllotment::signatoryStatuses($this->schoolId, $this->year, $division);
        $checking = WfpAllotment::checking($this->schoolId, $this->year, $division);
        $certification = WfpAllotment::certification($this->schoolId, $this->year, $division);
        $hasLines = WfpAllotment::lineItems($this->schoolId, $this->year, $division) !== [];
        $hasAllotment = WfpAllotment::allotment($this->schoolId, $this->year, $division) !== null;
        $canSubmit = $user->canCertifyAllotments()
            && $plan !== null
            && $plan->isEditable()
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
