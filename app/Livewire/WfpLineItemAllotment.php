<?php

namespace App\Livewire;

use App\Models\School;
use App\Models\User;
use App\Support\WfpAllotment;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The annual MOOE allotment allocated per line item or object of expenditure:
 * one school's year of Monthly Expenditure Matrices read as the printed report
 * — the MONTH / BUDGET column on the left, and the year's lines rolled up per
 * account code with their share of the grand total on the right.
 *
 * The figures are not computed here. They come from WfpAllotment, which is
 * also what the page's .xlsx export reads, so what is downloaded is what was
 * read on screen.
 *
 * The report is DERIVED, never stored: a line entered this afternoon shows up
 * in the report this afternoon, with no regeneration step in between.
 *
 * Scope is the signed-in School Head's own school — every query is filtered by
 * `school_id`, so no other school's expenditure can be read here. The page is
 * reachable by nobody else: the route guard and this component both refuse a
 * non-SH account with a 404.
 */
#[Title('Per Line Item Expenditure — SmartApp')]
class WfpLineItemAllotment extends Component
{
    /**
     * The planning year the report covers, as a string (the select's own
     * type). Defaults to the newest year the school has a matrix for, so the
     * page opens on figures rather than on an empty year.
     */
    public string $year = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);

        if ($this->year === '') {
            $this->year = (string) WfpAllotment::resolveYear(Auth::user()?->school_id, 0);
        }
    }

    /* -------------------------------------------------------------- guards */

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessWfp(), 404);

        return $user;
    }

    /* --------------------------------------------------------------- state */

    public function updatedYear(): void
    {
        // A year the school has no matrices for is not a year to report on: a
        // crafted value falls back to the newest year that does rather than
        // rendering a report of nothing.
        $this->year = (string) WfpAllotment::resolveYear(Auth::user()?->school_id, (int) $this->year);

        // The figures are computed properties keyed off this year; forgetting
        // them is what makes the page re-read for the year that was picked.
        unset($this->months, $this->lineItems, $this->grandTotal);
    }

    /* ----------------------------------------------------------- computed */

    #[Computed]
    public function school(): ?School
    {
        return Auth::user()?->school;
    }

    /**
     * Every year this school has a matrix for, newest first. A school with no
     * matrices still gets one entry — the current year — so the year select is
     * never an empty control.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function years(): array
    {
        return WfpAllotment::years(Auth::user()?->school_id);
    }

    /**
     * The MONTH / BUDGET column: the calendar year, each month with the total
     * of its own matrix beside it, zero for a month without one.
     *
     * @return array<int, array{label: string, budget: float, has_matrix: bool}>
     */
    #[Computed]
    public function months(): array
    {
        return WfpAllotment::months(Auth::user()?->school_id, (int) $this->year);
    }

    /**
     * The No. / Account Title / Account Code / Total Amount / % rows: the
     * year's lines rolled up per account code, in account-code order, each
     * with its share of the grand total.
     *
     * @return array<int, array{title: string, code: ?string, total: float, percent: float}>
     */
    #[Computed]
    public function lineItems(): array
    {
        if ($this->year === '') {
            return [];
        }

        return WfpAllotment::lineItems(Auth::user()?->school_id, (int) $this->year);
    }

    /**
     * The year's total: the figure the percentages are shares of. It is the
     * sum of the line-item rows, which is the same money as the sum of the
     * twelve months — one year's lines, read two ways.
     */
    #[Computed]
    public function grandTotal(): float
    {
        return WfpAllotment::total($this->lineItems);
    }

    public function render()
    {
        $this->user();

        return view('livewire.wfp.line-items', [
            'preparedBy' => Auth::user(),
        ]);
    }
}
