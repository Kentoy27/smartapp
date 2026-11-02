<?php

namespace App\Livewire;

use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\SchoolAllotmentAudit;
use App\Models\User;
use App\Support\MemYearInitializer;
use App\Support\SchoolAllotmentImporter;
use App\Support\SchoolDivision;
use App\Support\WfpAllotment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;

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
    use WithFileUploads;
    use WithPagination;

    /** How many analysed rows are shown in the bulk preview. Every row is
     *  still applied — this only caps what is sent to the browser, and the
     *  rows beyond it stay selected. */
    private const BULK_PREVIEW_LIMIT = 200;

    /**
     * The slices the school table can show. 'all' is the default; the others
     * are the questions the Budget office actually asks of a division's
     * roster — who has a School Head to consume the money, who is still
     * missing an allotment, and whose is already set.
     */
    private const SCHOOL_FILTERS = ['all', 'with_head', 'without_allotment', 'with_allotment'];

    /** The budget year being set. */
    public string $year = '';

    /** Search across school, School ID, district and School Head. */
    public string $search = '';

    /**
     * Which slice of the division's schools the table shows: every school
     * ('all'), the ones a School Head works in this division ('with_head'),
     * or the ones still missing an amount ('without_allotment').
     */
    public string $schoolFilter = 'all';

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

    /* ------------------------------------------------ bulk set allotments */

    /** Whether the bulk modal is open. */
    public bool $showBulk = false;

    /** The input being used: a pasted list ('paste') or an uploaded CSV. */
    public string $bulkMode = 'paste';

    /** The pasted "School ID, Amount" rows. */
    public string $bulkText = '';

    /** The uploaded CSV file. */
    public $bulkFile;

    /**
     * Whether a blank amount clears the allotment instead of being refused.
     * Off by default: a row whose amount cell was lost in a paste must not
     * silently wipe an allotment.
     */
    public bool $bulkBlankClears = false;

    /**
     * The analysed preview rows (the first BULK_PREVIEW_LIMIT of them).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $bulkRows = [];

    /**
     * The preview rows the officer unchecked, keyed by line. Rows that are
     * not in the preview (beyond the limit) stay included; so does every row
     * whose key is absent here.
     *
     * @var array<int|string, bool>
     */
    public array $bulkIncluded = [];

    public int $bulkTotalRows = 0;

    /** Rows that are valid, not superseded — the whole input, not the preview. */
    public int $bulkReadyCount = 0;

    public int $bulkInvalidCount = 0;

    public int $bulkSupersededCount = 0;

    /** Ready rows whose certification the apply would clear. */
    public int $bulkClearCertificationCount = 0;

    public ?string $bulkError = null;

    /** Whether the confirmation step is showing (the modal's second screen). */
    public bool $bulkConfirming = false;

    /** The counts the confirmation restates, read fresh from the source. */
    public int $bulkApplyCount = 0;

    public int $bulkApplyClearCertificationCount = 0;

    /**
     * The divisions the confirmed rows will write to, in canonical order.
     * One file may carry several — a high school's Junior High and Senior
     * High figures at once — so the confirmation names every one.
     *
     * @var array<int, string>
     */
    public array $bulkApplyDivisions = [];

    /**
     * The finished apply's report, while its result screen is showing.
     *
     * @var array{processed: int, created: int, updated: int, cleared: int, unchanged: int, cleared_certification: int, skipped: int, errors: array<int, array{line: int, school_id: string, error: string}>}|null
     */
    public ?array $bulkResult = null;

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

    /** The financial division these allotments belong to. */
    private function division(): string
    {
        return $this->user()->activeDivision();
    }

    /** The divisions the account may write to; a bulk row may name any of them. */
    private function allowedDivisions(): array
    {
        return $this->user()->authorizedDivisions();
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

        // A bulk preview belongs to one year's figures: a year change throws
        // it away rather than letting it apply against another year.
        $this->resetBulk();

        // The overview is keyed off the year; forgetting it is what makes the
        // strip re-read for the year that was picked.
        unset($this->overview);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSchoolFilter(): void
    {
        // A crafted value filters nothing — it must not silently leave the
        // select showing an option the table is not applying.
        if (! in_array($this->schoolFilter, self::SCHOOL_FILTERS, true)) {
            $this->schoolFilter = 'all';
        }

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

        $this->validateOnly($field, $this->amountRules($field), $this->amountMessages($field));

        $raw = $this->amounts[$schoolId] ?? null;

        $division = $this->division();

        $existing = $this->findAllotment($school->id, $division);

        $result = $this->persistAmount($user, $division, (int) $this->year, $school, $existing, $raw);

        $this->certifyingSchoolId = null;
        unset($this->overview);
        $this->errorMessage = null;

        if ($result['amount'] === null) {
            unset($this->amounts[$schoolId]);
            $this->successMessage = 'Allotment cleared — '.$school->name
                .' has no CY '.$this->year.' allotment on record.';

            return;
        }

        // Echo the stored figure back so the field shows exactly what the
        // database now holds (2 decimals, no thousands separators).
        $this->amounts[$schoolId] = number_format($result['amount'], 2, '.', '');
        $this->successMessage = 'Allotment saved — '.$school->name.' may consume '
            .number_format($result['amount'], 2).' in CY '.$this->year.'.'
            .($result['cleared_certification']
                ? ' The previous certification was cleared because the amount changed.'
                : '');
    }

    /**
     * Save every amount the officer has entered, in one action — the page's
     * whole reason for being a table: type the figures down the column, then
     * press one button instead of a Save per row.
     *
     * Only rows that actually differ from what is stored are written, so a
     * sweep with nothing edited changes nothing at all (and re-stamps no
     * one's "set by"). A row that fails validation is reported and left
     * alone; the rest still land.
     */
    public function saveAll(): void
    {
        $user = $this->user();

        if (! $this->validYear()) {
            $this->errorMessage = 'Choose a valid budget year before saving.';

            return;
        }

        $division = $this->division();
        $year = (int) $this->year;

        // Everything on the officer's screen: the year's stored amounts plus
        // any row they typed into — including rows left on another page.
        $schoolIds = array_values(array_unique(array_map('intval', array_keys($this->amounts))));

        if ($schoolIds === []) {
            $this->successMessage = 'Nothing to save — no amounts have been entered yet.';

            return;
        }

        $schools = School::query()->whereIn('id', $schoolIds)->get()->keyBy('id');

        $stored = SchoolAllotment::query()
            ->where('year', $year)
            ->where('school_division', $division)
            ->whereIn('school_id', $schoolIds)
            ->get()
            ->keyBy('school_id');

        $created = 0;
        $updated = 0;
        $cleared = 0;
        $clearedCertification = 0;
        $failed = 0;

        foreach ($schoolIds as $schoolId) {
            $school = $schools->get($schoolId);

            if ($school === null) {
                continue;
            }

            $raw = $this->amounts[$schoolId] ?? null;
            $record = $stored->get($schoolId);
            $raw = $raw === null ? null : trim((string) $raw);

            // The row already holds this figure (or is blank with nothing on
            // record): there is nothing to write. A figure typed in another
            // spelling ("2000000" for 2000000.00) is still no change — the
            // stored form is echoed back and the row is left untouched.
            if ($raw === null || $raw === '') {
                if ($record === null) {
                    continue;
                }
            } elseif ($record !== null && is_numeric($raw)
                && round((float) $raw, 2) === round((float) $record->amount, 2)) {
                $this->amounts[$schoolId] = number_format((float) $record->amount, 2, '.', '');

                continue;
            }

            $field = 'amounts.'.$schoolId;

            // Validated per row with the same rules the single-row Save uses.
            // Livewire's own validateOnly() would abort the whole sweep on the
            // first bad row; a sweep reports the bad rows and writes the sound
            // ones, so the failure is caught and put on the field instead.
            $validator = Validator::make(
                ['amount' => $raw],
                $this->amountRules('amount'),
                $this->amountMessages('amount'),
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->get('amount') as $message) {
                    $this->addError($field, $message);
                }

                $failed++;

                continue;
            }

            $result = $this->persistAmount($user, $division, $year, $school, $record, $raw);

            match ($result['status']) {
                'created' => $created++,
                'updated' => $updated++,
                'cleared' => $cleared++,
                default => null,
            };

            if ($result['cleared_certification']) {
                $clearedCertification++;
            }

            $this->amounts[$schoolId] = $result['amount'] === null
                ? ''
                : number_format($result['amount'], 2, '.', '');
        }

        $this->certifyingSchoolId = null;
        unset($this->overview);

        $saved = $created + $updated + $cleared;

        if ($failed > 0) {
            $this->errorMessage = $failed.' row(s) could not be saved — see the field errors below.';
        } else {
            $this->errorMessage = null;
        }

        $this->successMessage = $this->saveAllSummary($saved, $created, $updated, $cleared, $clearedCertification);
    }

    /** The one-line account of a Save-all sweep, for the toast. */
    private function saveAllSummary(int $saved, int $created, int $updated, int $cleared, int $clearedCertification): string
    {
        if ($saved === 0) {
            return 'Nothing to save — every amount already matches what is stored.';
        }

        $parts = [];

        if ($created > 0) {
            $parts[] = $created.' set';
        }

        if ($updated > 0) {
            $parts[] = $updated.' updated';
        }

        if ($cleared > 0) {
            $parts[] = $cleared.' cleared';
        }

        $summary = 'Saved — '.implode(', ', $parts).' for CY '.$this->year.'.';

        if ($clearedCertification > 0) {
            $summary .= ' '.$clearedCertification
                .($clearedCertification === 1 ? ' certification was' : ' certifications were')
                .' cleared and must be given again.';
        }

        return $summary;
    }

    /**
     * Write one school's amount for the year and division — the shared core of
     * the single-row Save and the Save-all sweep, so the two can never
     * disagree about rounding, certification or the audit trail. The caller
     * has already validated the raw value.
     *
     * @return array{status: 'created'|'updated'|'cleared'|'unchanged', amount: ?float, cleared_certification: bool}
     */
    private function persistAmount(
        User $user,
        string $division,
        int $year,
        School $school,
        ?SchoolAllotment $existing,
        ?string $raw,
    ): array {
        if ($raw === null || trim($raw) === '') {
            if ($existing === null) {
                return ['status' => 'unchanged', 'amount' => null, 'cleared_certification' => false];
            }

            $previousAmount = (float) $existing->amount;
            $wasCertified = $existing->isCertified();

            $existing->delete();

            SchoolAllotmentAudit::record(
                (int) $school->id,
                $year,
                $user,
                SchoolAllotmentAudit::ACTION_CLEAR,
                $previousAmount,
                null,
                SchoolAllotmentAudit::SOURCE_SINGLE,
                $division,
            );

            return ['status' => 'cleared', 'amount' => null, 'cleared_certification' => $wasCertified];
        }

        $amount = round((float) $raw, 2);

        // A certification is about one amount: changing the amount makes the
        // old certification untrue, so it is cleared and must be given again.
        // Re-saving the same figure keeps it, so a typo fix elsewhere does not
        // silently strip a valid certification.
        $previousAmount = $existing !== null ? (float) $existing->amount : null;
        $amountChanged = $existing === null || $previousAmount !== $amount;
        $wasCertified = $existing?->isCertified() ?? false;

        $attributes = ['amount' => $amount, 'set_by' => $user->id];

        if ($amountChanged) {
            $attributes['certified_by'] = null;
            $attributes['certified_at'] = null;
        }

        SchoolAllotment::query()->updateOrCreate(
            [
                'school_id' => $school->id,
                'school_division' => $division,
                'year' => $year,
            ],
            $attributes,
        );

        // Only a change is a change to record: re-stating the same figure
        // leaves the amount — and its trail — exactly as it was.
        if ($amountChanged) {
            SchoolAllotmentAudit::record(
                (int) $school->id,
                $year,
                $user,
                SchoolAllotmentAudit::ACTION_SET,
                $previousAmount,
                $amount,
                SchoolAllotmentAudit::SOURCE_SINGLE,
                $division,
            );
        }

        return [
            'status' => $amountChanged
                ? ($previousAmount === null ? 'created' : 'updated')
                : 'unchanged',
            'amount' => $amount,
            'cleared_certification' => $amountChanged && $wasCertified,
        ];
    }

    /** The stored allotment for one school, year and division. */
    private function findAllotment(int $schoolId, string $division): ?SchoolAllotment
    {
        if (! $this->validYear()) {
            return null;
        }

        return SchoolAllotment::query()
            ->where('school_id', $schoolId)
            ->where('school_division', $division)
            ->where('year', (int) $this->year)
            ->first();
    }

    /**
     * The validation rules and messages for one amount field, shared by the
     * single-row Save and the Save-all sweep.
     *
     * @return array<string, array<int, string>>
     */
    private function amountRules(string $field): array
    {
        return [$field => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99']];
    }

    /**
     * @return array<string, string>
     */
    private function amountMessages(string $field): array
    {
        return [
            $field.'.numeric' => 'Enter the allotment as a number.',
            $field.'.min' => 'The allotment cannot be negative.',
            $field.'.max' => 'The allotment is too large to record.',
        ];
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
            ->where('school_division', $this->division())
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

    /* -------------------------------------------------------- bulk allotments */

    public function openBulk(): void
    {
        $this->user();

        $this->resetBulk();
        $this->showBulk = true;
    }

    public function closeBulk(): void
    {
        $this->showBulk = false;
        $this->resetBulk();
    }

    /** The preview belongs to the mode that produced it; switching drops it. */
    public function updatedBulkMode(): void
    {
        $this->user();

        $this->resetBulkAnalysis();
    }

    /**
     * A CSV was picked: validate the upload. Nothing is analysed yet — the
     * Validate button reads the file.
     */
    public function updatedBulkFile(): void
    {
        $this->user();

        $this->resetBulkAnalysis();

        $maxKb = SchoolAllotmentImporter::MAX_SIZE_KB;

        $this->validate([
            'bulkFile' => [
                'required', 'file', 'max:'.$maxKb,
                'extensions:'.SchoolAllotmentImporter::EXTENSIONS,
            ],
        ], [
            'bulkFile.required' => 'Choose a CSV file to import.',
            'bulkFile.extensions' => 'The import must be a .csv file.',
            'bulkFile.max' => 'The file is too large ('.round($maxKb / 1024, 1).' MB maximum).',
        ]);
    }

    /** Flipping the blank-amount rule re-reads every row's outcome. */
    public function updatedBulkBlankClears(): void
    {
        $this->user();

        if ($this->bulkRows === [] && $this->bulkError === null) {
            return;
        }

        $this->reanalyzeBulk(sourceChanged: false);
    }

    /**
     * Validate the active source and show the preview. Nothing is written
     * here — the preview is what the Budget officer confirms.
     */
    public function reviewBulk(): void
    {
        $this->user();

        $this->resetBulkAnalysis();

        if (! $this->validYear()) {
            $this->bulkError = 'Choose a valid budget year before setting allotments.';

            return;
        }

        $this->reanalyzeBulk(sourceChanged: true);
    }

    /**
     * Ask before applying: the confirmation restates how many rows will be
     * set or cleared, and how many certifications the change ends. Counts are
     * read from a fresh analysis, never from what the browser sent.
     */
    public function confirmBulk(): void
    {
        $this->user();

        $this->bulkError = null;

        if ($this->bulkRows === []) {
            $this->bulkError = 'Validate the rows before applying them.';

            return;
        }

        $analysis = $this->analyzeBulkSource();

        if ($analysis === null) {
            return;
        }

        $counts = SchoolAllotmentImporter::selectionCounts($analysis['rows'], $this->bulkExcludedLines());

        if ($counts['selected'] === 0) {
            $this->bulkError = 'Every valid row is unchecked — there is nothing to apply.';

            return;
        }

        $this->bulkApplyCount = $counts['selected'];
        $this->bulkApplyDivisions = $counts['divisions'];
        $this->bulkApplyClearCertificationCount = $counts['clears_certification'];
        $this->bulkConfirming = true;
    }

    public function cancelBulkConfirm(): void
    {
        $this->bulkConfirming = false;
    }

    /**
     * Apply the confirmed rows. The source is analyzed again here — its rows
     * are never trusted from the client — and only the rows the officer left
     * checked are written. The whole apply is one transaction: it either lands
     * in full or not at all.
     */
    public function commitBulk(): void
    {
        $user = $this->user();

        $this->bulkError = null;

        if (! $this->bulkConfirming) {
            $this->bulkError = 'Confirm the bulk change before applying it.';

            return;
        }

        if (! $this->validYear()) {
            $this->bulkConfirming = false;
            $this->bulkError = 'Choose a valid budget year before setting allotments.';

            return;
        }

        $analysis = $this->analyzeBulkSource();

        if ($analysis === null) {
            $this->bulkConfirming = false;

            return;
        }

        $excluded = $this->bulkExcludedLines();

        $report = SchoolAllotmentImporter::commit(
            $analysis['rows'],
            $excluded,
            (int) $this->year,
            $user,
            $this->division(),
            $this->allowedDivisions(),
        );

        // Every row that was not applied — invalid, superseded, unchecked or
        // refused at commit time — belongs in the downloadable report, each
        // under the division it was headed for.
        $errors = $report['errors'];

        foreach ($analysis['rows'] as $row) {
            $line = (int) $row['line'];
            $rowDivision = (string) ($row['school_division'] ?? '');

            if ($row['superseded']) {
                $errors[] = [
                    'line' => $line,
                    'school_id' => (string) $row['school_id'],
                    'school_division' => $rowDivision,
                    'error' => 'Superseded by row '.$row['superseded_line'].' — the last occurrence wins.',
                ];
            } elseif (! $row['valid']) {
                $errors[] = [
                    'line' => $line,
                    'school_id' => (string) $row['school_id'],
                    'school_division' => $rowDivision,
                    'error' => implode(' ', $row['errors']),
                ];
            } elseif (in_array($line, $excluded, true)) {
                $errors[] = [
                    'line' => $line,
                    'school_id' => (string) $row['school_id'],
                    'school_division' => $rowDivision,
                    'error' => 'Unchecked before applying.',
                ];
            }
        }

        usort($errors, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        $report['errors'] = $errors;

        $this->bulkResult = $report;
        $this->showBulk = $errors !== [];
        $this->bulkRows = [];
        $this->bulkIncluded = [];
        $this->bulkText = '';
        $this->bulkFile = null;
        $this->bulkConfirming = false;
        $this->bulkTotalRows = 0;
        $this->bulkReadyCount = 0;
        $this->bulkInvalidCount = 0;
        $this->bulkSupersededCount = 0;
        $this->bulkClearCertificationCount = 0;
        $this->bulkApplyCount = 0;
        $this->bulkApplyDivisions = [];
        $this->bulkApplyClearCertificationCount = 0;

        $this->loadAmounts();
        unset($this->overview);

        $this->successMessage = $this->bulkSummary($report);
    }

    /**
     * Download the schools currently listed — the search and Show filters
     * applied, every page — with their amounts, as a CSV.
     *
     * The columns are the ones the bulk import reads back, so the file
     * doubles as the bulk template: export it, fill the blanks in a
     * spreadsheet, and upload the same file through Bulk Set Allotments.
     */
    public function exportAllotments()
    {
        $this->user();

        if (! $this->validYear()) {
            $this->errorMessage = 'Choose a valid budget year before downloading.';

            return null;
        }

        $division = $this->division();
        $year = (int) $this->year;

        $schools = $this->filteredSchools()
            ->orderBy('name')
            ->get(['id', 'name', 'school_id']);

        if ($schools->isEmpty()) {
            $this->errorMessage = 'There are no schools to download with the current search and filter.';

            return null;
        }

        $amounts = SchoolAllotment::query()
            ->where('year', $year)
            ->where('school_division', $division)
            ->whereIn('school_id', $schools->pluck('id'))
            ->pluck('amount', 'school_id');

        $rows = $schools->map(fn (School $school): array => [
            'school_id' => (string) ($school->school_id ?? ''),
            'school_name' => (string) $school->name,
            'school_division' => $division,
            'amount' => isset($amounts[$school->id]) ? (float) $amounts[$school->id] : null,
        ])->all();

        $csv = SchoolAllotmentImporter::exportCsv($rows);

        return response()->streamDownload(
            fn () => print ($csv),
            'school-allotments-cy'.$this->year.'-'.Str::slug(SchoolDivision::label($division)).'.csv',
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Length' => (string) strlen($csv),
            ],
        );
    }

    /** The error report for the finished apply, as a download. */
    public function downloadBulkErrorReport()
    {
        $this->user();

        $errors = (array) ($this->bulkResult['errors'] ?? []);

        if ($errors === []) {
            return null;
        }

        $csv = SchoolAllotmentImporter::errorReportCsv($errors);

        return response()->streamDownload(
            fn () => print ($csv),
            'school-allotments-bulk-errors-cy'.$this->year.'.csv',
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Length' => (string) strlen($csv),
            ],
        );
    }

    /**
     * Analyze whichever source is active and show its preview, or set the
     * error the officer must read. The rows are re-read server-side every
     * time; nothing the browser holds is trusted.
     */
    private function reanalyzeBulk(bool $sourceChanged): void
    {
        $analysis = $this->analyzeBulkSource();

        if ($analysis === null) {
            return;
        }

        if ($analysis['rows'] === []) {
            if ($sourceChanged) {
                $this->bulkError = $this->bulkMode === 'csv'
                    ? 'The file has no data rows.'
                    : 'No rows were found in the pasted list.';
            }

            return;
        }

        $this->applyBulkAnalysis($analysis);
    }

    /**
     * The current source, analyzed. Null (with the error set) when the source
     * itself is missing or unreadable.
     *
     * @return array{rows: array<int, array<string, mixed>>, readyCount: int, invalidCount: int, supersededCount: int, clearsCertificationCount: int}|null
     */
    private function analyzeBulkSource(): ?array
    {
        try {
            if ($this->bulkMode === 'csv') {
                if ($this->bulkFile === null) {
                    $this->bulkError = 'Choose a CSV file to import.';

                    return null;
                }

                return SchoolAllotmentImporter::analyzeCsv(
                    $this->bulkFile->getRealPath(),
                    (int) $this->year,
                    $this->bulkBlankClears,
                    $this->division(),
                    $this->allowedDivisions(),
                );
            }

            $text = trim($this->bulkText);

            if ($text === '') {
                $this->bulkError = 'Paste the rows to set — one School ID and amount per line.';

                return null;
            }

            return SchoolAllotmentImporter::analyzeText(
                $text,
                (int) $this->year,
                $this->bulkBlankClears,
                $this->division(),
                $this->allowedDivisions(),
            );
        } catch (RuntimeException $exception) {
            if ($this->bulkMode === 'csv') {
                $this->bulkFile = null;
            }

            $this->bulkError = $exception->getMessage();

            return null;
        }
    }

    /**
     * @param  array{rows: array<int, array<string, mixed>>, readyCount: int, invalidCount: int, supersededCount: int, clearsCertificationCount: int}  $analysis
     */
    private function applyBulkAnalysis(array $analysis): void
    {
        $this->bulkTotalRows = count($analysis['rows']);
        $this->bulkRows = array_slice($analysis['rows'], 0, self::BULK_PREVIEW_LIMIT);
        $this->bulkReadyCount = $analysis['readyCount'];
        $this->bulkInvalidCount = $analysis['invalidCount'];
        $this->bulkSupersededCount = $analysis['supersededCount'];
        $this->bulkClearCertificationCount = $analysis['clearsCertificationCount'];

        // Every row starts checked; a row whose key is absent (one beyond the
        // preview) stays checked by the same default.
        $this->bulkIncluded = [];

        foreach ($this->bulkRows as $row) {
            $this->bulkIncluded[$row['line']] = true;
        }
    }

    /**
     * The lines the officer unchecked. Anything not recorded as unchecked is
     * included.
     *
     * @return array<int, int>
     */
    private function bulkExcludedLines(): array
    {
        $excluded = [];

        foreach ($this->bulkIncluded as $line => $included) {
            if (! filter_var($included, FILTER_VALIDATE_BOOLEAN)) {
                $excluded[] = (int) $line;
            }
        }

        return $excluded;
    }

    private function resetBulk(): void
    {
        $this->showBulk = false;
        $this->bulkMode = 'paste';
        $this->bulkText = '';
        $this->bulkFile = null;
        $this->bulkBlankClears = false;
        $this->bulkResult = null;
        $this->resetBulkAnalysis();
        $this->resetValidation('bulkFile');
    }

    private function resetBulkAnalysis(): void
    {
        $this->bulkRows = [];
        $this->bulkIncluded = [];
        $this->bulkTotalRows = 0;
        $this->bulkReadyCount = 0;
        $this->bulkInvalidCount = 0;
        $this->bulkSupersededCount = 0;
        $this->bulkClearCertificationCount = 0;
        $this->bulkError = null;
        $this->bulkConfirming = false;
        $this->bulkApplyCount = 0;
        $this->bulkApplyDivisions = [];
        $this->bulkApplyClearCertificationCount = 0;
    }

    /**
     * The one-line account of a finished apply, for the toast.
     *
     * @param  array{processed: int, created: int, updated: int, cleared: int, unchanged: int, cleared_certification: int, skipped: int, errors: array<int, array{line: int, school_id: string, error: string}>}  $report
     */
    private function bulkSummary(array $report): string
    {
        $parts = [];

        if ($report['created'] > 0) {
            $parts[] = $report['created'].' set';
        }

        if ($report['updated'] > 0) {
            $parts[] = $report['updated'].' updated';
        }

        if ($report['cleared'] > 0) {
            $parts[] = $report['cleared'].' cleared';
        }

        if ($report['unchanged'] > 0) {
            $parts[] = $report['unchanged'].' already at that amount';
        }

        if ($report['processed'] === 0) {
            $summary = 'No allotments changed';

            if ($report['unchanged'] > 0) {
                $summary .= ' — '.$report['unchanged'].' row(s) already held that amount';
            }
        } else {
            $summary = 'Applied — '.implode(', ', $parts);
        }

        if ($report['cleared_certification'] > 0) {
            $summary .= '. '.$report['cleared_certification']
                .($report['cleared_certification'] === 1 ? ' certification was' : ' certifications were')
                .' cleared and must be given again.';
        } else {
            $summary .= '.';
        }

        if ($report['errors'] !== []) {
            $summary .= ' '.count($report['errors']).' row(s) could not be applied — see the report.';
        }

        return $summary;
    }

    /**
     * The readable list of the divisions the confirmed apply will write to —
     * "Senior High School", or "Elementary and Senior High School" when one
     * file spans several. Read from the fresh analysis the confirmation
     * restates, never from the browser.
     */
    #[Computed]
    public function bulkApplyDivisionLabel(): string
    {
        $summary = SchoolAllotmentImporter::divisionSummary($this->bulkApplyDivisions);

        return $summary !== '' ? $summary : SchoolDivision::label($this->division());
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
            ->where('school_division', $this->division())
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
        $division = $this->division();

        $years = collect([
            now()->year - 1,
            now()->year,
            now()->year + 1,
            now()->year + 2,
        ])
            ->merge(SchoolAllotment::query()->where('school_division', $division)->distinct()->pluck('year'))
            ->merge(MonthlyExpenditureMatrix::query()->where('school_division', $division)->distinct()->pluck('year'))
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
            $this->validYear() ? (int) $this->year : (int) now()->year,
            $this->division(),
        );
    }

    /**
     * The slices of the roster the "Show" select offers, in the order it
     * lists them. Used by the view and by the empty-state message, so the two
     * can never describe the same filter differently.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function schoolFilterOptions(): array
    {
        return [
            'all' => 'All schools',
            'with_head' => 'With a School Head in this division',
            'without_allotment' => 'Missing an allotment',
            'with_allotment' => 'With an allotment set',
        ];
    }

    /**
     * The schools, with their School Head account(s), filtered by the search
     * box and the Show select. The consumption and allotment figures are
     * joined in render() so they are read once for the whole page rather than
     * once per row.
     */
    #[Computed]
    public function schools(): LengthAwarePaginator
    {
        $schools = $this->filteredSchools()
            ->with([
                'district',
                'users' => fn ($query) => $query
                    ->where('role', 'user')
                    ->orderBy('name')
                    ->orderBy('username'),
            ])
            ->orderBy('name')
            ->paginate($this->perPage);

        // Only this division's School Heads are named against a school.
        $division = $this->division();

        $schools->getCollection()->transform(function (School $school) use ($division): School {
            $school->setRelation(
                'users',
                $school->users->filter(fn (User $user): bool => $user->canAccessDivision($division))->values(),
            );

            return $school;
        });

        return $schools;
    }

    /**
     * The roster the search box and the Show select between them — the one
     * definition the paginated table and the CSV download share, so the file
     * always carries exactly what the officer is looking at.
     */
    private function filteredSchools(): Builder
    {
        $term = trim($this->search);
        $division = $this->division();
        $year = $this->validYear() ? (int) $this->year : (int) now()->year;

        return School::query()
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
            ->when($this->schoolFilter === 'with_head', function ($query) use ($division): void {
                $query->whereIn('id', $this->schoolIdsWithHead($division));
            })
            ->when(
                in_array($this->schoolFilter, ['with_allotment', 'without_allotment'], true),
                function ($query) use ($division, $year): void {
                    $withAllotment = SchoolAllotment::query()
                        ->where('year', $year)
                        ->where('school_division', $division)
                        ->pluck('school_id');

                    $this->schoolFilter === 'with_allotment'
                        ? $query->whereIn('id', $withAllotment)
                        : $query->whereNotIn('id', $withAllotment);
                },
            );
    }

    /**
     * The schools whose School Head account(s) can work this division — the
     * roster that will actually consume the money being allotted. The
     * division each account may work is derived from its classification, so
     * the match is decided account by account rather than in SQL.
     *
     * @return array<int, int>
     */
    private function schoolIdsWithHead(string $division): array
    {
        return User::query()
            ->where('role', 'user')
            ->whereNotNull('school_id')
            ->get(['id', 'school_id', 'role', 'is_superadmin', 'school_level', 'school_sublevel', 'division_access'])
            ->filter(fn (User $user): bool => $user->canAccessDivision($division))
            ->pluck('school_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
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

        $division = $this->division();

        $allotments = SchoolAllotment::query()
            ->with(['setter', 'certifier'])
            ->where('year', $year)
            ->where('school_division', $division)
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
                ->where('school_division', $division)
                ->where('year', $year)
                ->first();

        // The modal's consumption is read for its own school rather than from
        // the page's map: the school must not read as zero just because a
        // later search or page change moved its row off the visible page.
        $certifyingSpent = $certifyingSchool === null
            ? 0.0
            : (float) (WfpAllotment::consumedBySchool([$certifyingSchool->id], $year, $division)[$certifyingSchool->id] ?? 0.0);

        return view('livewire.budget.school-allotments', [
            'schools' => $schools,
            'allotments' => $allotments,
            'consumed' => WfpAllotment::consumedBySchool($schoolIds, $year, $division),
            'overview' => $this->overview,
            'canCertify' => $this->user()->canCertifyAllotments(),
            'certifyingSchool' => $certifyingSchool,
            'certifyingAllotment' => $certifyingAllotment,
            'certifyingSpent' => $certifyingSpent,
            'bulkSelectedCount' => $this->bulkSelectedCount(),
            'divisionLabel' => SchoolDivision::label($division),
        ]);
    }

    /**
     * How many rows the apply button would write: every ready row, less the
     * preview rows the officer unchecked. Rows beyond the preview are not in
     * the list at all, so they stay counted — they are still checked.
     */
    private function bulkSelectedCount(): int
    {
        $unchecked = 0;

        foreach ($this->bulkRows as $row) {
            if (! $row['valid'] || $row['superseded']) {
                continue;
            }

            if (isset($this->bulkIncluded[$row['line']])
                && ! filter_var($this->bulkIncluded[$row['line']], FILTER_VALIDATE_BOOLEAN)) {
                $unchecked++;
            }
        }

        return max(0, $this->bulkReadyCount - $unchecked);
    }
}
