<?php

namespace App\Livewire;

use App\Models\OpcrfSubmission;
use App\Models\OpcrfTemplate;
use App\Models\User;
use App\Support\OpcrfAccess;
use App\Support\OpcrfPartOne;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The staff dashboard's live body.
 *
 * The OPCRF Template card is the only thing on the dashboard that can change
 * while a staff member is looking at it, and it refreshes itself (wire:poll) —
 * it unlocks while Part 1's deadline is open and when a reviewer returns the
 * submission, without a reload.
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
            // A submitted OPCRF stays editable through its Part 1 deadline;
            // returned submissions retain their existing revision flow.
            'opcrfLocked' => $isSuperAdmin ? false : $this->opcrfLocked($user),
            // Part availability by school-year term: the full workbook is
            // available during Part 1 or the shared Parts 2–4 term.
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
     * Keep the dashboard card available while the submission is editable:
     * through its template window, unless it reached the final reviewer.
     * Upload authorization is independently enforced by OpcrfUpload.
     */
    protected function opcrfLocked(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $submission = $this->currentSubmission($user);

        return $submission !== null
            && $submission->status !== OpcrfSubmission::STATUS_RETURNED
            && (
                $submission->isLockedForStaffUpdate()
                || ! OpcrfPartOne::fullTemplateAvailable()
            );
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
            ->where('opcrf_part', OpcrfSubmission::PART_ONE)
            ->with(['versions', 'template', 'latestReview'])
            ->latest('id')
            ->first();
    }
}
