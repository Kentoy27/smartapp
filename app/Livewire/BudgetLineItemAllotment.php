<?php

namespace App\Livewire;

use App\Models\School;
use App\Models\User;
use App\Models\WfpPlan;
use App\Models\WfpReportCheck;
use App\Support\WfpAllotment;
use App\Support\WfpReportSignoffs;
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
 * so the report is read one school at a time through `schoolId` — chosen from
 * a searchable picker (chooseSchool() over the `schoolSearch` box), because a
 * division's roster is longer than a select shows comfortably.
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
 * WHICH schools the report can reach is a second, independent boundary: the
 * Budget office reads every school, but a Bookkeeper reads only the schools
 * on its assignment list (User::visibleSchoolIds()). The picker's roster, the
 * opening school and every figure are narrowed through it, and a school id
 * that names a real school outside the list is refused with a 404 — a crafted
 * URL is not a permission.
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

    /**
     * The school picker's search box. It reads as the school on screen and
     * narrows the roster while typing — a division has more schools than a
     * plain select shows comfortably. The text is a search term, never the
     * report's scope: only chooseSchool() moves the report.
     */
    public string $schoolSearch = '';

    /** The check-confirmation modal's open state (Bookkeepers only). */
    public bool $showCheckModal = false;

    /** The Budget certification confirmation modal's open state. */
    public bool $showCertificationModal = false;

    /** Success toast after a stored check (cleared via clearSuccess()). */
    public ?string $successMessage = null;

    /** A check that could not be given (cleared via clearError()). */
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->user();

        // A URL naming a school outside the account's roster is refused
        // before anything else happens — the page is about to read that
        // school's figures, so the id is a request, not a filter.
        $this->guardRequestedSchool();

        // No school in the URL: open on the first school of the account's own
        // roster so the page lands on a report rather than on an empty
        // picker. A stale or malformed id falls back the same way.
        if (! $this->validSchoolId()) {
            $this->schoolId = (string) ($this->schools->first()?->id ?? '');
        }

        // The picker's box opens reading as the school on screen.
        $this->schoolSearch = (string) ($this->school?->name ?? '');

        $this->year = (string) WfpAllotment::resolveYear(
            $this->school?->id,
            (int) $this->year,
            $this->division(),
        );
    }

    /* -------------------------------------------------------------- guards */

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canCheckLineItems(), 404);

        return $user;
    }

    /** The financial division this report is read in. */
    private function division(): string
    {
        return $this->user()->activeDivision();
    }

    /**
     * Refuse an explicitly named school the account may not read. A malformed
     * or nonexistent id names no school, so it falls through to the caller's
     * own fallback; a real school outside the account's roster is a request
     * for its figures and gets the same 404 as an uninvited role. Only the
     * Bookkeeper is roster-bound (User::canAccessSchool()); for the Budget
     * office this never fires.
     */
    private function guardRequestedSchool(): void
    {
        if (ctype_digit($this->schoolId)
            && School::query()->whereKey((int) $this->schoolId)->exists()) {
            abort_unless($this->user()->canAccessSchool((int) $this->schoolId), 404);
        }
    }

    /**
     * Whether the report is narrowed to the account's assigned schools (the
     * Bookkeeper) rather than the whole division. The view reads it to say
     * whose roster the picker is.
     */
    public function scopedToAssignedSchools(): bool
    {
        return $this->user()->visibleSchoolIds() !== null;
    }

    /**
     * Whether the page is roster-scoped AND the roster is empty — the one
     * case the view says so instead of standing empty, because the fix is an
     * assignment, not a search.
     */
    public function hasNoAssignedSchools(): bool
    {
        return $this->user()->visibleSchoolIds() === [];
    }

    /* --------------------------------------------------------------- state */

    public function updatedSchoolId(): void
    {
        $this->user();

        // A crafted $wire.set() to another school's id is refused the same
        // way the URL is — the picker chooses among the account's own
        // roster, it never reaches outside it.
        $this->guardRequestedSchool();

        $this->syncSchool();
    }

    /**
     * Pick a school from the searchable picker. This is the picker's only
     * way to move the report: the box's text is a search term, not a value,
     * so a typed name that was never picked changes nothing.
     */
    public function chooseSchool(int $schoolId): void
    {
        $this->user();

        if (! School::query()->whereKey($schoolId)->exists()) {
            return;
        }

        // The menu only draws rows from the account's own roster, but the
        // action is callable on its own: a school outside it is refused,
        // never opened.
        abort_unless($this->user()->canAccessSchool($schoolId), 404);

        $this->schoolId = (string) $schoolId;

        $this->syncSchool();
    }

    /**
     * The picker's menu was closed without a pick (clicked away, Escape):
     * put the box back to reading as the school on screen, so it can never
     * look like a school that was never chosen.
     */
    public function resetSchoolSearch(): void
    {
        $this->user();

        $this->schoolSearch = (string) ($this->school?->name ?? '');
    }

    /**
     * Re-point the report at whatever `schoolId` now says: resolve the model,
     * fall back to the first school when the id is not one, keep the reader's
     * year when the new school has it, and re-read every figure. The picker's
     * box is left reading as the school on screen.
     */
    private function syncSchool(): void
    {
        // The picker chooses the report's scope, not a row to trust: an id
        // that is not a real school falls back to the first one rather than
        // reaching a query.
        unset($this->school);

        if (! $this->validSchoolId()) {
            $this->schoolId = (string) ($this->schools->first()?->id ?? '');
        }

        unset($this->school);

        $this->schoolSearch = (string) ($this->school?->name ?? '');

        // Keep the reader's year when the newly picked school has it;
        // otherwise open on the newest year that school actually has.
        $this->year = (string) WfpAllotment::resolveYear(
            $this->school?->id,
            (int) $this->year,
            $this->division(),
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
            $this->division(),
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
            $this->planUrl,
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

    public function canCertify(): bool
    {
        $user = Auth::user();
        $school = $this->school;

        return $user instanceof User
            && $user->canCertifyAllotments()
            && $school !== null
            && WfpAllotment::allotment($school->id, (int) $this->year, $this->division()) !== null
            && WfpAllotment::certification($school->id, (int) $this->year, $this->division()) === null;
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

    public function openCertificationModal(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canCertifyAllotments(), 404);

        $school = $this->school;
        abort_unless($school !== null && $user->canAccessDivision($this->division()), 404);

        if (WfpAllotment::certification($school->id, (int) $this->year, $this->division()) !== null) {
            $this->errorMessage = $school->name.'’s CY '.$this->year.' allotment is already certified.';

            return;
        }

        if (WfpAllotment::allotment($school->id, (int) $this->year, $this->division()) === null) {
            $this->errorMessage = 'Set an allotment for '.$school->name.' in CY '.$this->year.' before signing its certification.';

            return;
        }

        $this->errorMessage = null;
        $this->showCertificationModal = true;
        $this->resetValidation();
    }

    public function closeCertificationModal(): void
    {
        $this->showCertificationModal = false;
    }

    public function certify(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canCertifyAllotments(), 404);

        $school = $this->school;
        abort_unless($school !== null && $user->canAccessDivision($this->division()), 404);

        $division = $this->division();
        $year = (int) WfpAllotment::resolveYear($school->id, (int) $this->year, $division);

        try {
            WfpReportSignoffs::certify($user, $school->id, $year, $division);
        } catch (\RuntimeException $exception) {
            $this->showCertificationModal = false;
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $submitted = WfpReportSignoffs::submitReadyPlan($school->id, $year, $division) !== null;

        $this->showCertificationModal = false;
        $this->errorMessage = null;
        $this->successMessage = 'Certified — '.$school->name.'’s CY '.$year.' allotment is available.'
            .($submitted
                ? ' WFP submitted to the Schools Division Superintendent for final approval.'
                : '');

        if ($submitted) {
            $this->dispatch('wfp-plan-submitted');
        }

        $this->forgetFigures();
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

        // The check is a signature over the open school's figures, so the
        // roster boundary is re-verified at the write itself: a crafted call
        // cannot sign for a school the Bookkeeper was never assigned.
        abort_unless($user->canAccessSchool($school->id), 404);

        $division = $this->division();
        $year = (int) WfpAllotment::resolveYear($school->id, (int) $this->year, $division);

        if (WfpAllotment::lineItems($school->id, $year, $division) === []) {
            $this->showCheckModal = false;
            $this->errorMessage = 'There are no expenditure lines for '.$school->name.' in CY '.$year.' yet, so there is nothing to check.';

            return;
        }

        // One check per school and year: checking again replaces the previous
        // checker, exactly as certifying again replaces the previous officer.
        WfpReportCheck::query()->updateOrCreate(
            [
                'school_id' => $school->id,
                'school_division' => $division,
                'year' => $year,
            ],
            ['checked_by' => $user->id, 'checked_at' => now()],
        );

        $submitted = WfpReportSignoffs::submitReadyPlan($school->id, $year, $division) !== null;

        $this->showCheckModal = false;
        $this->errorMessage = null;
        $this->successMessage = 'Checked — the CY '.$year.' report for '.$school->name
            .' now carries your name as the checker of its account titles and codes.'
            .($submitted
                ? ' WFP submitted to the Schools Division Superintendent for final approval.'
                : '');

        if ($submitted) {
            $this->dispatch('wfp-plan-submitted');
        }

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
     * The schools the account can report on, by name. The report is read one
     * school at a time, so this is the picker's whole list — the fallback
     * source when a crafted id resolves to nothing, and the roster
     * schoolOptions() narrows while the reader types. The Budget office's
     * list is every school; the Bookkeeper's is its assignment list alone,
     * so a school outside it is not even offered, let alone opened.
     *
     * @return Collection<int, School>
     */
    #[Computed]
    public function schools(): Collection
    {
        $visible = $this->user()->visibleSchoolIds();

        return School::query()
            ->when($visible !== null, fn ($query) => $query->whereIn('id', $visible))
            ->with('district')
            ->orderBy('name')
            ->get(['id', 'name', 'school_id', 'district_id']);
    }

    /**
     * The picker's menu: the roster narrowed by what the search box currently
     * reads. While the box just displays the school on screen (its state
     * after opening or picking), the whole roster is offered — the list
     * narrows only once a term is actually typed.
     *
     * The term matches the name, the DepEd school ID and the district, so a
     * reader who knows the school by its ID number finds it the same way the
     * Monthly Expenditure index search does.
     *
     * @return Collection<int, School>
     */
    #[Computed]
    public function schoolOptions(): Collection
    {
        $term = trim($this->schoolSearch);

        // The box reading as the selected school is a display, not a search:
        // offer the whole roster so re-opening the menu is useful.
        if ($term === '' || $this->isSelectedSchoolName($term)) {
            return $this->schools;
        }

        $needle = mb_strtolower($term);

        return $this->schools
            ->filter(fn (School $school): bool => str_contains(mb_strtolower((string) $school->name), $needle)
                || str_contains(mb_strtolower((string) $school->school_id), $needle)
                || str_contains(mb_strtolower((string) $school->district?->name), $needle))
            ->values();
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
        return WfpAllotment::years($this->school?->id, $this->division());
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
        return WfpAllotment::months($this->school?->id, (int) $this->year, $this->division());
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

        return WfpAllotment::lineItems($this->school->id, (int) $this->year, $this->division());
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
            WfpAllotment::allotment($this->school?->id, (int) $this->year, $this->division()),
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
        return WfpAllotment::certification($this->school?->id, (int) $this->year, $this->division());
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
        return WfpAllotment::checking($this->school?->id, (int) $this->year, $this->division());
    }

    #[Computed]
    public function signatoryStatuses(): array
    {
        return WfpAllotment::signatoryStatuses(
            $this->school?->id,
            (int) $this->year,
            $this->division(),
        );
    }

    /**
     * The school's generated WFP page for the year on screen, when the viewer
     * is the Bookkeeper and a plan exists — the route from the annual report
     * to the plan whose correctness block the Bookkeeper signs. The Budget
     * office gets no link: the plan is where the signature is given, and that
     * is not its action. Null while no plan has been generated yet.
     */
    #[Computed]
    public function planUrl(): ?string
    {
        if (! $this->canCheck() || $this->school === null) {
            return null;
        }

        $plan = WfpPlan::query()
            ->where('school_id', $this->school->id)
            ->where('school_division', $this->division())
            ->where('year', (int) $this->year)
            ->first();

        return $plan === null ? null : route('wfp.plans.edit', ['plan' => $plan->id]);
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

        $division = $this->division();

        return User::query()
            ->where('school_id', $school->id)
            ->where('role', 'user')
            ->orderBy('name')
            ->orderBy('username')
            ->get()
            ->first(fn (User $candidate): bool => $candidate->canAccessDivision($division));
    }

    public function render()
    {
        $this->user();

        return view('livewire.budget.line-item-allotment', [
            'preparedBy' => $this->preparedBy,
            'checking' => $this->checking,
            'signatoryStatuses' => $this->signatoryStatuses,
            'canCheck' => $this->canCheck(),
            'canCertify' => $this->canCertify(),
            'planUrl' => $this->planUrl,
        ]);
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Whether `schoolId` names a school this account may report on. The
     * roster check is part of validity — not a separate step a caller might
     * forget — so every reader of the resolved `school` (the figures, the
     * years, the check's own preconditions) is scope-safe by construction.
     */
    private function validSchoolId(): bool
    {
        return ctype_digit($this->schoolId)
            && School::query()->whereKey((int) $this->schoolId)->exists()
            && $this->user()->canAccessSchool((int) $this->schoolId);
    }

    /** Whether the picker's box is just displaying the selected school. */
    private function isSelectedSchoolName(string $term): bool
    {
        $school = $this->school;

        return $school !== null
            && mb_strtolower(trim($school->name)) === mb_strtolower($term);
    }
}
