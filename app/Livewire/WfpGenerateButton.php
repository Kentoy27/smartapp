<?php

namespace App\Livewire;

use App\Models\User;
use App\Models\WfpExportAudit;
use App\Models\WfpPlan;
use App\Support\WfpAllotment;
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

    /**
     * Whether the sign-off chain renders beneath the controls. The /wfp page
     * gives it its own card instead (and follows the year through the
     * `wfp-year-changed` event); the dashboard keeps it inline.
     */
    public bool $showSignatories = true;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);

        $this->year = (string) $this->downloadYear();
    }

    /**
     * Build the workbook and hand it to the browser as a download. A generation
     * problem is caught and shown as a toast instead of returning a bad file.
     */
    public function download(string $signatureMode = 'with')
    {
        abort_unless(Auth::user()?->canManageWfp(), 404);
        abort_unless(in_array($signatureMode, ['with', 'without'], true), 404);

        $user = Auth::user();
        abort_unless($user instanceof User, 404);

        $year = $this->downloadYear();
        $includeSignatures = $signatureMode === 'with';

        try {
            // The school's own matrices win: that is the plan the SH has been
            // working in, and it is what the form is meant to hold. The year
            // is the one picked in the select, never merely "the newest".
            $plan = WfpPlanExporter::exportableFor($user, $year);

            if ($plan !== null) {
                $bytes = WfpPlanExporter::bytes($plan, $includeSignatures, $user);
                $year = (int) $plan->year;
            } else {
                $bytes = WfpAccountCodeGenerator::bytes($user, $year);
            }
        } catch (RuntimeException $e) {
            $this->errorMessage = $e->getMessage();

            return null;
        }

        WfpExportAudit::record($user, $plan, $year, $includeSignatures);
        $name = self::downloadName($user, $year, $includeSignatures);

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
        unset($this->missingSignatureLabels);
        unset($this->signatoryStatuses);

        // A page that renders the sign-off chain in its own card (the /wfp
        // workspace) follows the year that just resolved.
        $this->dispatch('wfp-year-changed', year: (int) $this->year);
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

    /**
     * The selected year's current plan, without creating or changing it.
     * A blank form has no recorded signatures to include.
     */
    #[Computed]
    public function missingSignatureLabels(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $plan = WfpPlanExporter::existingFor($user, $this->downloadYear());

        return WfpPlanExporter::missingSignatureLabels($plan, $user);
    }

    #[Computed]
    public function signatoryStatuses(): array
    {
        $user = Auth::user();

        return WfpAllotment::signatoryStatuses(
            $user?->school_id,
            $this->downloadYear(),
            $user?->activeDivision(),
        );
    }

    private static function downloadName(User $user, int $year, bool $includeSignatures): string
    {
        $original = WfpAccountCodeGenerator::downloadName($user, $year);
        $suffix = $includeSignatures ? '_Signed.xlsx' : '_Unsigned.xlsx';

        return substr($original, 0, -5).$suffix;
    }

    public function render()
    {
        return view('livewire.wfp.generate-button');
    }
}
