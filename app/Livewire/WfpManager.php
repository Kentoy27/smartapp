<?php

namespace App\Livewire;

use App\Support\WfpTemplate;
use Illuminate\Support\Facades\Auth;
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
 * Only the School Head (SH) may reach this component — enforced in mount()
 * via canAccessWfp(). The work itself is a generate-and-download, handled by
 * the nested WfpGenerateButton.
 */
#[Title('Work and Financial Plan — SmartApp')]
class WfpManager extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);
    }

    public function render()
    {
        return view('livewire.wfp.manager', [
            'fileName' => WfpTemplate::fileName(),
            'templateDescription' => WfpTemplate::description(),
        ]);
    }
}
