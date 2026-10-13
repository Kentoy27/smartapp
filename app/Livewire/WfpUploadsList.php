<?php

namespace App\Livewire;

use App\Models\WfpSubmission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * "WFP Uploads" — the superadmin's dashboard card: every uploaded Work and
 * Financial Plan, one row per school's current workbook, newest first.
 *
 * This is the other half of the WfpUpload flow: a School Head uploads on
 * /wfp, a row lands in wfp_submissions, and this list is where the
 * superadmin sees all of them together — school, uploader, file, size and
 * when it came in — with a download that serves the archived workbook.
 *
 * Superadmin-only (mount() aborts for everyone else), and it polls like the
 * rest of the dashboard's data-driven cards so an upload made a second ago
 * shows up without a reload.
 */
class WfpUploadsList extends Component
{
    public function mount(): void
    {
        // The list spans every school's uploads: numbers and file names that
        // staff must never see, so a non-admin gets a 404 before rendering.
        abort_unless(Auth::user()?->hasAdminAccess(), 404);
    }

    /**
     * Every uploaded WFP, newest first, with the uploader (and their school)
     * loaded for the row.
     *
     * One row per school in practice: WfpUpload replaces the owner's
     * previous submission on a re-upload, so the list never shows two
     * generations of the same school's plan.
     *
     * @return Collection<int, WfpSubmission>
     */
    #[Computed]
    public function submissions(): Collection
    {
        return WfpSubmission::query()
            ->with(['user.school'])
            ->latest('uploaded_at')
            ->latest('id')
            ->get();
    }

    /**
     * How many schools have uploaded — the card's one glanceable number.
     */
    #[Computed]
    public function schoolCount(): int
    {
        return $this->submissions()
            ->map(fn (WfpSubmission $submission): string => $submission->schoolLabel())
            ->unique()
            ->count();
    }

    public function render()
    {
        return view('livewire.wfp.uploads-list');
    }
}
