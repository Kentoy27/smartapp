<?php

namespace App\Livewire;

use App\Support\WfpAccountCodeGenerator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * "Generate and Download WFP" trigger — on the dashboard's Financial plan card
 * and on the /wfp page.
 *
 * The workbook is produced on the server from the official template and the
 * school's account-code master list (no upload round-trip) and returned as a
 * download. A generation problem (no account codes, an incomplete or
 * duplicated code) is reported as a toast rather than a broken download.
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

        try {
            $bytes = WfpAccountCodeGenerator::bytes();
            $name = WfpAccountCodeGenerator::downloadName(Auth::user());
        } catch (RuntimeException $e) {
            $this->errorMessage = $e->getMessage();

            return null;
        }

        return response()->streamDownload(
            fn () => print ($bytes),
            $name,
            [
                'Content-Type' => WfpAccountCodeGenerator::CONTENT_TYPE,
                'Content-Length' => (string) strlen($bytes),
            ],
        );
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    public function render()
    {
        return view('livewire.wfp.generate-button');
    }
}
