<?php

namespace App\Http\Controllers;

use App\Support\WfpTemplate;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Show the dashboard for the signed-in user.
     *
     * Dashboard content is role-scoped: staff plan cards are shown only to
     * their intended roles, and the OPCRF status card refreshes without a
     * reload through DashboardSummary.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        return view('home', [
            'user' => $user,
            'isBudgetReviewer' => $user?->isBudgetRole() ?? false,
            'isBookkeeper' => $user?->isBookkeeperRole() ?? false,
            'canUploadAip' => $user?->canAccessAip() ?? false,
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
