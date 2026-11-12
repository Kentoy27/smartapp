<?php

namespace App\Livewire;

use App\Support\WfpPlanExporter;
use App\Support\WfpTemplate;
use App\Models\WfpPlan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The /wfp page: the school's Work and Financial Plan, generated on demand.
 *
 * The workbook comes from the school's Monthly Expenditure Matrices once they
 * carry lines; until then it is the official template pre-filled from the
 * account-code master list. The generate button (WfpGenerateButton) says which
 * of the two the school gets, and for which year.
 *
 * The School Head authors here; Budget, Bookkeeper and Superadmin open the
 * scoped read-only sign-off list. The work itself is a generate-and-download,
 * handled by the nested WfpGenerateButton.
 */
#[Title('Work and Financial Plan — SmartApp')]
class WfpManager extends Component
{
    /**
     * The year the generate select picked for the sign-off card. Null until
     * the select has said otherwise.
     */
    #[Locked]
    public ?int $signatoryYear = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canViewWfpSignoffs(), 404);
    }

    /**
     * The generate select picked another year: the sign-off card follows it.
     */
    #[On('wfp-year-changed')]
    public function syncSignatoryYear(int $year): void
    {
        $this->signatoryYear = $year;

        // The card's year is computed from this one, and the year just
        // picked is a new answer to it.
        unset($this->signatoryCardYear);
    }

    /**
     * The year the sign-off card renders for: the year the select picked,
     * and until it does, the newest year the school has data for — the
     * current year when it has none — the same resolution the generate
     * button applies on first paint, so the card and the select cannot
     * disagree.
     *
     * It is a computed property, read as `$this->signatoryCardYear`, and
     * not a bare view variable: Livewire merges a component's public
     * properties into its view data and the property wins, so a variable
     * named like the property would be shadowed by it — null on the first
     * paint, which the card then hands to the child as its year.
     */
    #[Computed]
    public function signatoryCardYear(): int
    {
        return $this->signatoryYear
            ?? WfpPlanExporter::dataYearFor(Auth::user(), null)
            ?? (int) now()->year;
    }

    #[Computed]
    public function plans()
    {
        $user = Auth::user();

        return WfpPlan::query()
            ->with('school')
            ->where('school_division', $user->activeDivision())
            ->when(
                $user->visibleSchoolIds() !== null,
                fn ($query) => $query->whereIn('school_id', $user->visibleSchoolIds()),
            )
            ->orderByDesc('year')
            ->orderBy('school_id')
            ->paginate(15);
    }

    public function render()
    {
        return view('livewire.wfp.manager', [
            'fileName' => WfpTemplate::fileName(),
            'templateDescription' => WfpTemplate::description(),
        ]);
    }
}
