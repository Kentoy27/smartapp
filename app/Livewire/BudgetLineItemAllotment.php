<?php

namespace App\Livewire;

use App\Models\School;
use App\Models\User;
use App\Models\WfpReportCheck;
use App\Support\WfpAllotment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Per Line Item Expenditure — the Budget office's copy of one school's annual
 * MOOE allotment report.
 *
 * It is the same printed form the School Head reads on /wfp/line-items: the
 * MONTH / BUDGET column beside the year's lines rolled up per account code,
 * with each line's share of the grand total. The difference is scope. A School
 * Head is fixed to their own school; the Budget office oversees every school,
 * so the report is read one school at a time through `schoolId`.
 *
 * The figures are not computed here. They come from WfpAllotment, which is
 * also what the .xlsx export reads, so what is downloaded is what was read on
 * screen — and the same helper the School Head's copy uses, so the two pages
 * can never disagree.
 *
 * The report is DERIVED, never stored: a line entered this afternoon shows up
 * in the report this afternoon, with no regeneration step in between.
 *
 * Scope is the Budget role and the school Bookkeeper (canCheckLineItems()):
 * the route guard and this component both refuse any other account with a
 * 404.
 *
 * On the Bookkeeper's copy the report also carries its one action: the
 * "Checked as to the Correctness of Account Titles and Codes" sign-off (see
 * check()), which stores the checker for the school on screen and stamps
 * the report and its export.
 */
#[Title('Per Line Item Expenditure — SmartApp')]
class BudgetLineItemAllotment extends Component
{
    /**
     * The school whose report is open. It is the picker's own value (a
     * string), kept separate from the computed `school` model so a crafted
     * value can be validated before anything is queried.
     */
    #[Url(as: 'school')]
    public string $schoolId = '';

    /**
     * The planning year the report covers, as a string (the select's own
     * type). Defaults to the newest year the school has a matrix for, so the
     * page opens on figures rather than on an empty year.
     */
    #[Url]
    public string $year = '';

    /** The check-confirmation modal's open state (Bookkeepers only). */
    public bool $showCheckModal = false;

    /** Success toast after a stored check (cleared via clearSuccess()). */
    public ?string $successMessage = null;

    /** A check that could not be given (cleared via clearError()). */
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->user();

        // No school in the URL: open on the first school so the page lands on
        // a report rather than on an empty picker. A stale or malformed id
        // falls back the same way.
        if (! $this->validSchoolId()) {
            $this->schoolId = (string) School::query()->orderBy('name')->value('id');
        }

        $this->year = (string) WfpAllotment::resolveYear(
            $this->school?->id,
            (int) $this->year,
        );
    }

    /* -------------------------------------------------------------- guards */

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canCheckLineItems(), 404);

        return $user;
    }

    /* --------------------------------------------------------------- state */

    public function updatedSchoolId(): void
    {
        $this->user();

        // The picker chooses the report's scope, not a row to trust: an id
        // that is not a real school falls back to the first one rather than
        // reaching a query.
        unset($this->school);

        if (! $this->validSchoolId()) {
            $this->schoolId = (string) ($this->schools->first()?->id ?? '');
        }

        unset($this->school);

        // Keep the reader's year when the newly picked school has it;
        // otherwise open on the newest year that school actually has.
        $this->year = (string) WfpAllotment::resolveYear(
            $this->school?->id,
            (int) $this->year,
        );

        $this->forgetFigures();
    }

    public function updatedYear(): void
    {
        $this->user();

        // A year the school has no matrices for is not a year to report on:
        // a crafted value falls back to the newest year that does rather than
        // rendering a report of nothing.
        $this->year = (string) WfpAllotment::resolveYear(
            $this->school?->id,
            (int) $this->year,
        );

        $this->forgetFigures();
    }

    /**
     * Drop the memoised figures after the school or year changes. Every
     * section of the report reads a slice of the same selection, so they are
     * refreshed together.
     */
    private function forgetFigures(): void
    {
        unset(
            $this->school,
            $this->years,
            $this->months,
            $this->lineItems,
            $this->grandTotal,
            $this->allocation,
            $this->certification,
            $this->checking,
            $this->preparedBy,
        );
    }

    /* --------------------------------------------------------- checking */

    /**
     * Whether the signed-in account may sign the "Checked as to the
     * Correctness of Account Titles and Codes" block — the Bookkeeper alone.
     * The Blade shows the action only to them; every action re-checks it.
     */
    public function canCheck(): bool
    {
        return Auth::user()?->canCheckWfpReport() === true;
    }

    /**
     * Ask before checking: the check is the Bookkeeper's own statement, so
     * the modal restates the school and year first (the same courtesy the
     * Budget office's certification gives).
     */
    public function openCheckModal(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canCheckWfpReport(), 404);

        $this->errorMessage = null;
        $this->showCheckModal = true;
        $this->resetValidation();
    }

    public function closeCheckModal(): void
    {
        $this->showCheckModal = false;
    }

    /**
     * Record that the Bookkeeper checked the open school's account titles
     * and codes for the year. The row it writes is the report's signatory
     * block: name, position and date, on the page and in the Excel export.
     *
     * The school is the one the picker has on screen — resolved into a real
     * School here, never trusted as an id — and nothing to check on an empty
     * year is refused: a check over no lines would be a signature on figures
     * the Bookkeeper never saw.
     */
    public function check(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canCheckWfpReport(), 404);

        $school = $this->school;

        if ($school === null) {
            $this->showCheckModal = false;
            $this->errorMessage = 'Choose a school first — there is no report to check.';

            return;
        }

        $year = (int) WfpAllotment::resolveYear($school->id, (int) $this->year);

        if (WfpAllotment::lineItems($school->id, $year) === []) {
            $this->showCheckModal = false;
            $this->errorMessage = 'There are no expenditure lines for '.$school->name.' in CY '.$year.' yet, so there is nothing to check.';

            return;
        }

        // One check per school and year: checking again replaces the previous
        // checker, exactly as certifying again replaces the previous officer.
        WfpReportCheck::query()->updateOrCreate(
            ['school_id' => $school->id, 'year' => $year],
            ['checked_by' => $user->id, 'checked_at' => now()],
        );

        $this->showCheckModal = false;
        $this->errorMessage = null;
        $this->successMessage = 'Checked — the CY '.$year.' report for '.$school->name
            .' now carries your name as the checker of its account titles and codes.';

        unset($this->checking);
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    /* ----------------------------------------------------------- computed */

    /**
     * Every school the Budget office can report on, by name. The report is
     * read one school at a time, so this is the picker's whole list.
     *
     * @return Collection<int, School>
     */
    #[Computed]
    public function schools(): Collection
    {
        return School::query()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * The school whose report is open, or null on an installation with no
     * schools. mount() and updatedSchoolId() have already dropped an id that
     * does not exist.
     */
    #[Computed]
    public function school(): ?School
    {
        if (! $this->validSchoolId()) {
            return null;
        }

        return School::query()
            ->with('district')
            ->find((int) $this->schoolId);
    }

    /**
     * Every year the open school has a matrix for, newest first. A school with
     * no matrices still gets one entry — the current year — so the year select
     * is never an empty control.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function years(): array
    {
        return WfpAllotment::years($this->school?->id);
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
        return WfpAllotment::months($this->school?->id, (int) $this->year);
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
        if ($this->year === '' || $this->school === null) {
            return [];
        }

        return WfpAllotment::lineItems($this->school->id, (int) $this->year);
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

    /**
     * The stored allotment budget read against the year's own consumption —
     * the Monthly Expenditure Matrices this page already rolls up.
     *
     * A null allotted amount means the Budget office has not set one for this
     * year; that is different from an allotment of 0.00. Either way,
     * consumption is reported: it happened whether or not a ceiling was set.
     *
     * @return array{allotted: ?float, consumed: float, remaining: float, percent: float, is_over: bool}
     */
    #[Computed]
    public function allocation(): array
    {
        return WfpAllotment::balance(
            WfpAllotment::allotment($this->school?->id, (int) $this->year),
            $this->grandTotal,
        );
    }

    /**
     * The Budget office's certification for this school and year, or null
     * while the amount is still awaiting one.
     *
     * @return array{name: string, position: string, certified_at: CarbonInterface}|null
     */
    #[Computed]
    public function certification(): ?array
    {
        return WfpAllotment::certification($this->school?->id, (int) $this->year);
    }

    /**
     * The Bookkeeper's check of this school's and year's account titles and
     * codes, or null while the report is still unchecked. The Budget office
     * reads the status; it does not sign for the Bookkeeper's office, so the
     * check's action never renders on this copy.
     *
     * @return array{name: string, position: string, checked_at: CarbonInterface}|null
     */
    #[Computed]
    public function checking(): ?array
    {
        return WfpAllotment::checking($this->school?->id, (int) $this->year);
    }

    /**
     * The account that signs the form as its preparer: the school's School
     * Head. The Budget officer reads this copy, they do not prepare the
     * school's plan, so the signed name is the SH's — or a blank line while
     * the school has no SH account.
     */
    #[Computed]
    public function preparedBy(): ?User
    {
        $school = $this->school;

        if ($school === null) {
            return null;
        }

        return User::query()
            ->where('school_id', $school->id)
            ->where('role', 'user')
            ->orderBy('name')
            ->orderBy('username')
            ->first();
    }

    public function render()
    {
        $this->user();

        return view('livewire.budget.line-item-allotment', [
            'preparedBy' => $this->preparedBy,
            'checking' => $this->checking,
            'canCheck' => $this->canCheck(),
        ]);
    }

    /* -------------------------------------------------------------- helpers */

    private function validSchoolId(): bool
    {
        return ctype_digit($this->schoolId)
            && School::query()->whereKey((int) $this->schoolId)->exists();
    }
}
