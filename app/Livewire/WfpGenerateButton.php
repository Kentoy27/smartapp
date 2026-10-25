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
 * What comes back depends on whether the school has anything to build from
 * for the year they pick:
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
 * The planning year is the School Head's to pick, but only from years their
 * school actually has data for (WfpPlanExporter::dataYears) — the select is a
 * choice between real workbooks, not a way to re-title one year's numbers as
 * another's.
 *
 * Generating is the School Head's (SH) alone: mount() checks canAccessWfp()
 * and download() re-checks canManageWfp(), so a crafted call from another
 * role 404s. A read-only reader (should one ever be admitted to this page)
 * gets the button disabled rather than a dead click.
 */
#[Title('Dashboard — SmartApp')]
class WfpGenerateButton extends Component
{
    /**
     * A generation problem, surfaced as an error toast (cleared via clearError()).
     */
    public ?string $errorMessage = null;

    /**
     * The planning year to generate for, as the select's own string type.
     * Defaults to the newest year the school has data for.
     */
    public string $year = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);

        $this->year = (string) $this->downloadYear();
    }

    /**
     * Build the workbook and hand it to the browser as a download. A generation
     * problem is caught and shown as a toast instead of returning a bad file.
     */
    public function download()
    {
        abort_unless(Auth::user()?->canManageWfp(), 404);

        $user = Auth::user();
        $year = $this->downloadYear();

        try {
            // The school's own matrices win: that is the plan the SH has been
            // working in, and it is what the form is meant to hold. The year
            // is the one picked in the select, never merely "the newest".
            $plan = WfpPlanExporter::exportableFor($user instanceof User ? $user : null, $year);

            if ($plan !== null) {
                $bytes = WfpPlanExporter::bytes($plan);
                $name = WfpAccountCodeGenerator::downloadName($user instanceof User ? $user : null, $plan->year);
            } else {
                $bytes = WfpAccountCodeGenerator::bytes($user instanceof User ? $user : null, $year);
                $name = WfpAccountCodeGenerator::downloadName($user instanceof User ? $user : null, $year);
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
     * Whether the signed-in account may generate. The Blade renders the
     * button disabled for a read-only reader; download() refuses them
     * server-side regardless.
     */
    public function canEdit(): bool
    {
        return Auth::user()?->canManageWfp() === true;
    }

    public function updatedYear(): void
    {
        // A year the school has nothing in is not a year to generate for: a
        // crafted value falls back to the newest year that does, the same rule
        // WfpAllotment::resolveYear applies to the allotment report.
        $this->year = (string) $this->downloadYear();

        // The caption is keyed off the year that resolved; forget it so the
        // render reads it again for the year that was picked.
        unset($this->planYear);
    }

    /**
     * The year the download will be generated for: the picked year when the
     * school has data in it, the newest year that has data otherwise, and the
     * current year when it has none at all (the blank account-code form).
     */
    private function downloadYear(): int
    {
        $selected = $this->year === '' ? null : (int) $this->year;

        return WfpPlanExporter::dataYearFor(Auth::user(), $selected) ?? (int) now()->year;
    }

    /**
     * The years the select offers: every year the school has a plan or matrix
     * lines for, newest first. A school with neither still gets the current
     * year, because the blank account-code form is generated for it.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function years(): array
    {
        $years = WfpPlanExporter::dataYears(Auth::user());

        return $years === [] ? [(int) now()->year] : $years;
    }

    /**
     * How many codes the blank account-code form would carry — the caption's
     * figure, only read when that is the workbook being generated.
     */
    #[Computed]
    public function accountCount(): int
    {
        return WfpAccountCodeGenerator::activeCount();
    }

    /**
     * The year the download will be generated from, or null when the school has
     * no matrix data yet and the blank form is what will be built. Read-only:
     * the view says which workbook is coming, and a render never writes.
     */
    #[Computed]
    public function planYear(): ?int
    {
        return WfpPlanExporter::dataYearFor(
            Auth::user(),
            $this->year === '' ? null : (int) $this->year,
        );
    }

    public function render()
    {
        return view('livewire.wfp.generate-button');
    }
}
