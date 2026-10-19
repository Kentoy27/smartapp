<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\WfpAccountCodeGenerator;
use App\Support\WfpPlanExporter;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * "Generate and Download WFP" trigger — on the dashboard's Financial plan card
 * and on the /wfp page.
 *
 * What comes back depends on whether the school has anything to build from:
 *
 *   - with Monthly Expenditure Matrix data (a WFP plan, or matrix lines) the
 *     workbook is the plan generated from those matrices — the filled form,
 *     refreshed from the MEM when it has moved on since last generation;
 *   - with no matrix data yet, the blank official form pre-filled with the
 *     account-code master list, for the School Head to complete by hand.
 *
 * Either way the workbook is produced on the server (no upload round-trip) and
 * returned as a download; a generation problem (no account codes, an incomplete
 * or duplicated code, an unreadable template) is reported as a toast rather
 * than as a broken download.
 *
 * Access is restricted to the School Head (SH) role: mount() and the download
 * action both re-check canAccessWfp(), so a crafted call from another role 404s.
 */
#[Title('Dashboard — SmartApp')]
class WfpGenerateButton extends Component
{
    /**
     * A generation problem, surfaced as an error toast (cleared via clearError()).
     */
    public ?string $errorMessage = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);
    }

    /**
     * Build the workbook and hand it to the browser as a download. A generation
     * problem is caught and shown as a toast instead of returning a bad file.
     */
    public function download()
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);

        $user = Auth::user();

        try {
            // The school's own matrices win: that is the plan the SH has been
            // working in, and it is what the form is meant to hold.
            $plan = WfpPlanExporter::exportableFor($user instanceof User ? $user : null);

            if ($plan !== null) {
                $bytes = WfpPlanExporter::bytes($plan);
                $name = WfpAccountCodeGenerator::downloadName($user instanceof User ? $user : null, $plan->year);
            } else {
                $bytes = WfpAccountCodeGenerator::bytes($user instanceof User ? $user : null);
                $name = WfpAccountCodeGenerator::downloadName($user instanceof User ? $user : null);
            }
        } catch (RuntimeException $e) {
            $this->errorMessage = $e->getMessage();

            return null;
        }

        return response()->streamDownload(
            fn () => print ($bytes),
            $name,
            [
                'Content-Type' => WfpPlanExporter::CONTENT_TYPE,
                'Content-Length' => (string) strlen($bytes),
            ],
        );
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    /**
     * The year the download will be generated from, or null when the school has
     * no matrix data yet and the blank form is what will be built. Read-only:
     * the view says which workbook is coming, and a render never writes.
     */
    #[Computed]
    public function planYear(): ?int
    {
        return WfpPlanExporter::dataYearFor(Auth::user());
    }

    public function render()
    {
        return view('livewire.wfp.generate-button');
    }
}
