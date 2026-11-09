<?php

namespace App\Livewire;

use App\Support\WfpTemplate;
use App\Models\WfpPlan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The /wfp page: the school's Work and Financial Plan, generated on demand.
 *
 * The workbook comes from the school's Monthly Expenditure Matrices once they
 * carry lines; until then it is the official template pre-filled from the
 * account-code master list. The generate button (WfpGenerateButton) says which
 * of the two the school gets, and for which year.
 *
 * The School Head authors here; Budget, Bookkeeper and Superadmin open the
 * scoped read-only sign-off list. The work itself is a generate-and-download,
 * handled by the nested WfpGenerateButton.
 */
#[Title('Work and Financial Plan — SmartApp')]
class WfpManager extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->canViewWfpSignoffs(), 404);
    }

    #[Computed]
    public function plans()
    {
        $user = Auth::user();

        return WfpPlan::query()
            ->with('school')
            ->where('school_division', $user->activeDivision())
            ->when(
                $user->visibleSchoolIds() !== null,
                fn ($query) => $query->whereIn('school_id', $user->visibleSchoolIds()),
            )
            ->orderByDesc('year')
            ->orderBy('school_id')
            ->paginate(15);
    }

    public function render()
    {
        return view('livewire.wfp.manager', [
            'fileName' => WfpTemplate::fileName(),
            'templateDescription' => WfpTemplate::description(),
        ]);
    }
}
