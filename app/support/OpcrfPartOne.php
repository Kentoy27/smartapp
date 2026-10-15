<?php

namespace App\Support;

use App\Models\OpcrfSchedule;
use Illuminate\Support\Carbon;

/**
 * The OPCRF part-availability policy for staff accounts.
 *
 * The template ships one part per sheet tab (PART I … PART IV). Staff work
 * with Part One from the beginning: their download is the template rebuilt
 * with the PART I tab as its only worksheet (config
 * opcrf.part_one.part_one_only, on by default). With that lock released,
 * the school year's final-term window decides: outside it the download is
 * Part-I-only; inside it the complete workbook opens. Superadmins bypass
 * the gate — their downloads serve workbooks as stored.
 *
 * A superadmin-configured SCHEDULE now outranks all of that: once an
 * opcrf_schedules row exists for Part 1 (OpcrfAccess), it alone decides
 * whether the full workbook opens, and the hard-coded window below is only
 * the fallback for an installation that has not created any schedules yet.
 *
 * @see OpcrfWorkbookTrim for the sheet surgery
 * @see OpcrfAccess for the schedule the superadmin owns
 */
class OpcrfPartOne
{
    /**
     * Is the Part 1-controlled full template window open?
     *
     * A configured Part 1 schedule wins outright — that is the control the
     * superadmin actually operates. With no schedule at all, the legacy
     * behaviour applies: "Part One only, from the beginning" (the default)
     * keeps it permanently closed; with the lock released it is the recurring
     * final-term window.
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
     * The full workbook is available during either Part 1 or the shared
     * Parts 2–4 term. The Part 1 submission itself remains a separate record.
     */
    public static function fullTemplateAvailable(): bool
    {
        $partsTwoToFour = OpcrfAccess::scheduleFor(
            OpcrfAccess::currentYear(),
            OpcrfSchedule::PART_TWO
        );

        return self::windowOpen() || ($partsTwoToFour?->isOpenAt() ?? false);
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
        [$opens, $closes] = self::windowBoundaries();

        $scheduled = OpcrfAccess::scheduleFor(OpcrfAccess::currentYear(), OpcrfSchedule::PART_ONE);

        return [
            // How many parts the staff member's download carries right now.
            'part' => self::fullTemplateAvailable() ? 4 : 1,
            'full_open' => self::fullTemplateAvailable(),
            'full_open_date' => $opens !== null
                ? ($opens->isCurrentYear()
                    ? $opens->translatedFormat('F j')
                    : $opens->translatedFormat('F j, Y'))
                : null,
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
     * during the shared Parts 2–4 term includes every sheet; otherwise the
     * legacy Part 1 window determines whether to trim the workbook.
     */
    public static function templateBytes(bool $includeAllParts = false): string
    {
        if ($includeAllParts || self::windowOpen()) {
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
