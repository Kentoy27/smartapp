<?php

namespace App\Livewire;

use App\Models\User;
use App\Models\WfpPlan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The WFP preview: the generated Work and Financial Plans this account may
 * read, and the plan itself once one is picked.
 *
 * The Bookkeeper reaches the generated plan here — the school Head authors it
 * from the Monthly Expenditure Matrices, and the Bookkeeper's work is to read
 * it and check the account titles and codes behind its lines. The preview is
 * the plan read-only: the same editor the School Head writes in, opened
 * without the write controls (the editor re-checks that gate itself).
 *
 * The list is the plans in scope ({@see WfpPlan::scopeVisibleTo()}), so the
 * Bookkeeper sees the schools on its assignment list and nobody else's.
 */
#[Title('WFP Preview — SmartApp')]
class WfpPreview extends Component
{
    /**
     * The plan being previewed, by id — null until one is picked.
     *
     * It is a public property rather than a query string so the preview is
     * reached without a page load, the way the rest of the app moves
     * between pages (wire:navigate).
     */
    public ?int $planId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canViewWfpSignoffs(), 404);
    }

    /**
     * The generated plans in scope, newest year first.
     *
     * The lines come along with them so the list can show each plan's line
     * count and annual total without a query per row.
     */
    #[Computed]
    public function plans()
    {
        return WfpPlan::query()
            ->with(['school', 'items'])
            ->visibleTo(Auth::user())
            ->orderByDesc('year')
            ->orderBy('school_id')
            ->paginate(15);
    }

    /**
     * The plan being previewed, or null when none has been picked.
     *
     * A plan the account may not read is not previewed: the id is checked
     * against the same gate the plan page uses, so a crafted id shows
     * nothing rather than another school's plan.
     */
    #[Computed]
    public function selectedPlan(): ?WfpPlan
    {
        if ($this->planId === null) {
            return null;
        }

        $plan = WfpPlan::query()->with('school')->find($this->planId);

        return $plan !== null && $plan->canBeViewedBy(Auth::user()) ? $plan : null;
    }

    /**
     * Preview one of the plans in scope.
     *
     * The plan is checked here as well as in the preview itself, so a plan
     * outside the account's reach is refused with the same 404 the plan page
     * gives — never quietly shown.
     */
    public function selectPlan(int $planId): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 404);

        $plan = WfpPlan::query()->find($planId);

        abort_unless($plan !== null && $plan->canBeViewedBy($user), 404);

        $this->planId = $plan->id;

        unset($this->selectedPlan);
    }

    /** Close the preview and go back to the list. */
    public function closePreview(): void
    {
        $this->planId = null;

        unset($this->selectedPlan);
    }

    public function render()
    {
        return view('livewire.wfp.preview');
    }
}
