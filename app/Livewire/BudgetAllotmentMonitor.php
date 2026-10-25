<?php

namespace App\Livewire;

use App\Support\WfpAllotment;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Budget dashboard's allotment monitor: the current year's allotments,
 * consumption and certification count, with the way into the page where each
 * school's amount is set and certified.
 *
 * It reads the same WfpAllotment::overview() the School Allotments page uses,
 * so the dashboard and the page can never disagree about the year's totals.
 * Scope is the Budget role and the administrative side that oversees it.
 */
class BudgetAllotmentMonitor extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->canManageAllotments(), 404);
    }

    /**
     * @return array{schools: int, allotted_schools: int, certified_schools: int, allotted_total: float, consumed_total: float, remaining_total: float}
     */
    #[Computed]
    public function overview(): array
    {
        return WfpAllotment::overview((int) now()->year);
    }

    public function render()
    {
        return view('livewire.budget.allotment-monitor', [
            'year' => (int) now()->year,
            'overview' => $this->overview,
        ]);
    }
}
