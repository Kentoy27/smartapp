<?php

namespace App\Livewire;

use App\Models\WfpSubmission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "My WFP Uploads" — the School Head's own list at the foot of /wfp: the
 * Work and Financial Plan workbooks they have handed back, each with a
 * download that serves their archived copy.
 *
 * The school-side counterpart of WfpUploadsList (the superadmin's all-school
 * card): this one is scoped to the signed-in School Head's own uploads, so it
 * shows the file, size and when it came in without exposing anyone else's.
 *
 * School Head only (mount() aborts for everyone else), and it refreshes on the
 * `wfp-uploaded` event WfpUpload fires, so a workbook uploaded moments ago
 * appears here without a reload.
 */
class WfpMyUploadsList extends Component
{
    public function mount(): void
    {
        // The page is SH-only; the list is the caller's own uploads, so the
        // same gate applies before anything renders.
        abort_unless(Auth::user()?->canAccessWfp(), 404);
    }

    /**
     * The signed-in School Head's uploaded WFPs, newest first.
     *
     * In practice one row: WfpUpload replaces the owner's previous submission
     * on a re-upload, so this is the current plan rather than a full history.
     *
     * @return Collection<int, WfpSubmission>
     */
    #[Computed]
    public function submissions(): Collection
    {
        return WfpSubmission::query()
            ->where('user_id', Auth::id())
            ->latest('uploaded_at')
            ->latest('id')
            ->get();
    }

    /**
     * A workbook was just uploaded on this page — drop the cached list so it
     * re-reads and the new row shows.
     */
    #[On('wfp-uploaded')]
    public function refreshUploads(): void
    {
        unset($this->submissions);
    }

    public function render()
    {
        return view('livewire.wfp.my-uploads-list');
    }
}
