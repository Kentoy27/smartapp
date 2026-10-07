<?php

namespace App\Http\Controllers;

use App\Models\OpcrfSubmission;
use App\Models\User;
use App\Support\OpcrfPartOne;
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
        return view('home', [
            'user' => $user,
            'isSuperAdmin' => $isSuperAdmin,
            'systemStats' => $isSuperAdmin ? $this->systemStats() : [],
            'recentUsers' => $isSuperAdmin ? $this->recentUsers() : new Collection,
            // The OPCRF Template card locks once the staff member's
            // submission carries its MOVs (superadmins have no card).
            'opcrfLocked' => $isSuperAdmin ? false : $this->opcrfLocked($user),
            // Part availability by school-year term: outside the final term
            // the card's download is Part I only (superadmins have no card).
            'opcrfPartOne' => $isSuperAdmin ? null : OpcrfPartOne::summary(),
            // The WFP Template card is for the School Head (SH) role only —
            // no card for superadmins, SDS viewers, or anyone else.
            'wfpTemplate' => (! $isSuperAdmin && $user->canAccessWfp())
                ? ['name' => WfpTemplate::fileName(), 'description' => WfpTemplate::description()]
                : null,
            'user' => $request->user(),
        ]);
    }
}
