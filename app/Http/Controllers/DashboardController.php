<?php

namespace App\Http\Controllers;

use App\Models\OpcrfSubmission;
use App\Models\User;
use App\Support\OpcrfPartOne;
use App\Support\WfpTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    /**
     * Show the dashboard for the signed-in user.
     *
     * The system-wide figures and the user list are only loaded for
     * superadmins; everyone else just sees their own account summary.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = (bool) $user->is_superadmin;

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
        ]);
    }

    /**
     * Has the signed-in staff member completed the whole OPCRF cycle — a
     * confirmed submission AND at least one MOV attached to it? While that
     * is true, the dashboard's OPCRF Template card is locked: no download,
     * no upload.
     */
    protected function opcrfLocked(User $user): bool
    {
        return OpcrfSubmission::where('user_id', $user->id)
            ->whereHas('movs')
            ->exists();
    }

    /**
     * System-wide totals shown as stat cards on a superadmin dashboard.
     *
     * @return array<int, array{label: string, value: int, sub: string}>
     */
    protected function systemStats(): array
    {
        $total = User::count();
        $superAdmins = User::where('is_superadmin', true)->count();

        return [
            ['label' => 'Total Users', 'value' => $total, 'sub' => 'Registered accounts'],
            ['label' => 'Super Admins', 'value' => $superAdmins, 'sub' => 'Full system access'],
            ['label' => 'Standard Users', 'value' => $total - $superAdmins, 'sub' => 'Limited access'],
            ['label' => 'Google Linked', 'value' => User::whereNotNull('google_id')->count(), 'sub' => 'Google sign-in accounts'],
        ];
    }

    /**
     * The newest accounts, for the "Recent Users" table.
     */
    protected function recentUsers(): Collection
    {
        return User::query()
            ->latest('created_at')
            ->take(5)
            ->get(['id', 'username', 'email', 'is_superadmin', 'created_at']);
    }
}
