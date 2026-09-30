<?php

namespace App\Support;

/**
 * The OPCRF part-availability policy for staff accounts.
 *
 * The template ships one part per sheet tab (PART I … PART IV). Outside the
 * school year's final term window a staff member may only work with Part One,
 * so their download is the template rebuilt with the PART I tab as its only
 * worksheet; inside the window they get the complete workbook. Superadmins
 * bypass the gate — the Review Opcrf download serves the archived workbook
 * as stored.
 *
 * @see \App\Support\OpcrfWorkbookTrim for the sheet surgery
 */
class OpcrfPartOne
{
    /**
     * Is the full template open — the recurring final-term window?
     *
     * The window is a recurring month-day pair (e.g. 03-16 → 04-30); when
     * "closes" falls before "opens" it runs across the new year. The window
     * is inclusive on both ends.
     */
    public static function windowOpen(): bool
    {
        [$opens, $closes] = self::windowBoundaries();

        if ($opens === null || $closes === null) {
            return false;
        }

        // The closing boundary is a calendar day, so it lasts through its
        // final moment — the whole closing day sits inside the window.
        $closes = $closes->copy()->endOfDay();

        if ($opens->lessThanOrEqualTo($closes)) {
            // A window inside one calendar year.
            return now()->between($opens, $closes);
        }

        // A window across the new year (e.g. 11-01 → 03-15): it is open when
        // today is after the opening date OR before the closing one.
        return now()->greaterThanOrEqualTo($opens) || now()->lessThanOrEqualTo($closes);
    }

    /**
     * This year's window boundaries, or null when the configured dates are
     * not valid MM-DD pairs (the gate then stays closed rather than
     * misbehaving on a typo).
     *
     * @return array{0: ?\Illuminate\Support\Carbon, 1: ?\Illuminate\Support\Carbon}
     */
    private static function windowBoundaries(): array
    {
        $opens = self::boundary((string) config('opcrf.part_one.term_opens'));
        $closes = self::boundary((string) config('opcrf.part_one.term_closes'));

        if ($opens === null || $closes === null) {
            return [null, null];
        }

        // A closing date before the opening one means the window spans the
        // new year — the opening boundary then belongs to this year only
        // when we are not already past the closing date of the current span.
        if ($closes->lessThan($opens) && now()->greaterThan($closes)) {
            $closes = $closes->addYear();
        } elseif ($closes->lessThan($opens)) {
            $opens = $opens->subYear();
        }

        return [$opens, $closes];
    }

    /**
     * Parse an "MM-DD" config value against the current year, or null.
     */
    private static function boundary(string $value): ?object
    {
        if (preg_match('/^(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        try {
            $date = now()->setDate(
                (int) now()->format('Y'),
                (int) $m[1],
                (int) $m[2]
            );
        } catch (\Throwable) {
            return null; // An impossible date (02-30) keeps the gate closed.
        }

        return $date->startOfDay();
    }

    /**
     * What the dashboard's OPCRF card should tell the staff member.
     *
     * @return array{part: int, full_open: bool, full_open_date: ?string, label: string}
     */
    public static function summary(): array
    {
        [$opens, $closes] = self::windowBoundaries();

        return [
            // How many parts the staff member's download carries right now.
            'part' => self::windowOpen() ? 4 : 1,
            'full_open' => self::windowOpen(),
            'full_open_date' => $opens !== null
                ? ($opens->isCurrentYear()
                    ? $opens->translatedFormat('F j')
                    : $opens->translatedFormat('F j, Y'))
                : null,
            'label' => (string) config('opcrf.part_one.term_label', 'the end of the school year term'),
        ];
    }

    /**
     * The workbook bytes a staff download should serve: the full template
     * inside the final-term window, otherwise the template rebuilt with only
     * the PART I sheet.
     */
    public static function templateBytes(): string
    {
        if (self::windowOpen()) {
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
        return self::windowOpen()
            ? 'OPCRF-TEMPLATE.xlsx'
            : 'OPCRF-PART-I.xlsx';
    }
}
