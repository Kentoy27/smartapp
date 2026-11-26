<?php

namespace App\Support;

use App\Models\MonthlyExpenditureItem;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\User;
use App\Models\WfpPlan;
use Illuminate\Support\Collection;

/**
 * A whole planning year's Monthly Expenditure Matrix as one .xlsx workbook:
 * twelve worksheets, JAN through DEC, each the month's printed DepEd form as
 * it stands NOW, followed by the year's Per Line Item Expenditure report and,
 * when the school has a WFP plan for the year, the official WFP.
 *
 * The month layout belongs to {@see MemXlsxExporter::sheet()} and the Per Line
 * Item layout to {@see WfpAllotmentXlsxExporter::sheet()} — the same forms the
 * standalone downloads print — so a combined workbook and a single export can
 * never drift apart. The WFP tab is the plan's own official workbook
 * ({@see WfpPlanExporter::bytes()}), not a summary of it.
 *
 * When a plan exists its workbook is the BASE package: the template's theme,
 * shared strings, printer settings and drawings stay exactly as the WFP export
 * produced them, and the MEM and Per Line Item sheets are appended into it.
 * Without a plan the twelve MEM sheets are packaged as before and only the Per
 * Line Item sheet is appended; the WFP tab is omitted rather than creating a
 * plan during an export.
 *
 * This class adds the year packaging, the tab selection, and the optional
 * e-signature stamping: the School Head's, Bookkeeper's and Budget Officer's
 * registered profile signatures are placed in each form's own blank signature
 * bands. With `$includeSignatures = false` the same workbook is produced
 * without those images; the printed names stay, because the names are part of
 * the forms.
 *
 * The `zip` PHP extension is disabled on XAMPP's web-server PHP, so the
 * packages are assembled through PurePhpZipWriter exactly as the other
 * exporters are. {@see XlsxSheetAppender} folds the donors' style tables into
 * the single OOXML styles part, and signatures are stamped in one pass by
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

        /** @var array<int, array{name: string, xml: string}> $monthSheets */
        $monthSheets = [];

        /** @var array<int, array<string, string>> $monthRanges */
        $monthRanges = [];

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

            $monthSheets[] = [
                'name' => mb_strtoupper(MonthlyExpenditureMatrix::MONTH_ABBREVIATIONS[$month] ?? (string) $month),
                'xml' => $sheet['xml'],
            ];

            $monthRanges[$month] = $sheet['signature_ranges'];
        }

        // The Per Line Item report is always part of the year workbook. Its
        // sheet is the standalone export's own, so the tab and the separate
        // download describe the report identically.
        $lineItemTab = 'Per Line Item';
        $lineItem = WfpAllotmentXlsxExporter::sheet($schoolId, $year, $preparedBy, $division);

        // Read-only: an export must never create or regenerate a plan. The
        // WFP tab is present only when the school already has one for the
        // selected year and division.
        $plan = WfpPlan::query()
            ->where('school_id', $schoolId)
            ->where('school_division', $division)
            ->where('year', $year)
            ->first();

        if ($plan instanceof WfpPlan) {
            // The official WFP workbook is the base, so its theme, shared
            // strings, printer settings and signature drawings survive
            // untouched. The months and the line-item report are appended,
            // then the tabs are put in calendar order with the WFP last.
            $appended = XlsxSheetAppender::append(
                WfpPlanExporter::bytes($plan, $includeSignatures, $preparedBy),
                array_merge(
                    array_map(
                        static fn (array $sheet): array => $sheet + ['styles' => MemXlsxExporter::stylesXml()],
                        $monthSheets,
                    ),
                    [[
                        'name' => $lineItemTab,
                        'xml' => $lineItem['xml'],
                        'styles' => WfpAllotmentXlsxExporter::stylesXml(),
                    ]],
                ),
            );

            $tabOrder = array_merge(
                array_map(static fn (array $sheet): string => $sheet['name'], $monthSheets),
                [$lineItemTab, 'WFP'],
            );

            $activeTab = mb_strtoupper(
                MonthlyExpenditureMatrix::MONTH_ABBREVIATIONS[$activeMonth ?? 1] ?? 'JAN'
            );

            $bytes = XlsxSheetAppender::arrange($appended['bytes'], $tabOrder, $activeTab);

            $monthParts = array_slice($appended['parts'], 0, 12);
            $lineItemPart = $appended['parts'][12] ?? null;
        } else {
            // No plan for the year: the twelve months are packaged as before,
            // January (or the current month) opens selected, and the line-item
            // report is appended after them.
            $appended = XlsxSheetAppender::append(
                MemXlsxExporter::package($monthSheets, ($activeMonth ?? 1) - 1),
                [[
                    'name' => $lineItemTab,
                    'xml' => $lineItem['xml'],
                    'styles' => WfpAllotmentXlsxExporter::stylesXml(),
                ]],
            );

            $bytes = $appended['bytes'];

            $monthParts = array_map(
                static fn (int $month): string => 'xl/worksheets/sheet'.$month.'.xml',
                range(1, 12),
            );
            $lineItemPart = $appended['parts'][0] ?? null;
        }

        if (! $includeSignatures) {
            return $bytes;
        }

        $stamps = [];

        foreach ($monthParts as $index => $part) {
            $month = $index + 1;

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
                    'sheet' => $part,
                    'range' => $monthRanges[$month][$slot],
                    'bytes' => $signature['bytes'],
                    'extension' => $signature['extension'],
                    'name' => trim((string) ($signer->name ?: $signer->username)),
                ];
            }
        }

        if ($lineItemPart !== null) {
            $stamps = array_merge($stamps, WfpAllotmentXlsxExporter::signatureStamps(
                $schoolId,
                $year,
                $preparedBy,
                $division,
                $lineItemPart,
                $lineItem['signature_ranges'],
            ));
        }

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
