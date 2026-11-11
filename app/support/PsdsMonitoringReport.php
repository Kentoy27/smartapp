<?php

namespace App\Support;

use App\Models\AipPlan;
use App\Models\District;
use App\Models\OpcrfSubmission;
use App\Models\School;
use App\Models\User;
use App\Models\WfpPlan;
use App\Models\WfpSubmission;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The PSDS's read-only district submission report.
 *
 * Everything this class reads is scoped by the signed-in account's own
 * district list (`user_district_assignments`) — never by a district or school
 * id carried in the request. A district filter naming a district the account
 * is not assigned narrows the result to nothing rather than widening it, and
 * `schoolDetail()` refuses a school outside that reach outright.
 *
 * The report maps each school's three documents to one of four monitoring
 * states:
 *
 *   submitted       — filed and somewhere in (or past) its review workflow
 *   needs_revision  — returned to the school for revision
 *   in_progress     — a record exists but has never been submitted
 *   not_submitted   — no record for the period
 *
 * AIP is read from `aip_plans` (one plan per school and year), OPCRF from
 * `opcrf_submissions` through the submitting account's school (the form has no
 * year column; its reporting year is parsed from `review_period`), and WFP
 * from `wfp_plans`, falling back to a `wfp_submissions` workbook where no
 * formal plan exists. The formal WFP plan always wins over an upload.
 *
 * The component and the CSV export both call this one class, so the screen
 * and the file can never disagree about a school's status.
 */
final class PsdsMonitoringReport
{
    public const DOC_SUBMITTED = 'submitted';

    public const DOC_NEEDS_REVISION = 'needs_revision';

    public const DOC_IN_PROGRESS = 'in_progress';

    public const DOC_NOT_SUBMITTED = 'not_submitted';

    public const OVERALL_COMPLETE = 'complete';

    public const OVERALL_PARTIALLY_SUBMITTED = 'partially_submitted';

    public const OVERALL_NEEDS_REVISION = 'needs_revision';

    public const OVERALL_IN_PROGRESS = 'in_progress';

    public const OVERALL_NOT_STARTED = 'not_started';

    /** The document states a filter may name. */
    private const DOC_STATUSES = [
        self::DOC_SUBMITTED,
        self::DOC_NEEDS_REVISION,
        self::DOC_IN_PROGRESS,
        self::DOC_NOT_SUBMITTED,
    ];

    /** The overall states a filter may name. */
    private const OVERALL_STATUSES = [
        self::OVERALL_COMPLETE,
        self::OVERALL_PARTIALLY_SUBMITTED,
        self::OVERALL_NEEDS_REVISION,
        self::OVERALL_IN_PROGRESS,
        self::OVERALL_NOT_STARTED,
    ];

    private const DOCUMENTS = ['aip', 'opcrf', 'wfp'];

    /** @var array<int, int>|null */
    private ?array $districtIdsCache = null;

    /** @var array<int, int>|null */
    private ?array $schoolIdsCache = null;

    public function __construct(private readonly User $psds) {}

    /* ----------------------------------------------------------- scope */

    /** The districts this account is assigned to. */
    public function districtIds(): array
    {
        return $this->districtIdsCache ??= $this->psds->assignedDistrictIds();
    }

    /**
     * The assigned districts, in district-name order, each with its school
     * count. The roster is the account's own list — a request cannot add to
     * it.
     *
     * @return Collection<int, District>
     */
    public function districts(): Collection
    {
        $ids = $this->districtIds();

        if ($ids === []) {
            return collect();
        }

        return District::query()
            ->withCount('schools')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Every school currently sitting in one of the assigned districts.
     *
     * District membership is read fresh from the school's own `district_id`,
     * so a school transferred into an assigned district appears without any
     * per-school assignment, and one transferred out disappears.
     *
     * @return Collection<int, School>
     */
    public function schools(?int $districtId = null): Collection
    {
        $ids = $this->districtIds();

        if ($ids === []) {
            return collect();
        }

        return School::query()
            ->with('district:id,name')
            ->whereIn('district_id', $ids)
            ->when($districtId !== null, fn ($query) => $query->where('district_id', $districtId))
            ->orderBy('name')
            ->get(['id', 'district_id', 'name', 'school_id']);
    }

    /**
     * The reporting years the account can choose from: every year named by an
     * AIP plan or WFP plan/upload in reach, every year parsed from an OPCRF
     * review period, and the current year so the default is always offered.
     *
     * @return array<int, int>
     */
    public function yearOptions(): array
    {
        $schoolIds = $this->schoolIds();
        $years = collect([(int) now()->year]);

        if ($schoolIds !== []) {
            $years = $years
                ->merge(AipPlan::query()->whereIn('school_id', $schoolIds)->pluck('year'))
                ->merge(WfpPlan::query()->whereIn('school_id', $schoolIds)->pluck('year'))
                ->merge(
                    OpcrfSubmission::query()
                        ->whereHas('user', fn ($query) => $this->scopeSchoolHead($query, $schoolIds))
                        ->pluck('review_period')
                        ->map(fn ($period): ?int => self::yearFromText($period))
                )
                ->merge(
                    WfpSubmission::query()
                        ->whereHas('user', fn ($query) => $this->scopeSchoolHead($query, $schoolIds))
                        ->pluck('school_year')
                        ->map(fn ($period): ?int => self::yearFromText($period))
                );
        }

        return $years
            ->map(fn ($year): int => (int) $year)
            ->filter(fn (int $year): bool => $year >= 2000 && $year <= 2100)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /* ------------------------------------------------------- dashboard */

    /**
     * Build the page in one pass.
     *
     * `scope_rows` is the district/school/year/search scope before the
     * document-status filters. The summary and district coverage read it, so
     * narrowing the table to "not submitted" does not rewrite the counts
     * beside it. `rows` is the filtered table.
     *
     * @param  array<string, mixed>  $filters
     * @return array{scope_rows: Collection<int, array<string, mixed>>, rows: Collection<int, array<string, mixed>>, summary: array<string, mixed>, coverage: Collection<int, array<string, mixed>>}
     */
    public function dashboard(array $filters): array
    {
        $filters = self::normalizeFilters($filters);

        $schools = $this->schools($filters['district_id']);

        if ($filters['school_id'] !== null) {
            $schools = $schools
                ->where('id', $filters['school_id'])
                ->values();
        }

        if ($filters['search'] !== '') {
            $needle = mb_strtolower($filters['search']);

            $schools = $schools
                ->filter(fn (School $school): bool => str_contains(mb_strtolower((string) $school->name), $needle)
                    || str_contains(mb_strtolower((string) ($school->school_id ?? '')), $needle))
                ->values();
        }

        $scopeRows = $this->buildRows($schools, $filters['year']);

        return [
            'scope_rows' => $scopeRows,
            'rows' => $this->applyStatusFilters($scopeRows, $filters),
            'summary' => $this->summarize($scopeRows),
            'coverage' => $this->coverage($scopeRows, $filters['district_id']),
        ];
    }

    /**
     * One school's full read-only record: the same statuses the table shows,
     * plus the document metadata and every review/approval trail available.
     *
     * Returns null for a school outside the account's assigned districts —
     * an unassigned id is a request for data, not a permission.
     *
     * @return array<string, mixed>|null
     */
    public function schoolDetail(int $schoolId, ?int $year): ?array
    {
        if (! $this->psds->canMonitorSchool($schoolId)) {
            return null;
        }

        $school = School::query()
            ->with('district:id,name')
            ->find($schoolId);

        if ($school === null) {
            return null;
        }

        $row = $this->buildRows(collect([$school]), $year)->first();

        if ($row === null) {
            return null;
        }

        return [
            'school' => $school,
            'year' => $year,
            'row' => $row,
            'aip' => $this->aipDetail($schoolId, $year),
            'opcrf' => $this->opcrfDetail($schoolId, $year),
            'wfp' => $this->wfpDetail($schoolId, $year),
        ];
    }

    /* ---------------------------------------------------------- export */

    /**
     * The read-only CSV for the current filters. The BOM makes Excel open
     * the school names as UTF-8 instead of mojibake.
     *
     * @param  array<string, mixed>  $filters
     */
    public function csv(array $filters): string
    {
        // dashboard() normalizes the raw filters itself. Normalizing here
        // first would hand it the normalized keys (district_id, school_id),
        // which it does not read — silently exporting every assigned school.
        $rows = $this->dashboard($filters)['rows'];
        $filters = self::normalizeFilters($filters);
        $period = $filters['year'] !== null ? 'CY '.$filters['year'] : 'All years';

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'Reporting Period',
            'District',
            'School Name',
            'School ID',
            'AIP Status',
            'AIP Submitted',
            'OPCRF Status',
            'OPCRF Submitted',
            'WFP Status',
            'WFP Submitted',
            'Overall Status',
        ], ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $period,
                $row['district_name'],
                $row['school_name'],
                $row['school_code'],
                $row['aip']['label'],
                $row['aip']['submitted_at']?->format('Y-m-d H:i') ?? '',
                $row['opcrf']['label'],
                $row['opcrf']['submitted_at']?->format('Y-m-d H:i') ?? '',
                $row['wfp']['label'],
                $row['wfp']['submitted_at']?->format('Y-m-d H:i') ?? '',
                $row['overall_label'],
            ], ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /* ------------------------------------------------------ row building */

    /**
     * @param  Collection<int, School>  $schools
     * @return Collection<int, array<string, mixed>>
     */
    private function buildRows(Collection $schools, ?int $year): Collection
    {
        if ($schools->isEmpty()) {
            return collect();
        }

        $schoolIds = $schools
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $aipPlans = $this->latestAipPlans($schoolIds, $year);
        $opcrfSubmissions = $this->opcrfSubmissionsFor($schoolIds, $year);
        $wfpPlans = $this->latestWfpPlans($schoolIds, $year);
        $wfpUploads = $this->wfpUploadsFor($schoolIds, $year);

        return $schools
            ->map(function (School $school) use ($aipPlans, $opcrfSubmissions, $wfpPlans, $wfpUploads): array {
                $aip = $this->aipCell($aipPlans->get($school->id));
                $opcrf = $this->opcrfCell($opcrfSubmissions->get($school->id) ?? collect());
                $wfp = $this->wfpCell($wfpPlans->get($school->id), $wfpUploads->get($school->id) ?? collect());
                $overall = $this->overallStatus([$aip['status'], $opcrf['status'], $wfp['status']]);

                return [
                    'school_id' => (int) $school->id,
                    'school_code' => (string) ($school->school_id ?? ''),
                    'school_name' => (string) $school->name,
                    'district_id' => (int) $school->district_id,
                    'district_name' => (string) ($school->district?->name ?? ''),
                    'aip' => $aip,
                    'opcrf' => $opcrf,
                    'wfp' => $wfp,
                    'overall_status' => $overall,
                    'overall_label' => self::overallStatusLabel($overall),
                    'overall_badge' => self::overallBadgeClass($overall),
                ];
            })
            ->values();
    }

    /**
     * The latest AIP plan per school: the selected year, or the newest year
     * on file when the report is showing all years.
     *
     * @param  array<int, int>  $schoolIds
     * @return Collection<int, AipPlan>
     */
    private function latestAipPlans(array $schoolIds, ?int $year): Collection
    {
        return AipPlan::query()
            ->whereIn('school_id', $schoolIds)
            ->when($year !== null, fn ($query) => $query->where('year', $year))
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->get()
            ->groupBy('school_id')
            ->map(fn (Collection $plans): ?AipPlan => $plans->first());
    }

    /**
     * Active OPCRF submissions grouped by the submitter's school.
     *
     * With no year selected, only the newest reporting year found for that
     * school is used: a returned 2024 form must not keep a school flagged
     * forever once its 2026 form is approved.
     *
     * @param  array<int, int>  $schoolIds
     * @return Collection<int, Collection<int, OpcrfSubmission>>
     */
    private function opcrfSubmissionsFor(array $schoolIds, ?int $year): Collection
    {
        $submissions = OpcrfSubmission::query()
            ->whereNull('archived_at')
            ->whereHas('user', fn ($query) => $this->scopeSchoolHead($query, $schoolIds))
            ->with('user:id,school_id')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        if ($year !== null) {
            $submissions = $submissions->filter(
                fn (OpcrfSubmission $submission): bool => self::opcrfYear($submission) === $year
            );
        }

        return $submissions
            ->groupBy(fn (OpcrfSubmission $submission): int => (int) $submission->user->school_id)
            ->map(function (Collection $schoolSubmissions) use ($year): Collection {
                if ($year === null) {
                    $latestYear = $schoolSubmissions
                        ->map(fn (OpcrfSubmission $submission): int => self::opcrfYear($submission))
                        ->max();

                    $schoolSubmissions = $schoolSubmissions->filter(
                        fn (OpcrfSubmission $submission): bool => self::opcrfYear($submission) === $latestYear
                    );
                }

                return $schoolSubmissions->values();
            });
    }

    /**
     * @param  array<int, int>  $schoolIds
     * @return Collection<int, WfpPlan>
     */
    private function latestWfpPlans(array $schoolIds, ?int $year): Collection
    {
        return WfpPlan::query()
            ->whereIn('school_id', $schoolIds)
            ->when($year !== null, fn ($query) => $query->where('year', $year))
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->get()
            ->groupBy('school_id')
            ->map(fn (Collection $plans): ?WfpPlan => $plans->first());
    }

    /**
     * Uploaded WFP workbooks, used only where no formal plan exists.
     *
     * @param  array<int, int>  $schoolIds
     * @return Collection<int, Collection<int, WfpSubmission>>
     */
    private function wfpUploadsFor(array $schoolIds, ?int $year): Collection
    {
        $uploads = WfpSubmission::query()
            ->whereHas('user', fn ($query) => $this->scopeSchoolHead($query, $schoolIds))
            ->with('user:id,school_id')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get();

        if ($year !== null) {
            $uploads = $uploads->filter(
                fn (WfpSubmission $upload): bool => self::wfpUploadYear($upload) === $year
            );
        }

        return $uploads
            ->groupBy(fn (WfpSubmission $upload): int => (int) $upload->user->school_id)
            ->map(function (Collection $schoolUploads) use ($year): Collection {
                if ($year === null) {
                    $latestYear = $schoolUploads
                        ->map(fn (WfpSubmission $upload): int => self::wfpUploadYear($upload))
                        ->max();

                    $schoolUploads = $schoolUploads->filter(
                        fn (WfpSubmission $upload): bool => self::wfpUploadYear($upload) === $latestYear
                    );
                }

                return $schoolUploads->values();
            });
    }

    /* ------------------------------------------------------------ cells */

    /** @return array<string, mixed> */
    private function aipCell(?AipPlan $plan): array
    {
        if ($plan === null) {
            return $this->notSubmittedCell();
        }

        $status = match ((string) $plan->status) {
            AipPlan::STATUS_DRAFT => self::DOC_IN_PROGRESS,
            AipPlan::STATUS_RETURNED => self::DOC_NEEDS_REVISION,
            default => self::DOC_SUBMITTED,
        };

        return $this->cell(
            $status,
            AipPlan::statusLabel((string) $plan->status),
            $plan->submitted_at,
            (int) $plan->year,
        );
    }

    /**
     * @param  Collection<int, OpcrfSubmission>  $submissions
     * @return array<string, mixed>
     */
    private function opcrfCell(Collection $submissions): array
    {
        if ($submissions->isEmpty()) {
            return $this->notSubmittedCell();
        }

        $returned = $submissions->first(
            fn (OpcrfSubmission $submission): bool => $submission->status === OpcrfSubmission::STATUS_RETURNED
        );
        $latest = $submissions
            ->sortByDesc(fn (OpcrfSubmission $submission): int => $submission->submitted_at?->getTimestamp() ?? 0)
            ->first();

        $rawLabel = $returned !== null
            ? OpcrfSubmission::statusLabel(OpcrfSubmission::STATUS_RETURNED)
            : OpcrfSubmission::statusLabel((string) $latest?->status);

        $submittedAt = $submissions
            ->pluck('submitted_at')
            ->filter()
            ->max();

        return $this->cell(
            $returned !== null ? self::DOC_NEEDS_REVISION : self::DOC_SUBMITTED,
            $rawLabel,
            $submittedAt,
            $latest !== null ? self::opcrfYear($latest) : null,
        );
    }

    /**
     * @param  Collection<int, WfpSubmission>  $uploads
     * @return array<string, mixed>
     */
    private function wfpCell(?WfpPlan $plan, Collection $uploads): array
    {
        // The formal plan is the record of truth; an upload is only evidence
        // that the school has filed a workbook where no plan row exists.
        if ($plan !== null) {
            $status = match ((string) $plan->status) {
                WfpPlan::STATUS_DRAFT, WfpPlan::STATUS_GENERATED => self::DOC_IN_PROGRESS,
                WfpPlan::STATUS_RETURNED => self::DOC_NEEDS_REVISION,
                default => self::DOC_SUBMITTED,
            };

            $submittedAt = $plan->submitted_at
                ?? ($plan->status === WfpPlan::STATUS_FINALIZED ? $plan->approved_at : null);

            return $this->cell(
                $status,
                WfpPlan::labelForStatus((string) $plan->status),
                $submittedAt,
                (int) $plan->year,
            );
        }

        if ($uploads->isNotEmpty()) {
            $latest = $uploads
                ->sortByDesc(fn (WfpSubmission $upload): int => ($upload->submitted_at ?? $upload->uploaded_at)?->getTimestamp() ?? 0)
                ->first();

            return $this->cell(
                self::DOC_SUBMITTED,
                'Workbook uploaded',
                $latest?->submitted_at ?? $latest?->uploaded_at,
                $latest !== null ? self::wfpUploadYear($latest) : null,
            );
        }

        return $this->notSubmittedCell();
    }

    /**
     * @return array{status: string, label: string, detail_label: ?string, submitted_at: ?CarbonInterface, year: ?int, badge: string}
     */
    private function cell(string $status, ?string $detailLabel, ?CarbonInterface $submittedAt, ?int $year): array
    {
        return [
            'status' => $status,
            'label' => self::docStatusLabel($status),
            'detail_label' => $detailLabel,
            'submitted_at' => $submittedAt,
            'year' => $year,
            'badge' => self::docBadgeClass($status),
        ];
    }

    /** @return array{status: string, label: string, detail_label: ?string, submitted_at: null, year: null, badge: string} */
    private function notSubmittedCell(): array
    {
        return $this->cell(self::DOC_NOT_SUBMITTED, null, null, null);
    }

    /* ---------------------------------------------------------- summary */

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarize(Collection $rows): array
    {
        $summary = [
            'schools' => $rows->count(),
            'aip' => $this->emptyDocCounts(),
            'opcrf' => $this->emptyDocCounts(),
            'wfp' => $this->emptyDocCounts(),
            'overall' => [
                self::OVERALL_COMPLETE => 0,
                self::OVERALL_PARTIALLY_SUBMITTED => 0,
                self::OVERALL_NEEDS_REVISION => 0,
                self::OVERALL_IN_PROGRESS => 0,
                self::OVERALL_NOT_STARTED => 0,
            ],
        ];

        foreach ($rows as $row) {
            foreach (self::DOCUMENTS as $document) {
                $summary[$document][$row[$document]['status']]++;
            }

            $summary['overall'][$row['overall_status']]++;
        }

        return $summary;
    }

    /** @return array<string, int> */
    private function emptyDocCounts(): array
    {
        return [
            self::DOC_SUBMITTED => 0,
            self::DOC_NEEDS_REVISION => 0,
            self::DOC_IN_PROGRESS => 0,
            self::DOC_NOT_SUBMITTED => 0,
        ];
    }

    /**
     * Per-district completeness for the schools in view.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function coverage(Collection $rows, ?int $districtId): Collection
    {
        return $this->districts()
            ->when($districtId !== null, fn (Collection $districts) => $districts->where('id', $districtId))
            ->map(function (District $district) use ($rows): array {
                $districtRows = $rows->where('district_id', (int) $district->id);
                $schools = $districtRows->count();
                $submitted = $districtRows->sum(function (array $row): int {
                    return count(array_filter(
                        self::DOCUMENTS,
                        fn (string $document): bool => $row[$document]['status'] === self::DOC_SUBMITTED,
                    ));
                });
                $expected = $schools * count(self::DOCUMENTS);

                return [
                    'district' => $district,
                    'schools' => $schools,
                    'submitted' => $submitted,
                    'expected' => $expected,
                    'percent' => $expected > 0 ? (int) round($submitted / $expected * 100) : 0,
                    'complete' => $districtRows->where('overall_status', self::OVERALL_COMPLETE)->count(),
                    'needs_revision' => $districtRows->where('overall_status', self::OVERALL_NEEDS_REVISION)->count(),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyStatusFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            ->filter(function (array $row) use ($filters): bool {
                foreach (self::DOCUMENTS as $document) {
                    if ($filters[$document] !== null && $row[$document]['status'] !== $filters[$document]) {
                        return false;
                    }
                }

                return $filters['overall'] === null || $row['overall_status'] === $filters['overall'];
            })
            ->values();
    }

    /* ----------------------------------------------------------- detail */

    /** @return array<string, mixed> */
    private function aipDetail(int $schoolId, ?int $year): array
    {
        $plan = AipPlan::query()
            ->where('school_id', $schoolId)
            ->when($year !== null, fn ($query) => $query->where('year', $year))
            ->with(['reviews.reviewer:id,name,username'])
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();

        return [
            'plan' => $plan,
            'history' => $plan === null
                ? collect()
                : $plan->reviews->map(fn ($review): array => [
                    'when' => $review->reviewed_at,
                    'action' => $review->actionLabel(),
                    'actor' => $review->reviewer?->name ?? $review->reviewer?->username ?? '—',
                    'remarks' => $review->remarks,
                    'transition' => $review->transitionLabel(),
                ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function opcrfDetail(int $schoolId, ?int $year): array
    {
        $submissions = OpcrfSubmission::query()
            ->whereNull('archived_at')
            ->whereHas('user', fn ($query) => $this->scopeSchoolHead($query, [$schoolId]))
            ->with([
                'user:id,name,username,school_id',
                'reviewer:id,name,username',
                'reviews.reviewer:id,name,username',
            ])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        if ($year !== null) {
            $submissions = $submissions->filter(
                fn (OpcrfSubmission $submission): bool => self::opcrfYear($submission) === $year
            );
        } else {
            $latestYear = $submissions
                ->map(fn (OpcrfSubmission $submission): int => self::opcrfYear($submission))
                ->max();

            $submissions = $submissions->filter(
                fn (OpcrfSubmission $submission): bool => self::opcrfYear($submission) === $latestYear
            );
        }

        return [
            'submissions' => $submissions
                ->values()
                ->map(fn (OpcrfSubmission $submission): array => [
                    'reference' => $submission->reference,
                    'part' => $submission->partLabel(),
                    'status' => OpcrfSubmission::statusLabel((string) $submission->status),
                    'status_raw' => (string) $submission->status,
                    'submitted_at' => $submission->submitted_at,
                    'submitter' => $submission->user?->name ?? '—',
                    'reviewer' => $submission->reviewer?->name ?? '—',
                    'history' => $submission->reviews->map(fn ($review): array => [
                        'when' => $review->reviewed_at,
                        'action' => $review->actionLabel(),
                        'actor' => $review->reviewer?->name ?? $review->reviewer?->username ?? '—',
                        'remarks' => $review->remarks,
                        'transition' => $review->transitionLabel(),
                    ])->values(),
                ]),
        ];
    }

    /** @return array<string, mixed> */
    private function wfpDetail(int $schoolId, ?int $year): array
    {
        $plan = WfpPlan::query()
            ->where('school_id', $schoolId)
            ->when($year !== null, fn ($query) => $query->where('year', $year))
            ->with(['submittedBy:id,name', 'approvedBy:id,name', 'approvals.decidedBy:id,name'])
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();

        $uploads = WfpSubmission::query()
            ->whereHas('user', fn ($query) => $this->scopeSchoolHead($query, [$schoolId]))
            ->with('user:id,name,school_id')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get();

        if ($year !== null) {
            $uploads = $uploads->filter(
                fn (WfpSubmission $upload): bool => self::wfpUploadYear($upload) === $year
            );
        } else {
            $latestYear = $uploads
                ->map(fn (WfpSubmission $upload): int => self::wfpUploadYear($upload))
                ->max();

            $uploads = $uploads->filter(
                fn (WfpSubmission $upload): bool => self::wfpUploadYear($upload) === $latestYear
            );
        }

        return [
            'plan' => $plan,
            'approvals' => $plan === null
                ? collect()
                : $plan->approvals->map(fn ($approval): array => [
                    'when' => $approval->decided_at,
                    'decision' => $approval->decisionLabel(),
                    'actor' => $approval->decidedBy?->name ?? '—',
                    'remarks' => $approval->remarks,
                ])->values(),
            'uploads' => $uploads->values(),
        ];
    }

    /* ------------------------------------------------------------ helpers */

    /** @return array<int, int> */
    private function schoolIds(): array
    {
        if ($this->schoolIdsCache !== null) {
            return $this->schoolIdsCache;
        }

        $districtIds = $this->districtIds();

        if ($districtIds === []) {
            return $this->schoolIdsCache = [];
        }

        return $this->schoolIdsCache = School::query()
            ->whereIn('district_id', $districtIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Narrow a user query to School Head accounts of the given schools. Only a
     * School Head authors an OPCRF or a WFP workbook, so an administrative
     * account's own uploads are not a school's submission.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     * @param  array<int, int>  $schoolIds
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function scopeSchoolHead($query, array $schoolIds)
    {
        return $query
            ->whereIn('school_id', $schoolIds)
            ->where('role', 'user')
            ->where('is_superadmin', false);
    }

    /**
     * The reporting year of an OPCRF submission: the last four-digit year in
     * its free-text review period ("January to December 2026" → 2026), with
     * the submission date as the fallback for rows that carry no year.
     */
    public static function opcrfYear(OpcrfSubmission $submission): int
    {
        return self::yearFromText($submission->review_period)
            ?? (int) ($submission->submitted_at?->year ?? 0);
    }

    /** The planning year carried by a WFP workbook's detected "CY 2026". */
    public static function wfpUploadYear(WfpSubmission $upload): int
    {
        return self::yearFromText($upload->school_year)
            ?? (int) (($upload->submitted_at ?? $upload->uploaded_at)?->year ?? 0);
    }

    /** The last four-digit year in a free-text period, or null. */
    public static function yearFromText(?string $text): ?int
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        if (preg_match_all('/\b(19|20)\d{2}\b/', $text, $matches) < 1) {
            return null;
        }

        $years = array_map(intval(...), $matches[0]);

        return $years === [] ? null : (int) end($years);
    }

    /**
     * Normalise the component's/export query's filter values. Unknown status
     * values and malformed ids become "no filter"; the database scope still
     * decides what can actually be read.
     *
     * @param  array<string, mixed>  $filters
     * @return array{district_id: ?int, school_id: ?int, year: ?int, aip: ?string, opcrf: ?string, wfp: ?string, overall: ?string, search: string}
     */
    public static function normalizeFilters(array $filters): array
    {
        return [
            'district_id' => self::positiveInt($filters['district'] ?? null),
            'school_id' => self::positiveInt($filters['school'] ?? null),
            'year' => self::reportingYear($filters['year'] ?? null),
            'aip' => self::statusOrNull($filters['aip'] ?? null, self::DOC_STATUSES),
            'opcrf' => self::statusOrNull($filters['opcrf'] ?? null, self::DOC_STATUSES),
            'wfp' => self::statusOrNull($filters['wfp'] ?? null, self::DOC_STATUSES),
            'overall' => self::statusOrNull($filters['overall'] ?? null, self::OVERALL_STATUSES),
            'search' => mb_substr(trim((string) ($filters['search'] ?? '')), 0, 120),
        ];
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private static function reportingYear(mixed $value): ?int
    {
        $year = self::positiveInt($value);

        return $year !== null && $year >= 2000 && $year <= 2100 ? $year : null;
    }

    /** @param  array<int, string>  $allowed */
    private static function statusOrNull(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /* ------------------------------------------------------- presentation */

    /** @return array<string, string> */
    public static function docStatusOptions(): array
    {
        return [
            self::DOC_SUBMITTED => 'Submitted',
            self::DOC_NEEDS_REVISION => 'Needs Revision',
            self::DOC_IN_PROGRESS => 'In Progress',
            self::DOC_NOT_SUBMITTED => 'Not Submitted',
        ];
    }

    /** @return array<string, string> */
    public static function overallStatusOptions(): array
    {
        return [
            self::OVERALL_COMPLETE => 'Complete',
            self::OVERALL_PARTIALLY_SUBMITTED => 'Partially Submitted',
            self::OVERALL_NEEDS_REVISION => 'Needs Revision',
            self::OVERALL_IN_PROGRESS => 'In Progress',
            self::OVERALL_NOT_STARTED => 'Not Started',
        ];
    }

    public static function docStatusLabel(string $status): string
    {
        return self::docStatusOptions()[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function overallStatusLabel(string $status): string
    {
        return self::overallStatusOptions()[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function docBadgeClass(string $status): string
    {
        return match ($status) {
            self::DOC_SUBMITTED => 'badge--ok',
            self::DOC_NEEDS_REVISION => 'badge--warn',
            self::DOC_IN_PROGRESS => 'badge--info',
            default => 'badge-muted',
        };
    }

    public static function overallBadgeClass(string $status): string
    {
        return match ($status) {
            self::OVERALL_COMPLETE => 'badge--ok',
            self::OVERALL_NEEDS_REVISION => 'badge--warn',
            self::OVERALL_PARTIALLY_SUBMITTED, self::OVERALL_IN_PROGRESS => 'badge--info',
            default => 'badge-muted',
        };
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function overallStatus(array $statuses): string
    {
        if (! in_array(self::DOC_NOT_SUBMITTED, $statuses, true)
            && ! in_array(self::DOC_IN_PROGRESS, $statuses, true)
            && ! in_array(self::DOC_NEEDS_REVISION, $statuses, true)) {
            return self::OVERALL_COMPLETE;
        }

        if (in_array(self::DOC_NEEDS_REVISION, $statuses, true)) {
            return self::OVERALL_NEEDS_REVISION;
        }

        if (in_array(self::DOC_IN_PROGRESS, $statuses, true)) {
            return self::OVERALL_IN_PROGRESS;
        }

        if (in_array(self::DOC_SUBMITTED, $statuses, true)) {
            return self::OVERALL_PARTIALLY_SUBMITTED;
        }

        return self::OVERALL_NOT_STARTED;
    }
}
