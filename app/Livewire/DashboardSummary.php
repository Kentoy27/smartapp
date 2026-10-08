<?php

namespace App\Livewire;

use App\Models\OpcrfSubmission;
use App\Models\OpcrfTemplate;
use App\Models\User;
use App\Support\OpcrfPartOne;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The staff dashboard's live body.
 *
 * The OPCRF Template card is the only thing on the dashboard that can change
 * while a staff member is looking at it, and it refreshes itself (wire:poll) —
 * it unlocks the moment a reviewer returns their submission, without a reload.
 *
 * Superadmins have nothing here: their dashboard is the greeting, and the
 * District List they used to see now has its own page (Districts & Schools).
 *
 * Static shell (greeting, page furniture) stays on the controller-rendered
 * home view; this component only owns the data-driven card.
 */
class DashboardSummary extends Component
{
    /**
     * The dashboard belongs to whoever is signed in; its analytics are all
     * scoped to that account.
     */
    public function mount(): void
    {
        abort_unless(Auth::check(), 404);
    }
    public function render()
    {
        $user = Auth::user();
        $isSuperAdmin = (bool) $user?->hasAdminAccess();

        return view('livewire.dashboard.summary', [
            'isSuperAdmin' => $isSuperAdmin,
            // The OPCRF Template card locks once the staff member has a
            // confirmed submission; a return-for-revision unlocks it again.
            'opcrfLocked' => $isSuperAdmin ? false : $this->opcrfLocked($user),
            // Part availability by school-year term: outside the final term
            // the card's download is Part I only (superadmins have no card).
            'opcrfPartOne' => $isSuperAdmin ? null : OpcrfPartOne::summary(),
            // The personalized template this account last generated, and the
            // name it was generated with — the status panel's "✓ Template
            // Generated" state, with a download link back to the same file.
            'opcrfTemplate' => $isSuperAdmin || $user === null ? null : $this->latestTemplate($user),
            // The submission in flight, with every archived version, so a
            // returned form can be revised without Version 1 disappearing.
            'opcrfSubmission' => $isSuperAdmin || $user === null ? null : $this->currentSubmission($user),
        ]);
    }

    /**
     * Has the signed-in staff member completed the OPCRF cycle — a
     * confirmed submission? While that is true, the dashboard's OPCRF
     * Template card is locked: no download, no upload. A submission that
     * was returned for revision unlocks the card again — the staff member
     * may need the template to prepare their revised upload (the Revise &
     * Resubmit flow on the Opcrf page is the primary path).
     */
    protected function opcrfLocked(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return OpcrfSubmission::where('user_id', $user->id)
            ->where('status', '!=', OpcrfSubmission::STATUS_RETURNED)
            ->exists();
    }

    /**
     * The account's most recently generated personalized template, with the
     * name it was generated for. Null until the staff member downloads one.
     */
    protected function latestTemplate(User $user): ?OpcrfTemplate
    {
        return OpcrfTemplate::latestFor($user);
    }

    /**
     * The submission the dashboard's status panel describes: the newest one,
     * with its archived versions (Version 1 is the original upload) so a
     * revision never hides what came before it.
     */
    protected function currentSubmission(User $user): ?OpcrfSubmission
    {
        return OpcrfSubmission::where('user_id', $user->id)
            ->with(['versions', 'template', 'latestReview'])
            ->latest('id')
            ->first();
    }
}
