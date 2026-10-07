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
        $isSuperAdmin = (bool) $user?->is_superadmin;

        return view('home', [
            'user' => $user,
            // The WFP Template card is for the School Head (SH) role only —
            // no card for superadmins, SDS viewers, or anyone else.
            'wfpTemplate' => (! $isSuperAdmin && $user?->canAccessWfp())
                ? ['name' => WfpTemplate::fileName(), 'description' => WfpTemplate::description()]
                : null,
        ]);
    }
}
