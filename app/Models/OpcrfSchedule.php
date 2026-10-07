<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One OPCRF Part's access window, for one OPCRF year.
 *
 * The row holds only what the superadmin typed. Whether that window is open
 * RIGHT NOW is a question about the current time, answered by statusAt()
 * against the application's clock — never stored, never trusted from the
 * browser, and never cached, so moving a deadline takes effect the moment it
 * is saved.
 *
 * Four states, in the order they are decided:
 *
 *   disabled  — the superadmin switched it off (manual override; the dates
 *               may well say "open", and this still wins)
 *   scheduled — configured and enabled, but the start is still ahead of us
 *   open      — the current moment is inside [start, end]
 *   closed    — the end has passed
 *
 * Both edges are inclusive: a Part is open at exactly its start minute and
 * still open at exactly its end minute, and closes the minute after.
 */
class OpcrfSchedule extends Model
{
    /**
     * Access states. The labels and the icons the pages use live here so the
     * superadmin table, the staff card and any badge cannot disagree about
     * what a schedule means.
     */
    public const STATUS_OPEN = 'open';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_DISABLED = 'disabled';

    /** The Parts of the OPCRF form this system schedules. */
    public const PART_ONE = 1;

    public const PART_TWO = 2;

    public const PART_THREE = 3;

    public const PART_FOUR = 4;

    protected $fillable = [
        'opcrf_year',
        'part_number',
        'start_datetime',
        'end_datetime',
        'description',
        'is_enabled',
        'created_by',
    ];

    protected $casts = [
        'opcrf_year' => 'integer',
        'part_number' => 'integer',
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'is_enabled' => 'boolean',
    ];

    /* ------------------------------------------------------------------
     * Access — the one question everything else answers
     * ------------------------------------------------------------------ */

    /**
     * Is this Part open at the given moment?
     */
    public function isOpenAt(?Carbon $at = null): bool
    {
        return $this->statusAt($at) === self::STATUS_OPEN;
    }

    /**
     * This Part's access state at the given moment, in the application's
     * timezone. `now` defaults to the app clock so callers never pass a
     * browser-supplied timestamp.
     */
    public function statusAt(?Carbon $at = null): string
    {
        $at ??= now();

        // The manual override outranks the calendar: a superadmin can close a
        // Part that is inside its window, and reopen one that is not.
        if (! $this->is_enabled) {
            return self::STATUS_DISABLED;
        }

        if ($at->lt($this->start_datetime)) {
            return self::STATUS_SCHEDULED;
        }

        if ($at->gt($this->end_datetime)) {
            return self::STATUS_CLOSED;
        }

        return self::STATUS_OPEN;
    }

    /**
     * How long until this Part opens (null when it is not waiting), or how
     * long until it closes (null when it is not closing).
     *
     * Display only. The countdown on the staff page is a convenience; every
     * access decision is made by statusAt() against the server clock, so a
     * user who edits their clock gains nothing.
     *
     * @return array{opens_in: ?Carbon, closes_in: ?Carbon}
     */
    public function countdownsAt(?Carbon $at = null): array
    {
        $at ??= now();

        $status = $this->statusAt($at);

        return [
            'opens_in' => $status === self::STATUS_SCHEDULED ? $this->start_datetime : null,
            'closes_in' => $status === self::STATUS_OPEN ? $this->end_datetime : null,
        ];
    }

    /* ------------------------------------------------------------------
     * Presentation
     * ------------------------------------------------------------------ */

    /**
     * "Part 3" for every surface that shows a Part.
     */
    public function partLabel(): string
    {
        return 'Part '.$this->part_number;
    }

    /**
     * The human label for one access state ("Open", "Scheduled", …).
     */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN => 'Open',
            self::STATUS_SCHEDULED => 'Scheduled',
            self::STATUS_CLOSED => 'Closed',
            self::STATUS_DISABLED => 'Disabled',
            default => ucfirst($status),
        };
    }

    /**
     * The CSS class carrying each state's colour, so the badge is defined
     * once and the two tables cannot drift apart.
     */
    public static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN => 'badge--ok',
            self::STATUS_SCHEDULED => 'badge--info',
            self::STATUS_CLOSED => 'badge-muted',
            self::STATUS_DISABLED => 'badge--warn',
            default => 'badge-muted',
        };
    }

    /**
     * The window as it is written to the audit log: a flat array of the
     * values a superadmin changed, so a diff reads without the row.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'opcrf_year' => $this->opcrf_year,
            'part_number' => $this->part_number,
            'start_datetime' => $this->start_datetime?->toDateTimeString(),
            'end_datetime' => $this->end_datetime?->toDateTimeString(),
            'description' => $this->description,
            'is_enabled' => (bool) $this->is_enabled,
        ];
    }

    /* ------------------------------------------------------------------
     * Scopes & relations
     * ------------------------------------------------------------------ */

    /**
     * Schedules for one OPCRF year, in Part order — the shape both the
     * superadmin table and the staff page read.
     */
    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('opcrf_year', $year)->orderBy('part_number');
    }

    /**
     * The audit trail for this schedule, newest first.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(OpcrfScheduleLog::class, 'opcrf_schedule_id')->latest('id');
    }

    /**
     * The superadmin who configured it (nullable — an account may since have
     * been deleted; the log keeps the name either way).
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every OPCRF Part number this system knows about.
     *
     * @return array<int, int>
     */
    public static function parts(): array
    {
        return [self::PART_ONE, self::PART_TWO, self::PART_THREE, self::PART_FOUR];
    }
}
