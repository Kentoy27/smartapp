<?php

namespace App\Support;

use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A whole planning year's Monthly Expenditure Matrix as one .xlsx workbook:
 * twelve worksheets, JAN through DEC, each the month's printed DepEd form as
 * it stands NOW.
 *
 * The sheet layout belongs to {@see MemXlsxExporter::sheet()} — the same form
 * the single-matrix export prints — so the year and the month can never drift
 * apart. This class adds the year packaging, the tab selection, and the
 * optional e-signature stamping: the School Head's, Bookkeeper's and Budget
 * Officer's registered profile signatures are placed in the form's own blank
 * signature bands, on every month. With `$includeSignatures = false` the same
 * workbook is produced without those images; the printed names stay, because
 * the names are part of the form.
 *
 * The `zip` PHP extension is disabled on XAMPP's web-server PHP, so the
 * package is assembled through PurePhpZipWriter exactly as the other
 * exporters are. Signatures are stamped in one pass by
 * {@see OpcrfSignatureStamper::stampManyAtRanges()}.
 */
final class MemYearXlsxExporter
{
    /** The XLSX content type, shared by the route and the Livewire download. */
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Build the workbook's bytes for one school, year and financial division. */
    public static function bytes(
        int $schoolId,
        int $year,
        string $division,
        ?User $preparedBy = null,
        bool $includeSignatures = true,
    ): string {
        $school = School::query()->with('district')->find($schoolId);

        // One query for every month's lines, keyed by month. The items
        // relation carries the matrix's own position ordering; the form then
        // groups by section so each sheet reads operations first, exactly as
        // the printed form does.
        $matrices = MonthlyExpenditureMatrix::query()
            ->where('school_id', $schoolId)
            ->where('school_division', $division)
            ->where('year', $year)
            ->with('items')
            ->get()
            ->keyBy(fn (MonthlyExpenditureMatrix $matrix): int => (int) $matrix->month);

        $profiles = self::signatureProfiles($schoolId, $year, $division, $preparedBy);
        $activeMonth = self::activeMonthFor($year);

        $sheets = [];
        $stamps = [];

        for ($month = 1; $month <= 12; $month++) {
            $matrix = $matrices->get($month);

            /** @var Collection<int, MonthlyExpenditureItem> $items */
            $items = $matrix instanceof MonthlyExpenditureMatrix
                ? $matrix->items
                    ->sortBy([
                        ['position', 'asc'],
                        ['id', 'asc'],
                    ])
                    ->values()
                : collect();

            $selected = ($activeMonth ?? 1) === $month;

            $sheet = MemXlsxExporter::sheet(
                $school,
                $year,
                $month,
                $items,
                $profiles['names'],
                $items->isEmpty(),
                $selected,
                $activeMonth !== null && $selected,
            );

            $sheets[] = [
                'name' => mb_strtoupper(MonthlyExpenditureMatrix::MONTH_ABBREVIATIONS[$month] ?? (string) $month),
                'xml' => $sheet['xml'],
            ];

            if (! $includeSignatures) {
                continue;
            }

            foreach (['prepared', 'checked', 'certified', 'approved'] as $slot) {
                $signer = $profiles['users'][$slot] ?? null;

                if (! $signer instanceof User) {
                    continue;
                }

                $signature = $signer->signatureImage();

                if ($signature === null) {
                    continue;
                }

                $stamps[] = [
                    'sheet' => 'xl/worksheets/sheet'.$month.'.xml',
                    'range' => $sheet['signature_ranges'][$slot],
                    'bytes' => $signature['bytes'],
                    'extension' => $signature['extension'],
                    'name' => trim((string) ($signer->name ?: $signer->username)),
                ];
            }
        }

        $bytes = MemXlsxExporter::package($sheets, ($activeMonth ?? 1) - 1);

        return $stamps === [] ? $bytes : OpcrfSignatureStamper::stampManyAtRanges($bytes, $stamps);
    }

    /** The download's file name: one workbook per school year and mode. */
    public static function downloadName(int $year, bool $includeSignatures = true): string
    {
        return sprintf(
            'Monthly_Expenditure_Matrix_CY_%d_%s.xlsx',
            $year,
            $includeSignatures ? 'Signed' : 'Unsigned',
        );
    }

    /**
     * The month whose tab should open selected: the current calendar month
     * when exporting the current year, and none otherwise (a planning year
     * that has not started has no "current" month).
     */
    public static function activeMonthFor(int $year): ?int
    {
        return $year === (int) now()->year
            ? max(1, min(12, (int) now()->month))
            : null;
    }

    /* ------------------------------------------------------------ signers */

    /**
     * The names printed on the form and the accounts whose registered
     * profile signatures may be stamped. Names come from the same workflow
     * records as the single-matrix export; a signature is only stamped when
     * the account still holds the role and school/division access that made
     * the record valid.
     *
     * The SDS approves on paper — there is no approval record for a MEM — so
     * that block stays blank in both modes.
     *
     * @return array{
     *     names: array<string, array{name: string, position: string}>,
     *     users: array<string, User|null>
     * }
     */
    private static function signatureProfiles(
        int $schoolId,
        int $year,
        string $division,
        ?User $preparedBy,
    ): array {
        $checked = WfpAllotment::checking($schoolId, $year, $division);
        $certified = WfpAllotment::certification($schoolId, $year, $division);

        return [
            'names' => [
                'prepared' => [
                    'name' => trim((string) ($preparedBy?->name ?: $preparedBy?->username)),
                    'position' => $preparedBy?->position ?: 'School Principal I',
                ],
                'checked' => [
                    'name' => $checked['name'] ?? '',
                    'position' => $checked['position'] ?? 'Senior Bookkeeper',
                ],
                'certified' => [
                    'name' => $certified['name'] ?? '',
                    'position' => $certified['position'] ?? 'Budget Officer III',
                ],
                'approved' => [
                    'name' => '',
                    'position' => 'Schools Division Superintendent',
                ],
            ],
            'users' => [
                'prepared' => $preparedBy,
                'checked' => WfpAllotment::checkingUser($schoolId, $year, $division),
                'certified' => WfpAllotment::certifyingUser($schoolId, $year, $division),
                'approved' => null,
            ],
        ];
    }
}
