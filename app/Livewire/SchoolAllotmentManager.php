<?php

namespace App\Livewire;

use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Support\MemYearInitializer;
use App\Support\WfpAllotment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * School Allotments — the Budget office's page for setting what each School
 * Head may consume in a calendar year, and for certifying that the amount is
 * available.
 *
 * The allotment is stored per SCHOOL and year (see SchoolAllotment). The
 * consumption beside it is derived from that school's Monthly Expenditure
 * Matrices, so the balance moves the moment a line is edited in the matrix
 * editor — there is no "post the expenditures" step here.
 *
 * Scope is the Budget role (and the administrative side that oversees it);
 * the School Head never reaches this component. Every action re-checks the
 * same gate, so a crafted Livewire call is refused like a crafted URL.
 * Certification is narrower still: only the Budget role signs for the Budget
 * office, so the administrative side can set and read the ceiling but cannot
 * certify it.
 */
#[Title('School Allotments — SmartApp')]
class SchoolAllotmentManager extends Component
{
    use WithPagination;

    /** The budget year being set. */
    public string $year = '';

    /** Search across school, School ID, district and School Head. */
    public string $search = '';

    public int $perPage = 25;

    /**
     * The amount inputs, keyed by school id. An empty value means "no
     * allotment set" and clears the row on save.
     *
     * @var array<int, string>
     */
    public array $amounts = [];

    /** The school whose certification confirmation is open, if any. */
    public ?int $certifyingSchoolId = null;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canManageAllotments(), 404);

        $this->year = (string) now()->year;
        $this->loadAmounts();
    }

    /* -------------------------------------------------------------- guards */

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageAllotments(), 404);

        return $user;
    }

    /* --------------------------------------------------------------- state */

    public function updatedYear(): void
    {
        $this->user();

        // A crafted year is not a budget year: fall back to the current one
        // rather than loading (or saving against) a year with no bounds.
        if (! $this->validYear()) {
            $this->year = (string) now()->year;
        }

        $this->loadAmounts();
        $this->resetPage();
        $this->certifyingSchoolId = null;

        // The overview is keyed off the year; forgetting it is what makes the
        // strip re-read for the year that was picked.
        unset($this->overview);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    /* ---------------------------------------------------------------- save */

    /**
     * Save one school's allotment for the selected year. A blank amount
     * clears the row: an allotment the Budget office no longer stands behind
     * should read as "not set", not as a leftover figure.
     */
    public function save(int $schoolId): void
    {
        $user = $this->user();

        if (! $this->validYear()) {
            $this->errorMessage = 'Choose a valid budget year before saving.';

            return;
        }

        $school = School::query()->find($schoolId);

        if ($school === null) {
            abort(404);
        }

        $field = 'amounts.'.$schoolId;

        $this->validateOnly($field, [
            $field => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
        ], [
            $field.'.numeric' => 'Enter the allotment as a number.',
            $field.'.min' => 'The allotment cannot be negative.',
            $field.'.max' => 'The allotment is too large to record.',
        ]);

        $raw = $this->amounts[$schoolId] ?? null;

        if ($raw === null || trim((string) $raw) === '') {
            SchoolAllotment::query()
                ->where('school_id', $school->id)
                ->where('year', (int) $this->year)
                ->delete();

            unset($this->amounts[$schoolId]);
            $this->certifyingSchoolId = null;
            unset($this->overview);
            $this->errorMessage = null;
            $this->successMessage = 'Allotment cleared — '.$school->name
                .' has no CY '.$this->year.' allotment on record.';

            return;
        }

        $amount = round((float) $raw, 2);

        $existing = SchoolAllotment::query()
            ->where('school_id', $school->id)
            ->where('year', (int) $this->year)
            ->first();

        // A certification is about one amount: changing the amount makes the
        // old certification untrue, so it is cleared and must be given again.
        // Re-saving the same figure keeps it, so a typo fix elsewhere does not
        // silently strip a valid certification.
        $amountChanged = $existing === null || (float) $existing->amount !== $amount;
        $wasCertified = $existing?->isCertified() ?? false;

        $attributes = ['amount' => $amount, 'set_by' => $user->id];

        if ($amountChanged) {
            $attributes['certified_by'] = null;
            $attributes['certified_at'] = null;
        }

        SchoolAllotment::query()->updateOrCreate(
            ['school_id' => $school->id, 'year' => (int) $this->year],
            $attributes,
        );

        // Echo the stored figure back so the field shows exactly what the
        // database now holds (2 decimals, no thousands separators).
        $this->amounts[$schoolId] = number_format($amount, 2, '.', '');
        $this->certifyingSchoolId = null;
        unset($this->overview);
        $this->errorMessage = null;
        $this->successMessage = 'Allotment saved — '.$school->name.' may consume '
            .number_format($amount, 2).' in CY '.$this->year.'.'
            .($amountChanged && $wasCertified
                ? ' The previous certification was cleared because the amount changed.'
                : '');
    }

    /* -------------------------------------------------------- certification */

    /**
     * Ask before certifying: the confirmation is the Budget officer's own
     * statement, so the modal restates the school, year and amount first.
     */
    public function openCertifyModal(int $schoolId): void
    {
        $user = $this->user();
        abort_unless($user->canCertifyAllotments(), 404);

        if (! $this->validYear()) {
            $this->errorMessage = 'Choose a valid budget year before certifying.';

            return;
        }

        $school = School::query()->find($schoolId);

        if ($school === null) {
            abort(404);
        }

        $this->certifyingSchoolId = $school->id;
        $this->errorMessage = null;
        $this->resetValidation();
    }

    public function closeCertifyModal(): void
    {
        $this->certifyingSchoolId = null;
    }

    /**
     * Certify the selected year's allotment for one school as available.
     *
     * There is nothing to certify until an amount is on record: a certificate
     * over no allotment would be a signature on an empty figure. The amount
     * itself is re-read here rather than trusted from the client, and the
     * certification is stored against the row it covers.
     */
    public function certify(int $schoolId): void
    {
        $user = $this->user();
        abort_unless($user->canCertifyAllotments(), 404);

        if (! $this->validYear()) {
            $this->errorMessage = 'Choose a valid budget year before certifying.';

            return;
        }

        $school = School::query()->find($schoolId);

        if ($school === null) {
            abort(404);
        }

        $allotment = SchoolAllotment::query()
            ->where('school_id', $school->id)
            ->where('year', (int) $this->year)
            ->first();

        if ($allotment === null) {
            $this->certifyingSchoolId = null;
            $this->errorMessage = 'Set the '.$school->name.' allotment for CY '
                .$this->year.' before certifying it as available.';

            return;
        }

        $allotment->forceFill([
            'certified_by' => $user->id,
            'certified_at' => now(),
        ])->save();

        $this->certifyingSchoolId = null;
        $this->errorMessage = null;
        $this->successMessage = 'Certified — '.number_format((float) $allotment->amount, 2)
            .' is available for '.$school->name.' in CY '.$this->year.'.';

        unset($this->overview);
    }

    /* ---------------------------------------------------------------- year */

    private function validYear(): bool
    {
        return ctype_digit($this->year)
            && (int) $this->year >= MemYearInitializer::MIN_YEAR
            && (int) $this->year <= MemYearInitializer::MAX_YEAR;
    }

    private function loadAmounts(): void
    {
        if (! $this->validYear()) {
            $this->amounts = [];

            return;
        }

        $this->amounts = SchoolAllotment::query()
            ->where('year', (int) $this->year)
            ->pluck('amount', 'school_id')
            ->map(fn ($amount): string => number_format((float) $amount, 2, '.', ''))
            ->all();
    }

    /* ------------------------------------------------------------ computed */

    /**
     * The years the page can budget for: the current cycle, its neighbours,
     * and every year the school data already carries.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function years(): array
    {
        $years = collect([
            now()->year - 1,
            now()->year,
            now()->year + 1,
            now()->year + 2,
        ])
            ->merge(SchoolAllotment::query()->distinct()->pluck('year'))
            ->merge(MonthlyExpenditureMatrix::query()->distinct()->pluck('year'))
            ->map(fn ($year): int => (int) $year)
            ->unique()
            ->sortDesc()
            ->map(fn (int $year): string => (string) $year)
            ->values()
            ->all();

        // The selected year must always be selectable, even if a crafted
        // value slipped past updatedYear().
        if (! in_array($this->year, $years, true)) {
            $years[] = $this->year;
        }

        return $years;
    }

    /**
     * The monitoring strip: the year's allotments, consumption and
     * certification count across every school — not just the page being
     * looked at. It is the same figure the Budget dashboard shows.
     *
     * @return array{schools: int, allotted_schools: int, certified_schools: int, allotted_total: float, consumed_total: float, remaining_total: float}
     */
    #[Computed]
    public function overview(): array
    {
        return WfpAllotment::overview(
            $this->validYear() ? (int) $this->year : (int) now()->year
        );
    }

    /**
     * The schools, with their School Head account(s), filtered by the search
     * box. The consumption and allotment figures are joined in render() so
     * they are read once for the whole page rather than once per row.
     */
    #[Computed]
    public function schools(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return School::query()
            ->with([
                'district',
                'users' => fn ($query) => $query
                    ->where('role', 'user')
                    ->orderBy('name')
                    ->orderBy('username'),
            ])
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.$term.'%';

                $query->where(function ($query) use ($like): void {
                    $query
                        ->where('name', 'like', $like)
                        ->orWhere('school_id', 'like', $like)
                        ->orWhereHas('district', fn ($district) => $district->where('name', 'like', $like))
                        ->orWhereHas('users', fn ($user) => $user
                            ->where('role', 'user')
                            ->where(fn ($user) => $user
                                ->where('name', 'like', $like)
                                ->orWhere('username', 'like', $like)));
                });
            })
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    public function render()
    {
        $this->user();

        $year = $this->validYear() ? (int) $this->year : (int) now()->year;
        $schools = $this->schools;

        /** @var array<int, int> $schoolIds */
        $schoolIds = collect($schools->items())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $allotments = SchoolAllotment::query()
            ->with(['setter', 'certifier'])
            ->where('year', $year)
            ->whereIn('school_id', $schoolIds)
            ->get()
            ->keyBy('school_id');

        $certifyingSchool = $this->certifyingSchoolId === null
            ? null
            : School::query()->find($this->certifyingSchoolId);

        $certifyingAllotment = $certifyingSchool === null
            ? null
            : SchoolAllotment::query()
                ->where('school_id', $certifyingSchool->id)
                ->where('year', $year)
                ->first();

        // The modal's consumption is read for its own school rather than from
        // the page's map: the school must not read as zero just because a
        // later search or page change moved its row off the visible page.
        $certifyingSpent = $certifyingSchool === null
            ? 0.0
            : (float) (WfpAllotment::consumedBySchool([$certifyingSchool->id], $year)[$certifyingSchool->id] ?? 0.0);

        return view('livewire.budget.school-allotments', [
            'schools' => $schools,
            'allotments' => $allotments,
            'consumed' => WfpAllotment::consumedBySchool($schoolIds, $year),
            'overview' => $this->overview,
            'canCertify' => $this->user()->canCertifyAllotments(),
            'certifyingSchool' => $certifyingSchool,
            'certifyingAllotment' => $certifyingAllotment,
            'certifyingSpent' => $certifyingSpent,
        ]);
    }
}
