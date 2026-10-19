<?php

namespace App\Support;

use App\Models\OpcrfSchedule;
use Illuminate\Support\Carbon;

/**
 * The OPCRF part-availability policy for staff accounts.
 *
 * The template ships one part per sheet tab (PART I … PART IV). Staff can
 * access Part One according to its own schedule. The complete
 * workbook is offered during the shared Parts 2–4 schedule; without explicit
 * schedules, the legacy recurring term remains the compatibility fallback.
 * Superadmins bypass the gate — their downloads serve workbooks as stored.
 *
 * @see OpcrfWorkbookTrim for the sheet surgery
 * @see OpcrfAccess for the schedule the superadmin owns
 */
class OpcrfPartOne
{
    /**
     * Is Part One accessible according to its configured schedule?
     *
     * A configured Part 1 schedule wins outright. With no schedule, the
     * legacy Part 1 availability policy applies.
     */
    public static function windowOpen(): bool
    {
        $scheduled = OpcrfAccess::scheduleFor(OpcrfAccess::currentYear(), OpcrfSchedule::PART_ONE);

        if ($scheduled !== null) {
            return $scheduled->isOpenAt();
        }

        return self::legacyWindowOpen();
    }

    /**
     * The full workbook is available during the shared Parts 2–4 term.
     * The legacy configured term remains the fallback only when no explicit
     * Part 1 or Parts 2–4 schedule exists.
     */
    public static function fullTemplateAvailable(): bool
    {
        $partsTwoToFour = OpcrfAccess::scheduleFor(
            OpcrfAccess::currentYear(),
            OpcrfSchedule::PART_TWO
        );

        if ($partsTwoToFour !== null) {
            return $partsTwoToFour->isOpenAt();
        }

        if (OpcrfAccess::scheduleFor(OpcrfAccess::currentYear(), OpcrfSchedule::PART_ONE) !== null) {
            return false;
        }

        return self::legacyWindowOpen();
    }

    /**
     * The pre-schedule, config-driven window. Kept as the fallback for an
     * installation that has not created any opcrf_schedules rows.
     */
    public static function legacyWindowOpen(): bool
    {
        return OpcrfAccess::legacyWindowOpen();
    }

    /**
     * The legacy window's boundaries (null when the config dates are not
     * valid MM-DD pairs). Delegates to the access service so both paths read
     * one implementation.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function windowBoundaries(): array
    {
        return OpcrfAccess::legacyWindowBoundaries();
    }

    /**
     * What the dashboard's OPCRF card should tell the staff member.
     *
     * @return array{part: int, full_open: bool, full_open_date: ?string, label: string}
     */
    public static function summary(): array
    {
        [$opens] = self::windowBoundaries();

        $scheduled = OpcrfAccess::scheduleFor(OpcrfAccess::currentYear(), OpcrfSchedule::PART_ONE);
        $partsTwoToFour = OpcrfAccess::scheduleFor(
            OpcrfAccess::currentYear(),
            OpcrfSchedule::PART_TWO
        );
        $fullTemplateOpens = $partsTwoToFour?->start_datetime;
        $fullTemplateOpenDate = $fullTemplateOpens?->isFuture()
            ? ($fullTemplateOpens->isCurrentYear()
                ? $fullTemplateOpens->translatedFormat('F j')
                : $fullTemplateOpens->translatedFormat('F j, Y'))
            : ($partsTwoToFour === null && $scheduled === null && $opens !== null
                ? ($opens->isCurrentYear()
                    ? $opens->translatedFormat('F j')
                    : $opens->translatedFormat('F j, Y'))
                : null);

        return [
            // How many parts the staff member's download carries right now.
            'part' => self::fullTemplateAvailable() ? 4 : 1,
            'full_open' => self::fullTemplateAvailable(),
            'full_open_date' => $fullTemplateOpenDate,
            'label' => (string) config('opcrf.part_one.term_label', 'the end of the school year term'),

            // What the schedule says, when there is one: the card quotes the
            // configured window instead of the legacy term label.
            'scheduled' => $scheduled !== null,
            'schedule_start' => $scheduled?->start_datetime,
            'schedule_end' => $scheduled?->end_datetime,
        ];
    }

    /**
     * The workbook bytes a staff download should serve. Explicit access
     * during the shared Parts 2–4 term includes every sheet. A Part 1-only
     * download keeps the other tabs out of the workbook.
     */
    public static function templateBytes(?bool $includeAllParts = null): string
    {
        if ($includeAllParts ?? self::fullTemplateAvailable()) {
            return (string) file_get_contents(self::templatePath());
        }

        return OpcrfWorkbookTrim::keepPart(
            self::templatePath(),
            (string) config('opcrf.part_one.sheet', 'PART I')
        );
    }

    /**
     * The shipped template's path, wherever the app runs from.
     */
    public static function templatePath(): string
    {
        return storage_path('forms/OPCRF-TEMPLATE.xlsx');
    }

    /**
     * The download name for the staff template: it is only the bare template
     * file while the whole form is available; the Part-I-only copy carries a
     * name that says so.
     */
    public static function templateDownloadName(): string
    {
        return self::fullTemplateAvailable()
            ? 'OPCRF-TEMPLATE.xlsx'
            : 'OPCRF-PART-I.xlsx';
    }
}
