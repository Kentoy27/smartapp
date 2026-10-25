<?php

namespace App\Http\Controllers;

use App\Support\WfpTemplate;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Show the dashboard for the signed-in user.
     *
     * The page shell (greeting) is static; everything data-driven — the
     * superadmin's District List, the staff member's OPCRF Template card —
     * lives in the DashboardSummary Livewire component, which refreshes
     * itself so the dashboard stays live without a reload.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        return view('home', [
            'user' => $user,
            'isBudgetReviewer' => $user?->isBudgetRole() ?? false,
            'isBookkeeper' => $user?->isBookkeeperRole() ?? false,
            // The WFP Template card (generate + upload) is for the School
            // Head (SH) role only — no card for superadmins, SDS viewers, the
            // Bookkeeper (who works the division-wide checking pages), or
            // anyone else.
            'wfpTemplate' => $user?->canManageWfp()
                ? ['name' => WfpTemplate::fileName(), 'description' => WfpTemplate::description()]
                : null,
        ]);
    }
}
