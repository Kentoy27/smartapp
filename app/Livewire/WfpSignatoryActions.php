<?php

namespace App\Livewire;

use App\Models\School;
use App\Models\User;
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

        $this->successMessage = 'Signed the account-title and code check for CY '.$this->year.'.';
        $this->errorMessage = null;
        $this->dispatch('wfp-signoff-updated', schoolId: $this->schoolId, year: $this->year);
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

        $this->successMessage = 'Signed the allotment certification for CY '.$this->year.'.';
        $this->errorMessage = null;
        $this->dispatch('wfp-signoff-updated', schoolId: $this->schoolId, year: $this->year);
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

        return view('livewire.wfp.signatory-actions', [
            'statuses' => $statuses,
            'canCheck' => $user->canCheckWfpReport() && $hasLines,
            'canCertify' => $user->canCertifyAllotments() && $hasAllotment && $certification === null,
            'checking' => $checking,
            'certification' => $certification,
        ]);
    }
}
