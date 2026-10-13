<?php

namespace App\Livewire;

use App\Support\WfpAccountCodeGenerator;
use App\Support\WfpTemplate;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The /wfp page: the school's Work and Financial Plan, generated on demand
 * from the official template and the account-code master list.
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
        ]);
    }
}
