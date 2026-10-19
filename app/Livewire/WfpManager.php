<?php

namespace App\Livewire;

use App\Support\WfpAccountCodeGenerator;
use App\Support\WfpPlanExporter;
use App\Support\WfpTemplate;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The /wfp page: the school's Work and Financial Plan, generated on demand.
 *
 * The workbook comes from the school's Monthly Expenditure Matrices once they
 * carry lines; until then it is the official template pre-filled from the
 * account-code master list. The card says which of the two this school gets
 * (see WfpPlanExporter).
 *
 * Only the School Head (SH) role may reach this component — enforced in
 * mount(). The work itself is a one-click generate-and-download, handled by
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
            'accountCount' => WfpAccountCodeGenerator::activeCount(),
            'planYear' => WfpPlanExporter::dataYearFor(Auth::user()),
        ]);
    }
}
