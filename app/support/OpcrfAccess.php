<?php

namespace App\Support;

use App\Models\OpcrfSchedule;
use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Who may open which OPCRF Part, right now.
 *
 * Every access decision in the app funnels through here — the parts grid on
 * the staff page, the guarded Part routes, and the personalized template
 * download. One service means the badge a user sees and the check that
 * actually blocks them can never disagree.
 *
 * Three rules, in the order they are applied to a Part:
 *
 *   1. SCHEDULE — is there an enabled window for this (year, Part) that
 *      contains the current moment? No window, or a window that has not
 *      opened yet / has closed / was switched off, and the Part is locked.
 *      Each of those says something different to the user, because each needs
 *      a different action from them.
 *
 *   2. COMPLETION — has this user already submitted this Part? A completed
 *      Part reads as "Completed" and stays readable: a closed deadline must
 *      never invalidate work the user already handed in.
 *
 *   3. Nothing else. There is deliberately NO rule making Part 2 depend on
 *      Part 1 — this application's workflow does not require it, and the
 *      requirement is explicit that availability and completion are separate
 *      concepts and that sequencing must not be imposed where the app does
 *      not already ask for it.
 *
 * All comparisons are made against the application's configured timezone
 * (config('app.timezone')) via now(), never a timestamp supplied by the
 * browser.
 */
class OpcrfAccess
{
    /** The status given to a Part nobody has scheduled at all. */
    public const STATUS_NOT_SCHEDULED = 'not_scheduled';

    /**
     * The OPCRF year the staff dashboard is showing.
     *
     * Not hardcoded: the most recent year that actually has schedules wins,
     * so a superadmin who rolls the calendar forward to 2027 moves every
     * user onto it without a deploy. Before any schedule exists it falls back
     * to the configured default (config('opcrf.year')), itself defaulting to
     * the current calendar year.
     */
    public static function currentYear(): int
    {
        $latest = OpcrfSchedule::query()
            ->distinct()
            ->orderByDesc('opcrf_year')
            ->value('opcrf_year');

        return (int) ($latest ?: config('opcrf.year', (int) now()->format('Y')));
    }

    /**
     * Every OPCRF year that has a schedule, newest first — the superadmin's
     * year switcher.
     *
     * @return array<int, int>
     */
    public static function years(): array
    {
        $years = OpcrfSchedule::query()
            ->distinct()
            ->orderByDesc('opcrf_year')
            ->pluck('opcrf_year')
            ->map(fn ($year): int => (int) $year)
            ->all();

        $current = (int) config('opcrf.year', (int) now()->format('Y'));

        // The default year is always offered, even with nothing scheduled yet,
        // so the superadmin can create the first schedule for it.
        if (! in_array($current, $years, true)) {
            array_unshift($years, $current);
        }

        return $years;
    }

    /**
     * One Part's schedule for one year, or null when the Part was never
     * configured.
     */
    public static function scheduleFor(int $year, int $part): ?OpcrfSchedule
    {
        return OpcrfSchedule::query()
            ->where('opcrf_year', $year)
            ->where('part_number', $part)
            ->first();
    }

    /**
     * A Part's access state for a given user, as one array carrying
     * everything a page needs to explain itself: the status, the reason in
     * words, the window, and the countdowns.
     *
     * @return array{
     *     part: int, label: string, year: int, status: string, open: bool,
     *     completed: bool, can_access: bool, message: string, detail: string,
     *     schedule: ?OpcrfSchedule, starts_at: ?Carbon, ends_at: ?Carbon,
     *     opens_in: ?Carbon, closes_in: ?Carbon
     * }
     */
    public static function partState(User $user, int $part, ?int $year = null, ?Carbon $at = null): array
    {
        $year ??= self::currentYear();
        $at ??= now();

        $schedule = self::scheduleFor($year, $part);
        $completed = self::isCompleted($user, $part);

        $status = $schedule !== null
            ? $schedule->statusAt($at)
            : self::statusWithoutSchedule($part);

        $countdowns = $schedule?->countdownsAt($at) ?? ['opens_in' => null, 'closes_in' => null];

        // A completed Part stays readable forever: the deadline closing stops
        // new work, it does not retract work already submitted.
        $open = $status === OpcrfSchedule::STATUS_OPEN;
        $canAccess = $open || $completed;

        return [
            'part' => $part,
            'label' => 'Part '.$part,
            'year' => $year,
            'status' => $status,
            'open' => $open,
            'completed' => $completed,
            'can_access' => $canAccess,
            // The card's own visual tone, and its badge wording, resolved
            // here rather than in the view: one place decides what "closed"
            // looks like, so the grid and the tables cannot drift.
            'tone' => $completed ? 'completed' : self::toneFor($status),
            'badge' => $completed ? 'Completed' : self::badgeFor($status),
            'message' => self::messageFor($status, $part, $schedule),
            'detail' => self::detailFor($status, $schedule),
            'countdown' => $completed ? '' : self::countdownLabel($countdowns, $at),
            'schedule' => $schedule,
            'starts_at' => $schedule?->start_datetime,
            'ends_at' => $schedule?->end_datetime,
            'opens_in' => $countdowns['opens_in'],
            'closes_in' => $countdowns['closes_in'],
        ];
    }

    /**
     * The card's tone for an access state.
     */
    public static function toneFor(string $status): string
    {
        return match ($status) {
            OpcrfSchedule::STATUS_OPEN => 'open',
            OpcrfSchedule::STATUS_SCHEDULED => 'scheduled',
            OpcrfSchedule::STATUS_CLOSED => 'closed',
            default => 'disabled',
        };
    }

    /**
     * The badge wording for an access state, as the staff member reads it:
     * a Part that has not opened yet is "Locked", not "Scheduled" — the
     * word they need is about them, not about the calendar.
     */
    public static function badgeFor(string $status): string
    {
        return match ($status) {
            OpcrfSchedule::STATUS_OPEN => 'Open',
            OpcrfSchedule::STATUS_SCHEDULED => 'Locked',
            OpcrfSchedule::STATUS_CLOSED => 'Closed',
            OpcrfSchedule::STATUS_DISABLED => 'Unavailable',
            default => 'Unavailable',
        };
    }

    /**
     * "Closes in 5 days 3 hours" / "Opens in 2 days 4 hours", or '' when
     * there is nothing to count down to.
     *
     * Display only — see countdownsAt(): the decision to let a Part open is
     * made by statusAt() against the server clock, never by this string.
     *
     * @param  array{opens_in: ?Carbon, closes_in: ?Carbon}  $countdowns
     */
    private static function countdownLabel(array $countdowns, Carbon $at): string
    {
        $target = $countdowns['closes_in'] ?? $countdowns['opens_in'];

        if ($target === null) {
            return '';
        }

        $prefix = $countdowns['closes_in'] !== null ? 'Closes in' : 'Opens in';
        $diff = $at->diff($target, false);

        $parts = [];

        if ($diff->d > 0) {
            $parts[] = $diff->d.' '.($diff->d === 1 ? 'day' : 'days');
        }

        if ($diff->h > 0 || $parts === []) {
            $parts[] = $diff->h.' '.($diff->h === 1 ? 'hour' : 'hours');
        }

        if ($diff->i > 0 || $parts === []) {
            $parts[] = $diff->i.' '.($diff->i === 1 ? 'minute' : 'minutes');
        }

        return $prefix.' '.implode(' ', $parts);
    }

    /**
     * Every Part's state for a user, in Part order — what the staff page
     * renders one card per Part from.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allParts(User $user, ?int $year = null, ?Carbon $at = null): array
    {
        $year ??= self::currentYear();
        $at ??= now();

        return array_map(
            fn (int $part): array => self::partState($user, $part, $year, $at),
            OpcrfSchedule::parts()
        );
    }

    /**
     * May this user open this Part? The check every guarded route runs.
     *
     * Only a scheduled-and-currently-open Part passes, or a Part the user
     * has already submitted (so a closed window never locks them out of
     * their own finished work).
     */
    public static function canAccess(User $user, int $part, ?int $year = null, ?Carbon $at = null): bool
    {
        return self::partState($user, $part, $year, $at)['can_access'];
    }

    /**
     * The one-line reason a Part is in the state it is in, said the way the
     * staff member needs to read it.
     *
     * Each locked reason is different because each needs a different action:
     * "wait until the 1st" and "ask the superadmin" and "it's over" are not
     * the same message.
     */
    public static function messageFor(string $status, int $part, ?OpcrfSchedule $schedule): string
    {
        $label = 'Part '.$part;

        return match ($status) {
            OpcrfSchedule::STATUS_OPEN => $label.' is now open.',
            OpcrfSchedule::STATUS_SCHEDULED => $schedule !== null
                ? $label.' is not yet available. Available starting '
                    .self::when($schedule->start_datetime).'.'
                : $label.' is not yet available.',
            OpcrfSchedule::STATUS_CLOSED => $label.' access period has ended.',
            OpcrfSchedule::STATUS_DISABLED => $label.' is currently unavailable.',
            default => $label.' is currently unavailable.',
        };
    }

    /**
     * The supporting line under the message: the window when there is one,
     * the superadmin's instructions when they wrote any.
     */
    /**
     * A Part nobody has scheduled yet.
     *
     * Part 1 is the exception, and it is deliberate: Part 1 has always been
     * the Part staff work on, and it stays reachable until a superadmin
     * actually schedules it. Without this an upgrade that introduced this
     * table would have locked every existing user out of the one Part they
     * could previously use — the schedules have to ADD control, not remove
     * access.
     *
     * Parts 2–4 are new surfaces with no history to preserve: unscheduled
     * means not offered, and they say so.
     */
    public static function statusWithoutSchedule(int $part): string
    {
        return $part === OpcrfSchedule::PART_ONE
            ? OpcrfSchedule::STATUS_OPEN
            : self::STATUS_NOT_SCHEDULED;
    }

    /**
     * The legacy, config-driven Part 1 window: "Part One only, from the
     * beginning" (the default), or — once that lock is released — the school
     * year's recurring final-term window (MM-DD pairs from config).
     *
     * This governs whether the FULL four-part workbook is served, which is a
     * separate question from whether Part 1 can be opened at all.
     */
    public static function legacyWindowOpen(): bool
    {
        if ((bool) config('opcrf.part_one.part_one_only', true)) {
            return false;
        }

        [$opens, $closes] = self::legacyWindowBoundaries();

        if ($opens === null || $closes === null) {
            return false;
        }

        // The closing boundary is a calendar day, so it lasts through its
        // final moment — the whole closing day sits inside the window.
        $closes = $closes->copy()->endOfDay();

        if ($opens->lessThanOrEqualTo($closes)) {
            return now()->between($opens, $closes);
        }

        // A window across the new year (e.g. 11-01 → 03-15): open when today
        // is after the opening date OR before the closing one.
        return now()->greaterThanOrEqualTo($opens) || now()->lessThanOrEqualTo($closes);
    }

    /**
     * The legacy window's boundaries for this year, or null when the
     * configured MM-DD values are not valid dates.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function legacyWindowBoundaries(): array
    {
        $opens = self::legacyBoundary((string) config('opcrf.part_one.term_opens'));
        $closes = self::legacyBoundary((string) config('opcrf.part_one.term_closes'));

        if ($opens === null || $closes === null) {
            return [null, null];
        }

        // A closing date before the opening one means the window spans the new
        // year.
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
    private static function legacyBoundary(string $value): ?Carbon
    {
        if (preg_match('/^(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        try {
            return now()
                ->setDate((int) now()->format('Y'), (int) $m[1], (int) $m[2])
                ->startOfDay();
        } catch (\Throwable) {
            return null; // An impossible date (02-30) keeps the gate closed.
        }
    }

    public static function detailFor(string $status, ?OpcrfSchedule $schedule): string
    {
        if ($schedule === null) {
            return $status === OpcrfSchedule::STATUS_OPEN
                ? 'Open — no access period has been set for this Part yet.'
                : 'No access period has been set for this Part yet.';
        }

        $lines = [];

        if ($status === OpcrfSchedule::STATUS_OPEN) {
            $lines[] = 'Access period: '.self::when($schedule->start_datetime)
                .' – '.self::when($schedule->end_datetime);
        } elseif ($status === OpcrfSchedule::STATUS_SCHEDULED) {
            $lines[] = 'Access period: '.self::when($schedule->start_datetime)
                .' – '.self::when($schedule->end_datetime);
        } elseif ($status === OpcrfSchedule::STATUS_CLOSED) {
            $lines[] = 'Access ended: '.self::when($schedule->end_datetime);
        } elseif ($status === OpcrfSchedule::STATUS_DISABLED) {
            $lines[] = 'Scheduled '.self::when($schedule->start_datetime)
                .' – '.self::when($schedule->end_datetime)
                .', but currently switched off by the administrator.';
        }

        $instructions = trim((string) $schedule->description);

        if ($instructions !== '') {
            $lines[] = $instructions;
        }

        return implode(' ', $lines);
    }

    /**
     * A moment as the pages write it — in the application's timezone, since
     * that is the zone every comparison above was made in.
     */
    public static function when(?Carbon $moment): string
    {
        return $moment === null
            ? '—'
            : $moment->timezone(config('app.timezone'))->format('F j, Y g:i A');
    }

    /**
     * Has this user already handed in this Part? A returned submission does
     * not count as done — it is back in their hands to be revised.
     */
    public static function isCompleted(User $user, int $part): bool
    {
        return OpcrfSubmission::query()
            ->where('user_id', $user->id)
            ->where('opcrf_part', $part)
            ->where('status', '!=', OpcrfSubmission::STATUS_RETURNED)
            ->exists();
    }
}
