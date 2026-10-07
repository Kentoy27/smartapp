<?php

namespace App\Http\Controllers;

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
            'user' => $request->user(),
        ]);
    }
}
